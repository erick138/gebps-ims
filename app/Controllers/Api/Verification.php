<?php

namespace App\Controllers\Api;

use App\Libraries\ApiException;
use App\Libraries\Activity;
use App\Libraries\Db;
use App\Libraries\InventoryService;

/** Physical inventory: scan items room by room; at the end, the unscanned ones are reported missing. */
class Verification extends BaseApiController
{
    public function index()
    {
        return Db::all('SELECT s.*, r.name AS room_name, u.name AS started_by_name FROM inventory_sessions s LEFT JOIN rooms r ON r.id = s.room_id LEFT JOIN users u ON u.id = s.started_by ORDER BY s.id DESC LIMIT 100');
    }

    public function start()
    {
        $in   = $this->input();
        $room = ! empty($in['room_id']) ? Db::one('SELECT * FROM rooms WHERE id = ?', [(int) $in['room_id']]) ?? throw new ApiException('Room not found.', 404) : null;
        if (Db::one("SELECT 1 FROM inventory_sessions WHERE status = 'In progress'")) {
            throw new ApiException('A verification is already in progress. Finish or discard it first.', 409);
        }
        $s = Db::insert('inventory_sessions', ['room_id' => $room['id'] ?? null, 'scope_label' => $room ? $room['name'] : 'Entire school', 'started_by' => $this->user()['id']]);
        Activity::log($this->user(), 'inventory', 'Physical inventory started: ' . $s['scope_label'] . '.');

        return $this->ok($s, 201);
    }

    public function show($id)
    {
        $s = Db::one('SELECT s.*, r.name AS room_name FROM inventory_sessions s LEFT JOIN rooms r ON r.id = s.room_id WHERE s.id = ?', [$this->id($id)]) ?? throw new ApiException('Session not found.', 404);
        $s['scans'] = Db::all('SELECT sc.*, e.property_no, e.name AS item FROM inventory_scans sc LEFT JOIN equipment e ON e.id = sc.equipment_id WHERE sc.session_id = ? ORDER BY sc.id DESC', [$s['id']]);
        $s['missing'] = Db::all(
            "SELECT e.id, e.property_no, e.name, e.room_name FROM v_equipment e WHERE e.status <> 'Condemned' AND (? IS NULL OR e.room_id = ?)
             AND NOT EXISTS (SELECT 1 FROM inventory_scans sc WHERE sc.session_id = ? AND sc.equipment_id = e.id AND sc.result = 'verified') ORDER BY e.property_no",
            [$s['room_id'], $s['room_id'], $s['id']]
        );

        return $s;
    }

    public function scan($id)
    {
        return InventoryService::scan($this->id($id), (string) ($this->input()['code'] ?? ''), $this->user());
    }

    /** DELETE verification/{id}/scans/{equipmentId} — "undo" a manual verification. */
    public function undo($id, $equipmentId)
    {
        $n = Db::run(
            "DELETE FROM inventory_scans WHERE session_id = ? AND equipment_id = ? AND result = 'verified'
             AND EXISTS (SELECT 1 FROM inventory_sessions s WHERE s.id = ? AND s.status = 'In progress')",
            [$this->id($id), $this->id($equipmentId), $this->id($id)]
        );

        return ['removed' => $n];
    }

    public function finish($id)
    {
        return InventoryService::finishSession($this->id($id), $this->user());
    }

    public function discard($id)
    {
        $n = Db::run("UPDATE inventory_sessions SET status = 'Discarded', finished_at = now() WHERE id = ? AND status = 'In progress'", [$this->id($id)]);
        if (! $n) {
            throw new ApiException('No open verification session with that id.', 404);
        }

        return ['discarded' => true];
    }
}
