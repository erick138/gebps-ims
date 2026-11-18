<?php

namespace App\Controllers\Api;

use App\Libraries\Db;

class Personnel extends CrudController
{
    protected string $table = 'personnel';
    protected string $label = 'Personnel';
    protected string $listSql = 'SELECT p.*, d.name AS department_name,
        (SELECT COUNT(*) FROM equipment e WHERE e.assigned_to = p.id) AS items_held
        FROM personnel p LEFT JOIN departments d ON d.id = p.department_id ORDER BY p.name';
    protected array $fields = [
        'emp_no'        => ['required' => true, 'max' => 30],
        'name'          => ['required' => true, 'max' => 120],
        'designation'   => ['max' => 120],
        'department_id' => ['type' => 'fk'],
        'contact'       => ['max' => 30],
        'email'         => ['max' => 160, 'pattern' => '/^[^@\s]+@[^@\s]+\.[^@\s]+$/', 'hint' => 'Enter a valid email address.'],
        'status'        => ['type' => 'enum', 'values' => ['Active', 'Inactive']],
    ];

    /** Items currently assigned to / borrowed by one person, plus their issuance history. */
    public function holdings($id)
    {
        $id = $this->id($id);

        return [
            'equipment' => Db::all('SELECT * FROM v_equipment WHERE assigned_to = ? ORDER BY property_no', [$id]),
            'history'   => Db::all('SELECT i.*, e.property_no, e.name AS item FROM issuances i JOIN equipment e ON e.id = i.equipment_id WHERE i.personnel_id = ? ORDER BY i.id DESC', [$id]),
        ];
    }
}
