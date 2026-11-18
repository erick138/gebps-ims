<?php

namespace App\Controllers\Api;

use App\Libraries\Db;

class Health extends BaseApiController
{
    public function index()
    {
        return ['status' => 'ok', 'database' => Db::value('SELECT 1') == 1, 'time' => date('c')];
    }
}
