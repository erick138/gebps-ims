<?php

namespace App\Controllers\Api;

use App\Libraries\Activity;
use App\Libraries\ApiException;
use App\Libraries\Db;

class Supplies extends CrudController
{
    protected string $table = 'supplies';
    protected string $label = 'Supply';
    protected string $listSql = 'SELECT s.*, r.name AS room_name FROM supplies s LEFT JOIN rooms r ON r.id = s.room_id ORDER BY s.name';
    protected array $fields = [
        'name'          => ['required' => true, 'max' => 120],
        'unit'          => ['max' => 20],
        'qty'           => ['type' => 'int', 'required' => true],
        'reorder_level' => ['type' => 'int'],
        'room_id'       => ['type' => 'fk'],
    ];

    /** POST /supplies/{id}/adjust  {delta: -3, note: "..."} — stock in or out without overwriting the count. */
    public function adjust($id)
    {
        $in    = $this->input();
        $delta = $in['delta'] ?? null;
        if (! is_numeric($delta) || (int) $delta == 0 || abs((int) $delta) > 100000) {
            throw new ApiException('Enter a quantity to add or remove.');
        }

        return Db::transaction(function () use ($id, $delta, $in) {
            $s = Db::one('SELECT * FROM supplies WHERE id = ? FOR UPDATE', [$this->id($id)]) ?? throw new ApiException('Supply not found.', 404);
            $new = (int) $s['qty'] + (int) $delta;
            if ($new < 0) {
                throw new ApiException('Not enough stock: only ' . $s['qty'] . ' ' . $s['unit'] . ' on hand.');
            }
            $row = Db::update('supplies', $s['id'], ['qty' => $new]);
            Activity::log($this->user(), 'supply', ((int) $delta > 0 ? 'Stock in ' : 'Stock out ') . abs((int) $delta) . " {$s['unit']} of {$s['name']}" . (! empty($in['note']) ? ' — ' . mb_substr((string) $in['note'], 0, 120) : '') . '.');

            return $row;
        });
    }
}
