<?php

namespace App\Libraries;

/**
 * SMS reminder queue. The system does not talk to a carrier: it prepares messages and the
 * office sends them from a phone or an SMS gateway, then marks them as sent here. Each
 * message has a dedupe key so the same reminder is never queued twice.
 */
class SmsQueue
{
    public const TYPES = ['overdue', 'maintenance', 'request', 'approval', 'stock', 'return'];

    /** Normalises Philippine mobile numbers to +639XXXXXXXXX. Returns null if it is not one. */
    public static function phone(?string $raw): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $raw);
        if (preg_match('/^(?:63|0)?(9\d{9})$/', $d, $m)) {
            return '+63' . $m[1];
        }

        return null;
    }

    public static function settings(): array
    {
        $s = Db::one('SELECT prefix, types FROM sms_settings WHERE id = 1') ?? ['prefix' => 'GEBPSOICS', 'types' => '{}'];
        $types = json_decode($s['types'], true) ?: [];
        foreach (self::TYPES as $t) {
            $types[$t] = (bool) ($types[$t] ?? false);
        }

        return ['prefix' => $s['prefix'], 'types' => $types];
    }

    public static function saveSettings(array $d): array
    {
        $prefix = trim((string) ($d['prefix'] ?? 'GEBPSOICS'));
        $prefix = substr(preg_replace('/[^\w .\-]/', '', $prefix), 0, 24) ?: 'GEBPSOICS';
        $types  = [];
        foreach (self::TYPES as $t) {
            $types[$t] = (bool) ($d['types'][$t] ?? false);
        }
        Db::run(
            'INSERT INTO sms_settings (id, prefix, types) VALUES (1, ?, ?) ON DUPLICATE KEY UPDATE prefix = VALUES(prefix), types = VALUES(types)',
            [$prefix, json_encode($types)]
        );

        return self::settings();
    }

    /** Messages that are due and have not yet been marked as sent. */
    public static function pending(): array
    {
        $cfg   = self::settings();
        $on    = $cfg['types'];
        $today = Db::today();
        $out   = [];
        $add   = function (string $key, string $type, ?string $name, ?string $phone, string $text) use (&$out, $on, $cfg) {
            $p = self::phone($phone);
            if (! ($on[$type] ?? false) || $p === null) {
                return;
            }
            $out[] = ['key' => $key, 'type' => $type, 'name' => (string) $name, 'phone' => $p, 'message' => '[' . $cfg['prefix'] . '] ' . $text];
        };

        // Borrowers: overdue (one reminder per day) and due-today
        foreach (Db::all(
            "SELECT i.id, i.due_date, p.name, p.contact, e.name AS item, e.property_no FROM issuances i
             JOIN personnel p ON p.id = i.personnel_id JOIN equipment e ON e.id = i.equipment_id
             WHERE i.status = 'Active' AND i.type = 'Borrow' AND i.due_date <= ?",
            [$today]
        ) as $r) {
            $late = $r['due_date'] < $today;
            $add(
                'sms:overdue:' . $r['id'] . ':' . $today, 'overdue', $r['name'], $r['contact'],
                $late
                    ? "Hi {$r['name']}, {$r['item']} ({$r['property_no']}) was due on {$r['due_date']}. Please return it to the property custodian."
                    : "Hi {$r['name']}, {$r['item']} ({$r['property_no']}) is due for return today."
            );
        }

        // Maintenance staff: assigned work due
        foreach (Db::all(
            "SELECT m.id, m.scheduled_date, m.problem, e.property_no, e.name AS item, u.name, u.phone FROM maintenance m
             JOIN equipment e ON e.id = m.equipment_id JOIN users u ON u.id = m.assigned_to
             WHERE m.status IN ('Scheduled','In Progress') AND m.scheduled_date <= ? AND u.sms_opt_in AND u.status = 'active'",
            [Db::today('+1 day')]
        ) as $r) {
            $add('sms:maint:' . $r['id'] . ':' . $r['scheduled_date'], 'maintenance', $r['name'], $r['phone'],
                "Hi {$r['name']}, maintenance for {$r['item']} ({$r['property_no']}) is scheduled {$r['scheduled_date']}: {$r['problem']}");
        }

        // Approvers: new requests
        $approvers = Db::all("SELECT name, phone FROM users WHERE role IN ('superadmin','admin','custodian') AND status = 'active' AND sms_opt_in AND phone <> ''");
        foreach (Db::all("SELECT r.id, r.item, u.name AS who FROM equipment_requests r JOIN users u ON u.id = r.requested_by WHERE r.status = 'Pending'") as $r) {
            foreach ($approvers as $a) {
                $add('sms:req:' . $r['id'] . ':' . $a['phone'], 'request', $a['name'], $a['phone'], "New equipment request from {$r['who']}: {$r['item']}. Please review it in the inventory system.");
            }
        }

        // Requesters: decision made
        foreach (Db::all(
            "SELECT r.id, r.item, r.status, r.remarks, u.name, u.phone FROM equipment_requests r JOIN users u ON u.id = r.requested_by
             WHERE r.status <> 'Pending' AND r.decided_at > now() - INTERVAL 7 DAY AND u.sms_opt_in AND u.phone <> ''"
        ) as $r) {
            $add('sms:dec:' . $r['id'], 'approval', $r['name'], $r['phone'],
                "Hi {$r['name']}, your request for {$r['item']} was " . strtolower($r['status']) . ($r['remarks'] ? ": {$r['remarks']}" : '.'));
        }

        // Custodians: low stock
        foreach (Db::all('SELECT id, name, qty, unit FROM supplies WHERE qty <= reorder_level') as $s) {
            foreach ($approvers as $a) {
                $add('sms:stock:' . $s['id'] . ':' . $s['qty'] . ':' . $a['phone'], 'stock', $a['name'], $a['phone'], "Low stock: {$s['name']} has {$s['qty']} {$s['unit']} left.");
            }
        }

        // Returns recorded in the last 2 days (thank-you / confirmation)
        foreach (Db::all(
            "SELECT i.id, p.name, p.contact, e.name AS item FROM issuances i JOIN personnel p ON p.id = i.personnel_id JOIN equipment e ON e.id = i.equipment_id
             WHERE i.status = 'Returned' AND i.returned_date >= ?",
            [Db::today('-2 days')]
        ) as $r) {
            $add('sms:ret:' . $r['id'], 'return', $r['name'], $r['contact'], "Hi {$r['name']}, we received {$r['item']}. Thank you.");
        }

        $sent = [];
        if ($out) {
            $keys = array_column($out, 'key');
            $sent = array_column(Db::all('SELECT dedupe_key FROM sms_log WHERE dedupe_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ')', $keys), 'dedupe_key');
        }

        return array_values(array_filter($out, fn ($m) => ! in_array($m['key'], $sent, true)));
    }

    /** Records the given queue keys as sent. Only keys that are currently pending are accepted. */
    public static function markSent(array $keys, array $user): int
    {
        $pending = [];
        foreach (self::pending() as $m) {
            $pending[$m['key']] = $m;
        }
        $n = 0;
        foreach (array_unique($keys) as $k) {
            if (! isset($pending[$k])) {
                continue;
            }
            $m  = $pending[$k];
            $n += Db::run(
                'INSERT IGNORE INTO sms_log (dedupe_key, recipient_name, phone, message, type, marked_by) VALUES (?,?,?,?,?,?)',
                [$k, $m['name'], $m['phone'], $m['message'], $m['type'], $user['id']]
            );
        }
        if ($n > 0) {
            Activity::log($user, 'sms', "$n SMS reminder(s) marked as sent.");
        }

        return $n;
    }

    public static function log(int $limit = 100): array
    {
        return Db::all('SELECT l.*, u.name AS marked_by_name FROM sms_log l LEFT JOIN users u ON u.id = l.marked_by ORDER BY l.id DESC LIMIT ' . max(1, min(500, $limit)));
    }
}
