<?php

namespace App\Controllers\Api;

use App\Libraries\DashboardService;

class Dashboard extends BaseApiController
{
    public function index()
    {
        return DashboardService::summary($this->user());
    }
}
