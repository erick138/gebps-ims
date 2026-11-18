<?php

namespace App\Controllers\Api;

class Departments extends CrudController
{
    protected string $table = 'departments';
    protected string $label = 'Department';
    protected string $orderBy = 'name';
    protected array $fields = [
        'code' => ['required' => true, 'max' => 12, 'pattern' => '/^[A-Za-z0-9\-]+$/', 'hint' => 'Letters, numbers and dashes only.'],
        'name' => ['required' => true, 'max' => 120],
        'head' => ['max' => 120],
    ];

    protected function clean(array $in, bool $creating): array
    {
        $d = parent::clean($in, $creating);
        if (isset($d['code'])) {
            $d['code'] = strtoupper($d['code']);
        }

        return $d;
    }
}
