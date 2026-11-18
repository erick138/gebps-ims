<?php

namespace App\Libraries;

class DashboardService
{
    public static function summary(array $user): array
    {
        $viewAll = Rbac::can($user['role'], 'viewAll');
        $today   = Db::today();
        $from12  = (new \DateTime($today))->modify('first day of this month')->modify('-11 months')->format('Y-m-d');

        $status = Db::all('SELECT status, COUNT(*) AS n FROM equipment GROUP BY status');
        $total  = array_sum(array_column($status, 'n'));
        $s      = array_column($status, 'n', 'status');

        $data = [
            'totals' => [
                'equipment'   => $total,
                'available'   => (int) ($s['Available'] ?? 0),
                'deployed'    => (int) ($s['Deployed'] ?? 0),
                'under_repair' => (int) ($s['Under Repair'] ?? 0),
                'defective'   => (int) ($s['Defective'] ?? 0),
                'condemned'   => (int) ($s['Condemned'] ?? 0),
                'overdue'     => (int) Db::value("SELECT COUNT(*) FROM issuances WHERE status = 'Active' AND type = 'Borrow' AND due_date < ?", [$today]),
                'open_repairs' => (int) Db::value("SELECT COUNT(*) FROM maintenance WHERE status IN ('Requested','Scheduled','In Progress')"),
                'low_stock'   => (int) Db::value('SELECT COUNT(*) FROM supplies WHERE qty <= reorder_level'),
                'pending_requests' => (int) Db::value("SELECT COUNT(*) FROM equipment_requests WHERE status = 'Pending'"),
            ],
            'by_status'   => $status,
            'by_category' => Db::all('SELECT c.name, COUNT(e.id) AS n FROM categories c LEFT JOIN equipment e ON e.category_id = c.id GROUP BY c.id, c.name ORDER BY n DESC, c.name'),
            'by_department' => Db::all('SELECT d.name, d.code, COUNT(e.id) AS n FROM departments d LEFT JOIN equipment e ON e.department_id = d.id GROUP BY d.id, d.name, d.code ORDER BY n DESC, d.name'),
            'by_condition' => Db::all('SELECT `condition`, COUNT(*) AS n FROM equipment GROUP BY `condition`'),
            'acquisitions' => Db::all(
                "SELECT DATE_FORMAT(acquisition_date, '%Y-%m') AS month, COUNT(*) AS n
                 FROM equipment WHERE acquisition_date >= ?
                 GROUP BY DATE_FORMAT(acquisition_date, '%Y-%m') ORDER BY month",
                [$from12]
            ),
            'recent_activity' => $viewAll ? Db::all('SELECT id, user_name, kind, message, created_at FROM activity_log ORDER BY id DESC LIMIT 12') : [],
        ];

        if (! $viewAll) {
            // Teachers and staff only see their own holdings and requests.
            $pid = (int) ($user['personnel_id'] ?? 0);
            $data = ['by_status' => [], 'by_category' => [], 'by_department' => [], 'by_condition' => [], 'acquisitions' => [], 'recent_activity' => []];
            $data['totals'] = [
                'my_equipment' => (int) Db::value("SELECT COUNT(*) FROM equipment WHERE assigned_to = ? AND status <> 'Condemned'", [$pid]),
                'my_overdue'   => (int) Db::value("SELECT COUNT(*) FROM issuances WHERE personnel_id = ? AND status = 'Active' AND type = 'Borrow' AND due_date < ?", [$pid, $today]),
                'my_requests'  => (int) Db::value("SELECT COUNT(*) FROM equipment_requests WHERE requested_by = ? AND status = 'Pending'", [$user['id']]),
            ];
        }

        return $data;
    }
}
