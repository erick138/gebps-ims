<?php

namespace App\Libraries;

/** Builds the notification list shown in the bell menu. Every alert has a stable key so "read" state can be stored. */
class Alerts
{
    public const DUE_SOON_DAYS = 3;
    public const MAINT_SOON_DAYS = 7;

    public static function forUser(array $user): array
    {
        $role   = $user['role'];
        $all    = Rbac::can($role, 'viewAll');
        $today  = Db::today();
        $soon   = Db::today('+' . self::DUE_SOON_DAYS . ' days');
        $msoon  = Db::today('+' . self::MAINT_SOON_DAYS . ' days');
        $out    = [];
        $mine   = (int) ($user['personnel_id'] ?? 0);

        // Borrowed items: overdue / due soon
        $sql = "SELECT i.id, i.ref_no, i.due_date, i.personnel_id, p.name AS person, p.contact, e.property_no, e.name AS item
                FROM issuances i JOIN personnel p ON p.id = i.personnel_id JOIN equipment e ON e.id = i.equipment_id
                WHERE i.status = 'Active' AND i.type = 'Borrow' AND i.due_date <= ?";
        $b   = [$soon];
        if (! $all) {
            $sql .= ' AND i.personnel_id = ?';
            $b[]  = $mine;
        }
        foreach (Db::all($sql . ' ORDER BY i.due_date', $b) as $r) {
            $late  = $r['due_date'] < $today;
            $days  = (int) abs((strtotime($r['due_date']) - strtotime($today)) / 86400);
            $out[] = [
                'key' => ($late ? 'overdue:' : 'duesoon:') . $r['id'], 'type' => $late ? 'overdue' : 'due_soon',
                'severity' => $late ? 'danger' : 'warning', 'link' => 'issuance',
                'title' => $late ? 'Overdue: ' . $r['item'] : 'Due soon: ' . $r['item'],
                'message' => $late
                    ? "{$r['property_no']} borrowed by {$r['person']} is $days day(s) overdue (due {$r['due_date']})."
                    : "{$r['property_no']} borrowed by {$r['person']} is due " . ($days === 0 ? 'today' : "in $days day(s)") . '.',
                'date' => $r['due_date'],
            ];
        }

        // Maintenance
        if ($all) {
            $rows = Db::all(
                "SELECT m.*, e.property_no, e.name AS item FROM maintenance m JOIN equipment e ON e.id = m.equipment_id
                 WHERE (m.status = 'Requested' AND m.type = 'Corrective')
                    OR (m.status IN ('Scheduled','In Progress') AND m.scheduled_date <= ?)",
                [$msoon]
            );
            foreach ($rows as $m) {
                if ($m['status'] === 'Requested') {
                    $out[] = [
                        'key' => 'repair:' . $m['id'], 'type' => 'maintenance', 'severity' => $m['priority'] === 'High' ? 'danger' : 'warning',
                        'link' => 'maintenance', 'title' => 'Repair requested: ' . $m['item'],
                        'message' => "{$m['property_no']} — {$m['problem']}", 'date' => $m['reported_date'],
                    ];
                } else {
                    $late  = $m['scheduled_date'] < $today;
                    $out[] = [
                        'key' => 'maint:' . $m['id'], 'type' => 'maintenance', 'severity' => $late ? 'danger' : 'info',
                        'link' => 'maintenance', 'title' => ($late ? 'Maintenance overdue: ' : 'Maintenance due: ') . $m['item'],
                        'message' => "{$m['property_no']} scheduled {$m['scheduled_date']} ({$m['type']}).", 'date' => $m['scheduled_date'],
                    ];
                }
            }
        }

        // Low stock
        if (Rbac::can($role, 'equipment')) {
            foreach (Db::all('SELECT id, name, qty, unit, reorder_level FROM supplies WHERE qty <= reorder_level ORDER BY name') as $s) {
                $out[] = [
                    'key' => 'stock:' . $s['id'] . ':' . $s['qty'], 'type' => 'stock', 'severity' => (int) $s['qty'] === 0 ? 'danger' : 'warning',
                    'link' => 'supplies', 'title' => ((int) $s['qty'] === 0 ? 'Out of stock: ' : 'Low stock: ') . $s['name'],
                    'message' => "{$s['qty']} {$s['unit']} left (reorder at {$s['reorder_level']}).", 'date' => $today,
                ];
            }
        }

        // Requests
        if (Rbac::can($role, 'approve')) {
            foreach (Db::all("SELECT r.id, r.item, DATE(r.created_at + INTERVAL ? SECOND) AS d, u.name FROM equipment_requests r JOIN users u ON u.id = r.requested_by WHERE r.status = 'Pending' ORDER BY r.id", [Db::offsetSeconds()]) as $r) {
                $out[] = [
                    'key' => 'req:' . $r['id'], 'type' => 'request', 'severity' => 'info', 'link' => 'requests',
                    'title' => 'Request awaiting approval: ' . $r['item'], 'message' => "Submitted by {$r['name']}.", 'date' => $r['d'],
                ];
            }
        }
        foreach (Db::all("SELECT id, item, status, remarks, DATE(decided_at + INTERVAL ? SECOND) AS d FROM equipment_requests WHERE requested_by = ? AND status <> 'Pending' AND decided_at > now() - INTERVAL 14 DAY", [Db::offsetSeconds(), $user['id']]) as $r) {
            $out[] = [
                'key' => 'reqdone:' . $r['id'], 'type' => 'approval', 'severity' => $r['status'] === 'Approved' ? 'success' : 'danger',
                'link' => 'requests', 'title' => 'Your request was ' . strtolower($r['status']) . ': ' . $r['item'],
                'message' => $r['remarks'] ?: 'No remarks.', 'date' => $r['d'],
            ];
        }

        // New account approvals
        if (Rbac::can($role, 'users')) {
            foreach (Db::all("SELECT id, name, DATE(created_at + INTERVAL ? SECOND) AS d FROM users WHERE status = 'pending' ORDER BY id", [Db::offsetSeconds()]) as $u) {
                $out[] = [
                    'key' => 'acct:' . $u['id'], 'type' => 'account', 'severity' => 'info', 'link' => 'users',
                    'title' => 'Account awaiting approval', 'message' => $u['name'] . ' registered.', 'date' => $u['d'],
                ];
            }
        }

        $read = array_column(Db::all('SELECT alert_key FROM notification_reads WHERE user_id = ?', [$user['id']]), 'alert_key');
        $rank = ['danger' => 0, 'warning' => 1, 'info' => 2, 'success' => 3];
        foreach ($out as &$a) {
            $a['read'] = in_array($a['key'], $read, true);
        }
        unset($a);
        usort($out, fn ($x, $y) => [$x['read'], $rank[$x['severity']], $x['date']] <=> [$y['read'], $rank[$y['severity']], $y['date']]);

        return $out;
    }

    public static function markUnread(array $user, array $keys): void
    {
        foreach (array_slice(array_unique(array_filter($keys, 'is_string')), 0, 500) as $k) {
            Db::run('DELETE FROM notification_reads WHERE user_id = ? AND alert_key = ?', [$user['id'], $k]);
        }
    }

    public static function markRead(array $user, array $keys): void
    {
        foreach (array_slice(array_unique(array_filter($keys, 'is_string')), 0, 500) as $k) {
            Db::run('INSERT IGNORE INTO notification_reads (user_id, alert_key) VALUES (?, ?)', [$user['id'], substr($k, 0, 120)]);
        }
    }
}
