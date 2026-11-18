<?php

namespace App\Controllers\Api;

class Categories extends CrudController
{
    protected string $table = 'categories';
    protected string $label = 'Category';
    protected string $orderBy = 'name';
    protected array $fields = [
        'code' => ['required' => true, 'max' => 6, 'pattern' => '/^[A-Za-z0-9]+$/', 'hint' => 'Letters and numbers only.'],
        'name' => ['required' => true, 'max' => 80],
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
