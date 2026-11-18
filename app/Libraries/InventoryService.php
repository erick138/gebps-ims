<?php

namespace App\Libraries;

/**
 * Business rules for issuing, returning, transferring and repairing equipment.
 * Every method runs in a transaction and locks the equipment row it changes.
 */
class InventoryService
{
    public const OPEN_MAINT = ['Requested', 'Scheduled', 'In Progress'];

    // ---------------------------------------------------------------- helpers
    public static function date(mixed $v, string $field, bool $required = false): ?string
    {
        if ($v === null || $v === '') {
            if ($required) {
                throw new ApiException("$field is required.");
            }

            return null;
        }
        $d = \DateTime::createFromFormat('Y-m-d', (string) $v);
        if (! $d || $d->format('Y-m-d') !== (string) $v) {
            throw new ApiException("$field must be a date in YYYY-MM-DD format.");
        }

        return (string) $v;
    }

    private static function lockEquipment(int $id): array
    {
        return Db::one('SELECT * FROM equipment WHERE id = ? FOR UPDATE', [$id])
            ?? throw new ApiException('Equipment not found.', 404);
    }

    private static function plain(array $e): string
    {
        return $e['property_no'] . ' ' . $e['name'];
    }

    // --------------------------------------------------------------- issuance
    public static function issue(array $d, array $user): array
    {
        return Db::transaction(function () use ($d, $user) {
            $e = self::lockEquipment((int) ($d['equipment_id'] ?? 0));
            if ($e['status'] !== 'Available') {
                throw new ApiException('Only available equipment can be issued.');
            }
            $p = Db::one('SELECT * FROM personnel WHERE id = ?', [(int) ($d['personnel_id'] ?? 0)])
                ?? throw new ApiException('Personnel record not found.', 404);
            if ($p['status'] !== 'Active') {
                throw new ApiException('That person is marked inactive.');
            }
            $type   = $d['type'] ?? 'Assignment';
            if (! in_array($type, ['Assignment', 'Borrow'], true)) {
                throw new ApiException('Type must be Assignment or Borrow.');
            }
            $issued = self::date($d['issued_date'] ?? Db::today(), 'issued_date', true);
            $due    = $type === 'Borrow' ? self::date($d['due_date'] ?? null, 'due_date', true) : null;
            if ($due !== null && $due < $issued) {
                throw new ApiException('Due date cannot be earlier than the issue date.');
            }
            $iss = Db::insert('issuances', [
                'equipment_id' => $e['id'],
                'personnel_id' => $p['id'],
                'type'         => $type,
                'issued_date'  => $issued,
                'due_date'     => $due,
                'received_by'  => trim((string) ($d['received_by'] ?? '')) ?: $p['name'],
                'purpose'      => trim((string) ($d['purpose'] ?? '')),
                'remarks'      => trim((string) ($d['remarks'] ?? '')),
                'issued_by'    => $user['id'],
            ]);
            Db::run("UPDATE equipment SET status = 'Deployed', assigned_to = ? WHERE id = ?", [$p['id'], $e['id']]);
            Activity::log($user, 'issuance', self::plain($e) . ' issued to ' . $p['name'] . ' (' . $iss['ref_no'] . ').');

            return $iss;
        });
    }

    public static function returnItem(int $issuanceId, array $d, array $user): array
    {
        return Db::transaction(function () use ($issuanceId, $d, $user) {
            $iss = Db::one('SELECT * FROM issuances WHERE id = ? FOR UPDATE', [$issuanceId])
                ?? throw new ApiException('Issuance record not found.', 404);
            if ($iss['status'] !== 'Active') {
                throw new ApiException('This issuance was already returned.');
            }
            $cond = $d['condition'] ?? 'Good';
            if (! in_array($cond, ['Good', 'Fair', 'Poor'], true)) {
                throw new ApiException('Condition must be Good, Fair or Poor.');
            }
            $date = self::date($d['returned_date'] ?? Db::today(), 'returned_date', true);
            if ($date < $iss['issued_date']) {
                throw new ApiException('Return date cannot be earlier than the issue date.');
            }
            $e   = self::lockEquipment((int) $iss['equipment_id']);
            $upd = Db::update('issuances', $iss['id'], [
                'status'           => 'Returned',
                'returned_date'    => $date,
                'return_condition' => $cond,
                'remarks'          => trim($iss['remarks'] . (($d['remarks'] ?? '') !== '' ? ($iss['remarks'] !== '' ? ' | ' : '') . trim((string) $d['remarks']) : '')),
            ]);
            Db::run('UPDATE equipment SET assigned_to = NULL, `condition` = ?, status = ? WHERE id = ?', [
                $cond, $cond === 'Poor' ? 'Defective' : 'Available', $e['id'],
            ]);
            $who = Db::value('SELECT name FROM personnel WHERE id = ?', [$iss['personnel_id']]);
            Activity::log($user, 'return', self::plain($e) . ' returned by ' . $who . ' in ' . strtolower($cond) . ' condition.');

            return $upd;
        });
    }

    /** Closes the open issuance of an item (used when it is condemned). */
    public static function closeActiveIssuance(int $equipmentId, ?string $date, string $cond, string $remarks): void
    {
        Db::run(
            "UPDATE issuances SET status = 'Returned', returned_date = GREATEST(CAST(? AS DATE), issued_date), return_condition = ?,
                    remarks = LEFT(CASE WHEN remarks = '' THEN ? ELSE CONCAT(remarks, ' | ', ?) END, 1000)
             WHERE equipment_id = ? AND status = 'Active'",
            [$date ?: Db::today(), $cond, $remarks, $remarks, $equipmentId]
        );
    }

    // -------------------------------------------------------------- transfers
    public static function transfer(array $d, array $user): array
    {
        return Db::transaction(function () use ($d, $user) {
            $e = self::lockEquipment((int) ($d['equipment_id'] ?? 0));
            if ($e['status'] === 'Condemned') {
                throw new ApiException('Condemned equipment cannot be transferred.');
            }
            $to = Db::one('SELECT * FROM rooms WHERE id = ?', [(int) ($d['to_room_id'] ?? 0)])
                ?? throw new ApiException('Destination room not found.', 404);
            if ((int) $to['id'] === (int) $e['room_id']) {
                throw new ApiException('The equipment is already in that room.');
            }
            $reason = trim((string) ($d['reason'] ?? ''));
            if ($reason === '') {
                throw new ApiException('A reason is required.');
            }
            $tr = Db::insert('transfers', [
                'equipment_id'   => $e['id'],
                'from_room_id'   => $e['room_id'],
                'to_room_id'     => $to['id'],
                'transfer_date'  => self::date($d['transfer_date'] ?? Db::today(), 'transfer_date', true),
                'reason'         => $reason,
                'transferred_by' => $user['id'],
            ]);
            $from = Db::value('SELECT name FROM rooms WHERE id = ?', [$e['room_id']]);
            Db::update('equipment', (int) $e['id'], ['room_id' => (int) $to['id']]); // also re-derives the department
            Activity::log($user, 'transfer', self::plain($e) . " transferred from $from to {$to['name']}.");

            return $tr;
        });
    }

    // ------------------------------------------------------------ maintenance
    public static function requestRepair(array $d, array $user): array
    {
        return Db::transaction(function () use ($d, $user) {
            $e = self::lockEquipment((int) ($d['equipment_id'] ?? 0));
            if ($user['role'] === 'staff' && (empty($user['personnel_id']) || (int) $e['assigned_to'] !== (int) $user['personnel_id'])) {
                throw new ApiException('You can only report problems on equipment assigned to you.', 403);
            }
            $problem = trim((string) ($d['problem'] ?? ''));
            if ($problem === '') {
                throw new ApiException('Describe the problem.');
            }
            $priority = $d['priority'] ?? 'Normal';
            if (! in_array($priority, ['Normal', 'High'], true)) {
                throw new ApiException('Priority must be Normal or High.');
            }
            $m = Db::insert('maintenance', [
                'equipment_id' => $e['id'], 'type' => 'Corrective', 'problem' => $problem, 'priority' => $priority,
                'status' => 'Requested', 'reported_by' => $user['id'],
            ]);
            self::applyEffects($m, '', $user);
            Activity::log($user, 'maintenance', 'Repair requested for ' . self::plain($e) . '.');

            return $m;
        });
    }

    public static function schedulePreventive(array $d, array $user): array
    {
        return Db::transaction(function () use ($d, $user) {
            $e = self::lockEquipment((int) ($d['equipment_id'] ?? 0));
            if ($e['status'] === 'Condemned') {
                throw new ApiException('Condemned equipment cannot be maintained.');
            }
            $task = trim((string) ($d['problem'] ?? ''));
            if ($task === '') {
                throw new ApiException('Describe the task.');
            }
            $m = Db::insert('maintenance', [
                'equipment_id'   => $e['id'], 'type' => 'Preventive', 'problem' => $task, 'status' => 'Scheduled',
                'reported_by'    => $user['id'],
                'scheduled_date' => self::date($d['scheduled_date'] ?? null, 'scheduled_date', true),
                'assigned_to'    => ($d['assigned_to'] ?? '') !== '' ? (int) $d['assigned_to'] : null,
                'repeat_months'  => max(0, min(60, (int) ($d['repeat_months'] ?? 0))),
            ]);
            Activity::log($user, 'maintenance', 'Preventive maintenance scheduled for ' . self::plain($e) . '.');

            return $m;
        });
    }

    public static function updateMaintenance(int $id, array $d, array $user): array
    {
        return Db::transaction(function () use ($id, $d, $user) {
            $m = Db::one('SELECT * FROM maintenance WHERE id = ? FOR UPDATE', [$id])
                ?? throw new ApiException('Maintenance record not found.', 404);
            $prev   = $m['status'];
            $status = $d['status'] ?? $m['status'];
            if (! in_array($status, ['Requested', 'Scheduled', 'In Progress', 'Completed', 'Cancelled'], true)) {
                throw new ApiException('Invalid status.');
            }
            $sched   = array_key_exists('scheduled_date', $d) ? self::date($d['scheduled_date'], 'scheduled_date') : $m['scheduled_date'];
            $done    = array_key_exists('completed_date', $d) ? self::date($d['completed_date'], 'completed_date') : $m['completed_date'];
            $outcome = array_key_exists('outcome', $d) ? ($d['outcome'] ?: null) : $m['outcome'];
            if (in_array($status, ['Scheduled', 'In Progress'], true) && ! $sched) {
                throw new ApiException('Enter a scheduled date for scheduled or in-progress work.');
            }
            if ($status === 'Completed') {
                $done    = $done ?: Db::today();
                $outcome = $outcome ?: 'Serviceable';
            }
            if ($outcome !== null && ! in_array($outcome, ['Serviceable', 'Unserviceable'], true)) {
                throw new ApiException('Outcome must be Serviceable or Unserviceable.');
            }
            $assigned = array_key_exists('assigned_to', $d) ? (($d['assigned_to'] ?? '') !== '' ? (int) $d['assigned_to'] : null) : $m['assigned_to'];
            $cost     = array_key_exists('cost', $d) ? (float) $d['cost'] : (float) $m['cost'];
            if ($cost < 0) {
                throw new ApiException('Cost cannot be negative.');
            }
            $upd = Db::update('maintenance', $id, [
                'problem'        => trim((string) ($d['problem'] ?? $m['problem'])),
                'priority'       => in_array($d['priority'] ?? $m['priority'], ['Normal', 'High'], true) ? ($d['priority'] ?? $m['priority']) : 'Normal',
                'status'         => $status,
                'scheduled_date' => $sched,
                'completed_date' => $done,
                'outcome'        => $outcome,
                'assigned_to'    => $assigned,
                'cost'           => $cost,
                'remarks'        => trim((string) ($d['remarks'] ?? $m['remarks'])),
                'repeat_months'  => array_key_exists('repeat_months', $d) ? max(0, min(60, (int) $d['repeat_months'])) : (int) $m['repeat_months'],
            ]);
            self::applyEffects($upd, $prev, $user);
            $e = Db::one('SELECT property_no, name FROM equipment WHERE id = ?', [$m['equipment_id']]);
            Activity::log($user, 'maintenance', 'Maintenance ' . strtolower($status) . ' for ' . ($e ? self::plain($e) : 'equipment') . '.');

            return $upd;
        });
    }

    /** Keeps the equipment status in step with its repair record. */
    public static function applyEffects(array $m, string $prev, ?array $user): void
    {
        $e = Db::one('SELECT * FROM equipment WHERE id = ? FOR UPDATE', [$m['equipment_id']]);
        if (! $e) {
            return;
        }
        $restore = $e['assigned_to'] ? 'Deployed' : 'Available';

        if ($m['type'] === 'Corrective') {
            if (in_array($m['status'], ['Requested', 'Scheduled'], true) && ! in_array($e['status'], ['Condemned', 'Under Repair'], true)) {
                Db::run("UPDATE equipment SET status = 'Defective' WHERE id = ?", [$e['id']]);
            }
            if ($m['status'] === 'In Progress' && $e['status'] !== 'Condemned') {
                Db::run("UPDATE equipment SET status = 'Under Repair' WHERE id = ?", [$e['id']]);
            }
            if ($m['status'] === 'Completed') {
                if ($m['outcome'] === 'Unserviceable') {
                    self::closeActiveIssuance((int) $e['id'], $m['completed_date'], 'Poor', 'Returned: found unserviceable.');
                    Db::run("UPDATE equipment SET assigned_to = NULL, status = 'Condemned', `condition` = 'Poor' WHERE id = ?", [$e['id']]);
                } else {
                    Db::run(
                        'UPDATE equipment SET status = ?, `condition` = CASE WHEN `condition` = ? THEN ? ELSE `condition` END WHERE id = ?',
                        [$restore, 'Poor', 'Fair', $e['id']]
                    );
                }
            }
            if ($m['status'] === 'Cancelled') {
                $open = (int) Db::value(
                    "SELECT COUNT(*) FROM maintenance WHERE equipment_id = ? AND id <> ? AND type = 'Corrective'
                     AND status IN ('Requested','Scheduled','In Progress')",
                    [$e['id'], $m['id']]
                );
                if ($open === 0 && in_array($e['status'], ['Defective', 'Under Repair'], true)) {
                    Db::run('UPDATE equipment SET status = ? WHERE id = ?', [$restore, $e['id']]);
                }
            }
        }

        if ($m['type'] === 'Preventive' && $m['status'] === 'Completed' && $prev !== 'Completed' && (int) $m['repeat_months'] > 0) {
            $base = new \DateTime($m['completed_date'] ?: Db::today());
            $base->modify('+' . (int) $m['repeat_months'] . ' months');
            Db::insert('maintenance', [
                'equipment_id' => $m['equipment_id'], 'type' => 'Preventive', 'problem' => $m['problem'],
                'status' => 'Scheduled', 'reported_by' => $user['id'] ?? null, 'scheduled_date' => $base->format('Y-m-d'),
                'assigned_to' => $m['assigned_to'], 'repeat_months' => $m['repeat_months'],
            ]);
        }
    }

    // --------------------------------------------------------------- requests
    public static function decideRequest(int $id, string $status, string $remarks, array $user): array
    {
        return Db::transaction(function () use ($id, $status, $remarks, $user) {
            $r = Db::one('SELECT * FROM equipment_requests WHERE id = ? FOR UPDATE', [$id])
                ?? throw new ApiException('Request not found.', 404);
            if ($r['status'] !== 'Pending') {
                throw new ApiException('This request has already been decided.');
            }
            if (! in_array($status, ['Approved', 'Rejected'], true)) {
                throw new ApiException('Status must be Approved or Rejected.');
            }
            $remarks = trim($remarks);
            if ($status === 'Rejected' && $remarks === '') {
                throw new ApiException('Tell the requester why the request was rejected.');
            }
            $upd = Db::update('equipment_requests', $id, [
                'status' => $status, 'remarks' => $remarks, 'decided_by' => $user['id'], 'decided_at' => Db::nowUtc(),
            ]);
            Activity::log($user, 'request', 'Equipment request "' . $r['item'] . '" ' . strtolower($status) . '.');

            return $upd;
        });
    }

    // ------------------------------------------------------- physical inventory
    public static function scan(int $sessionId, string $code, array $user): array
    {
        return Db::transaction(function () use ($sessionId, $code, $user) {
            $s = Db::one("SELECT * FROM inventory_sessions WHERE id = ? AND status = 'In progress' FOR UPDATE", [$sessionId])
                ?? throw new ApiException('No open verification session with that id.', 404);
            $c = trim(preg_replace('/^GEBPSOICS:/i', '', trim($code)));
            if ($c === '') {
                throw new ApiException('Enter or scan a code.');
            }
            $e = Db::one('SELECT * FROM equipment WHERE lower(property_no) = lower(?) OR barcode = ? OR lower(serial_no) = lower(?) LIMIT 1', [$c, $c, $c]);
            if (! $e) {
                Db::insert('inventory_scans', ['session_id' => $s['id'], 'code' => $c, 'result' => 'unknown', 'scanned_by' => $user['id']]);

                return ['result' => 'unknown', 'code' => $c];
            }
            if ($s['room_id'] && (int) $e['room_id'] !== (int) $s['room_id']) {
                if (! Db::one("SELECT 1 FROM inventory_scans WHERE session_id = ? AND equipment_id = ? AND result = 'elsewhere'", [$s['id'], $e['id']])) {
                    Db::insert('inventory_scans', ['session_id' => $s['id'], 'equipment_id' => $e['id'], 'code' => $c, 'result' => 'elsewhere', 'scanned_by' => $user['id']]);
                }

                return ['result' => 'elsewhere', 'equipment' => $e];
            }
            if (Db::one("SELECT 1 FROM inventory_scans WHERE session_id = ? AND equipment_id = ? AND result = 'verified'", [$s['id'], $e['id']])) {
                return ['result' => 'already', 'equipment' => $e];
            }
            Db::insert('inventory_scans', ['session_id' => $s['id'], 'equipment_id' => $e['id'], 'code' => $c, 'result' => 'verified', 'scanned_by' => $user['id']]);

            return ['result' => 'verified', 'equipment' => $e];
        });
    }

    public static function finishSession(int $sessionId, array $user): array
    {
        return Db::transaction(function () use ($sessionId, $user) {
            $s = Db::one("SELECT * FROM inventory_sessions WHERE id = ? AND status = 'In progress' FOR UPDATE", [$sessionId])
                ?? throw new ApiException('No open verification session with that id.', 404);
            $expected = (int) Db::value(
                "SELECT COUNT(*) FROM equipment WHERE status <> 'Condemned' AND (? IS NULL OR room_id = ?)",
                [$s['room_id'], $s['room_id']]
            );
            $verified = (int) Db::value("SELECT COUNT(*) FROM inventory_scans WHERE session_id = ? AND result = 'verified'", [$s['id']]);
            $upd = Db::update('inventory_sessions', $s['id'], [
                'status' => 'Completed', 'finished_at' => Db::nowUtc(), 'expected_count' => $expected,
                'verified_count' => $verified, 'missing_count' => max(0, $expected - $verified),
            ]);
            Activity::log($user, 'inventory', "Physical inventory finished ({$s['scope_label']}): $verified of $expected verified.");

            return $upd;
        });
    }
}
