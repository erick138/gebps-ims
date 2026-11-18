<?php

namespace App\Libraries;

/** The eight printable reports. Each returns {title, columns[], rows[][]} so any client can render, print or export it. */
class ReportService
{
    public const KINDS = [
        'inventory'   => 'Equipment Inventory',
        'department'  => 'Equipment by Department',
        'issuance'    => 'Issuance & Return Log',
        'overdue'     => 'Overdue Borrowed Items',
        'maintenance' => 'Maintenance & Repair',
        'condemned'   => 'Condemned / Unserviceable Equipment',
        'transfers'   => 'Equipment Transfers',
        'supplies'    => 'Supplies Stock',
    ];

    public static function build(string $kind, array $f = []): array
    {
        if (! isset(self::KINDS[$kind])) {
            throw new ApiException('Unknown report.', 404);
        }
        $from = InventoryService::date($f['from'] ?? null, 'from');
        $to   = InventoryService::date($f['to'] ?? null, 'to');

        return ['kind' => $kind, 'title' => self::KINDS[$kind], 'generated_at' => date('c')] + self::{'r_' . $kind}($f, $from, $to);
    }

    private static function where(array $conds, array $binds): array
    {
        return [$conds ? ' WHERE ' . implode(' AND ', $conds) : '', $binds];
    }

    private static function r_inventory(array $f): array
    {
        $c = [];
        $b = [];
        foreach (['department_id' => 'department_id', 'category_id' => 'category_id', 'room_id' => 'room_id'] as $k => $col) {
            if (! empty($f[$k])) {
                $c[] = "$col = ?";
                $b[] = (int) $f[$k];
            }
        }
        foreach (['status', 'condition'] as $k) {
            if (! empty($f[$k])) {
                $c[] = "`$k` = ?";
                $b[] = (string) $f[$k];
            }
        }
        [$w, $b] = self::where($c, $b);
        $rows    = Db::all("SELECT * FROM v_equipment $w ORDER BY property_no", $b);

        return [
            'columns' => ['Property No.', 'Item', 'Category', 'Department', 'Room', 'Condition', 'Status', 'Assigned To', 'Acquired'],
            'rows'    => array_map(fn ($r) => [$r['property_no'], $r['name'], $r['category_name'], $r['department_name'], $r['room_name'], $r['condition'], $r['status'], $r['assigned_name'], $r['acquisition_date']], $rows),
            'totals'  => ['Items' => count($rows)],
        ];
    }

    private static function r_department(array $f): array
    {
        $rows = Db::all(
            "SELECT d.code, d.name, COUNT(e.id) AS items,
                    COUNT(CASE WHEN e.status = 'Available' THEN 1 END) AS available, COUNT(CASE WHEN e.status = 'Deployed' THEN 1 END) AS deployed,
                    COUNT(CASE WHEN e.status IN ('Under Repair','Defective') THEN 1 END) AS repair, COUNT(CASE WHEN e.status = 'Condemned' THEN 1 END) AS condemned
             FROM departments d LEFT JOIN equipment e ON e.department_id = d.id GROUP BY d.id, d.code, d.name ORDER BY d.name"
        );
        return [
            'columns' => ['Code', 'Department', 'Items', 'Available', 'Deployed', 'Repair / Defective', 'Condemned'],
            'rows'    => array_map(function ($r) {
                $z = (int) $r['items'] === 0;

                return [$r['code'], $r['name'], (int) $r['items'], $z ? 0 : (int) $r['available'], $z ? 0 : (int) $r['deployed'], $z ? 0 : (int) $r['repair'], $z ? 0 : (int) $r['condemned']];
            }, $rows),
        ];
    }

    private static function r_issuance(array $f, ?string $from, ?string $to): array
    {
        $c = [];
        $b = [];
        if ($from) {
            $c[] = 'i.issued_date >= ?';
            $b[] = $from;
        }
        if ($to) {
            $c[] = 'i.issued_date <= ?';
            $b[] = $to;
        }
        if (! empty($f['status'])) {
            $c[] = 'i.status = ?';
            $b[] = (string) $f['status'];
        }
        if (! empty($f['personnel_id'])) {
            $c[] = 'i.personnel_id = ?';
            $b[] = (int) $f['personnel_id'];
        }
        [$w, $b] = self::where($c, $b);
        $rows    = Db::all(
            "SELECT i.*, e.property_no, e.name AS item, p.name AS person FROM issuances i
             JOIN equipment e ON e.id = i.equipment_id JOIN personnel p ON p.id = i.personnel_id $w ORDER BY i.id DESC",
            $b
        );

        return [
            'columns' => ['Ref. No.', 'Property No.', 'Item', 'Issued To', 'Type', 'Issued', 'Due', 'Returned', 'Condition', 'Status'],
            'rows'    => array_map(fn ($r) => [$r['ref_no'], $r['property_no'], $r['item'], $r['person'], $r['type'], $r['issued_date'], $r['due_date'], $r['returned_date'], $r['return_condition'], $r['status']], $rows),
        ];
    }

    private static function r_overdue(): array
    {
        $rows = Db::all(
            "SELECT i.ref_no, i.due_date, DATEDIFF(?, i.due_date) AS days_late, e.property_no, e.name AS item, p.name AS person, p.contact
             FROM issuances i JOIN equipment e ON e.id = i.equipment_id JOIN personnel p ON p.id = i.personnel_id
             WHERE i.status = 'Active' AND i.type = 'Borrow' AND i.due_date < ? ORDER BY i.due_date",
            [Db::today(), Db::today()]
        );

        return [
            'columns' => ['Ref. No.', 'Property No.', 'Item', 'Borrower', 'Contact', 'Due', 'Days Late'],
            'rows'    => array_map(fn ($r) => [$r['ref_no'], $r['property_no'], $r['item'], $r['person'], $r['contact'], $r['due_date'], (int) $r['days_late']], $rows),
        ];
    }

    private static function r_maintenance(array $f, ?string $from, ?string $to): array
    {
        $c = [];
        $b = [];
        if ($from) {
            $c[] = 'm.reported_date >= ?';
            $b[] = $from;
        }
        if ($to) {
            $c[] = 'm.reported_date <= ?';
            $b[] = $to;
        }
        foreach (['status', 'type'] as $k) {
            if (! empty($f[$k])) {
                $c[] = "m.$k = ?";
                $b[] = (string) $f[$k];
            }
        }
        [$w, $b] = self::where($c, $b);
        $rows    = Db::all(
            "SELECT m.*, e.property_no, e.name AS item, u.name AS tech FROM maintenance m
             JOIN equipment e ON e.id = m.equipment_id LEFT JOIN users u ON u.id = m.assigned_to $w ORDER BY m.id DESC",
            $b
        );

        return [
            'columns' => ['Property No.', 'Item', 'Type', 'Problem / Task', 'Reported', 'Scheduled', 'Technician', 'Status', 'Outcome', 'Cost'],
            'rows'    => array_map(fn ($r) => [$r['property_no'], $r['item'], $r['type'], $r['problem'], $r['reported_date'], $r['scheduled_date'], $r['tech'], $r['status'], $r['outcome'], (float) $r['cost']], $rows),
            'totals'  => ['Records' => count($rows), 'Repair cost' => array_sum(array_column($rows, 'cost'))],
        ];
    }

    private static function r_condemned(): array
    {
        $rows = Db::all("SELECT * FROM v_equipment WHERE status = 'Condemned' ORDER BY property_no");

        return [
            'columns' => ['Property No.', 'Item', 'Category', 'Department', 'Room', 'Acquired'],
            'rows'    => array_map(fn ($r) => [$r['property_no'], $r['name'], $r['category_name'], $r['department_name'], $r['room_name'], $r['acquisition_date']], $rows),
            'totals'  => ['Items' => count($rows)],
        ];
    }

    private static function r_transfers(array $f, ?string $from, ?string $to): array
    {
        $c = [];
        $b = [];
        if ($from) {
            $c[] = 't.transfer_date >= ?';
            $b[] = $from;
        }
        if ($to) {
            $c[] = 't.transfer_date <= ?';
            $b[] = $to;
        }
        [$w, $b] = self::where($c, $b);
        $rows    = Db::all(
            "SELECT t.*, e.property_no, e.name AS item, rf.name AS from_room, rt.name AS to_room, u.name AS by_name FROM transfers t
             JOIN equipment e ON e.id = t.equipment_id LEFT JOIN rooms rf ON rf.id = t.from_room_id JOIN rooms rt ON rt.id = t.to_room_id
             LEFT JOIN users u ON u.id = t.transferred_by $w ORDER BY t.id DESC",
            $b
        );

        return [
            'columns' => ['Date', 'Property No.', 'Item', 'From', 'To', 'Reason', 'By'],
            'rows'    => array_map(fn ($r) => [$r['transfer_date'], $r['property_no'], $r['item'], $r['from_room'], $r['to_room'], $r['reason'], $r['by_name']], $rows),
        ];
    }

    private static function r_supplies(): array
    {
        $rows = Db::all('SELECT s.*, r.name AS room FROM supplies s LEFT JOIN rooms r ON r.id = s.room_id ORDER BY s.name');

        return [
            'columns' => ['Item', 'Unit', 'On Hand', 'Reorder Level', 'Location', 'Stock'],
            'rows'    => array_map(fn ($r) => [$r['name'], $r['unit'], (int) $r['qty'], (int) $r['reorder_level'], $r['room'], (int) $r['qty'] === 0 ? 'Out of stock' : ((int) $r['qty'] <= (int) $r['reorder_level'] ? 'Low' : 'OK')], $rows),
        ];
    }

    /** CSV with a UTF-8 BOM so Excel opens accented names correctly. Cells starting with = + - @ are neutralised. */
    public static function csv(array $report): string
    {
        $h = fopen('php://temp', 'w+');
        fwrite($h, "\xEF\xBB\xBF");
        $safe = static function ($v) {
            $v = $v === null ? '' : (string) $v;

            return $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false && ! is_numeric($v) ? "'" . $v : $v;
        };
        fputcsv($h, [$report['title']], ',', '"', '');
        fputcsv($h, array_map($safe, $report['columns']), ',', '"', '');
        foreach ($report['rows'] as $row) {
            fputcsv($h, array_map($safe, $row), ',', '"', '');
        }
        rewind($h);

        return stream_get_contents($h);
    }
}
