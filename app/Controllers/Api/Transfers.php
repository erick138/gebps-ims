<?php

namespace App\Controllers\Api;

use App\Libraries\Db;
use App\Libraries\InventoryService;

class Transfers extends BaseApiController
{
    public function index()
    {
        return Db::all(
            'SELECT t.*, e.property_no, e.name AS item, rf.name AS from_room, rt.name AS to_room, u.name AS by_name FROM transfers t
             JOIN equipment e ON e.id = t.equipment_id LEFT JOIN rooms rf ON rf.id = t.from_room_id JOIN rooms rt ON rt.id = t.to_room_id
             LEFT JOIN users u ON u.id = t.transferred_by ORDER BY t.id DESC LIMIT 1000'
        );
    }

    public function create()
    {
        return $this->ok(InventoryService::transfer($this->input(), $this->user()), 201);
    }
}
