<?php

namespace App\Controllers\Api;

use App\Libraries\Db;

class Rooms extends CrudController
{
    protected string $table = 'rooms';
    protected string $label = 'Room';
    protected string $listSql = 'SELECT r.*, d.name AS department_name FROM rooms r JOIN departments d ON d.id = r.department_id ORDER BY d.name, r.name';
    protected array $fields = [
        'name' => ['required' => true, 'max' => 120],
        'type' => ['type' => 'enum', 'values' => ['Classroom', 'Laboratory', 'Storage Room', 'Office', 'Library', 'Gymnasium', 'Other']],
        'department_id' => ['type' => 'fk', 'required' => true],
    ];

    /** Keeps each item's department in step when a room is moved to another department. */
    protected function after(array $row): array
    {
        Db::run('UPDATE equipment SET department_id = ? WHERE room_id = ? AND NOT (department_id <=> ?)', [$row['department_id'], $row['id'], $row['department_id']]);

        return $row;
    }
}
