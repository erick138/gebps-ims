<?php

namespace App\Controllers\Api;

use App\Libraries\Activity;
use App\Libraries\ReportService;

class Reports extends BaseApiController
{
    public function index()
    {
        return array_map(fn ($k, $t) => ['kind' => $k, 'title' => $t], array_keys(ReportService::KINDS), ReportService::KINDS);
    }

    /** GET /reports/{kind}?format=json|csv&from=&to=&status=... */
    public function show($kind)
    {
        $r = ReportService::build((string) $kind, $this->request->getGet() ?? []);
        if ($this->request->getGet('format') === 'csv') {
            Activity::log($this->user(), 'report', 'Report exported (CSV): ' . $r['title'] . '.');

            return $this->response->setHeader('Content-Type', 'text/csv; charset=utf-8')
                ->setHeader('Content-Disposition', 'attachment; filename="' . $kind . '-' . date('Ymd') . '.csv"')
                ->setBody(ReportService::csv($r));
        }

        return $r;
    }
}
