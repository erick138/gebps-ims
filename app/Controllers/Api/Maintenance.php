<?php

namespace App\Controllers\Api;

use App\Libraries\ApiException;
use App\Libraries\Db;
use App\Libraries\InventoryService;
use App\Libraries\Rbac;

class Maintenance extends BaseApiController
{
    public function index()
    {
        $u = $this->user();
        $c = [];
        $b = [];
        if (! Rbac::can($u['role'], 'viewAll')) {
            $c[] = 'e.assigned_to = ?';
            $b[]  = (int) ($u['personnel_id'] ?? 0);
        }
        foreach (['status', 'type'] as $k) {
            if ($this->request->getGet($k)) {
                $c[] = "m.$k = ?";
                $b[] = (string) $this->request->getGet($k);
            }
        }
        $w = $c ? ' WHERE ' . implode(' AND ', $c) : '';

        return Db::all(
            "SELECT m.*, e.property_no, e.name AS item, u.name AS tech, rp.name AS reporter FROM maintenance m
             JOIN equipment e ON e.id = m.equipment_id LEFT JOIN users u ON u.id = m.assigned_to LEFT JOIN users rp ON rp.id = m.reported_by$w
             ORDER BY m.id DESC LIMIT 1000",
            $b
        );
    }

    /** Anyone signed in can report a problem; staff only on items assigned to them (enforced in the service). */
    public function request()
    {
        return $this->ok(InventoryService::requestRepair($this->input(), $this->user()), 201);
    }

    public function schedule()
    {
        return $this->ok(InventoryService::schedulePreventive($this->input(), $this->user()), 201);
    }

    public function update($id)
    {
        return InventoryService::updateMaintenance($this->id($id), $this->input(), $this->user());
    }

    /** Technicians: list of users who can be assigned work. */
    public function technicians()
    {
        return Db::all("SELECT id, name FROM users WHERE status = 'active' AND role IN ('maintenance','custodian','admin','superadmin') ORDER BY name");
    }
}
