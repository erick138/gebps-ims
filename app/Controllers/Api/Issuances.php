<?php

namespace App\Controllers\Api;

use App\Libraries\Db;
use App\Libraries\InventoryService;

class Issuances extends BaseApiController
{
    public function index()
    {
        $u = $this->user();
        $c = [];
        $b = [];
        if (! \App\Libraries\Rbac::can($u['role'], 'viewAll')) {
            $c[] = 'i.personnel_id = ?';
            $b[] = (int) ($u['personnel_id'] ?? 0);
        }
        foreach (['status', 'type'] as $k) {
            if ($this->request->getGet($k)) {
                $c[] = "i.$k = ?";
                $b[] = (string) $this->request->getGet($k);
            }
        }
        foreach (['personnel_id', 'equipment_id'] as $k) {
            if ($this->request->getGet($k)) {
                $c[] = "i.$k = ?";
                $b[] = (int) $this->request->getGet($k);
            }
        }
        if ($this->request->getGet('overdue')) {
            $c[] = "i.status = 'Active' AND i.type = 'Borrow' AND i.due_date < ?";
            $b[] = Db::today();
        }
        $w = $c ? ' WHERE ' . implode(' AND ', $c) : '';

        return Db::bools(Db::all(
            "SELECT i.*, e.property_no, e.name AS item, p.name AS person, iu.name AS issued_by_name,
                    (i.status = 'Active' AND i.type = 'Borrow' AND i.due_date < ?) AS overdue
             FROM issuances i JOIN equipment e ON e.id = i.equipment_id JOIN personnel p ON p.id = i.personnel_id
             LEFT JOIN users iu ON iu.id = i.issued_by$w ORDER BY i.id DESC LIMIT 1000",
            [Db::today(), ...$b]
        ), ['overdue']);
    }

    public function create()
    {
        return $this->ok(InventoryService::issue($this->input(), $this->user()), 201);
    }

    public function returnItem($id)
    {
        return InventoryService::returnItem($this->id($id), $this->input(), $this->user());
    }
}
