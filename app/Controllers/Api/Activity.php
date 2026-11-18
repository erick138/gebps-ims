<?php

namespace App\Controllers\Api;

use App\Libraries\Db;

class Activity extends BaseApiController
{
    public function index()
    {
        [$per, $off, $page] = $this->paging();
        $c = [];
        $b = [];
        if ($this->request->getGet('kind')) {
            $c[] = 'kind = ?';
            $b[] = (string) $this->request->getGet('kind');
        }
        if (($q = trim((string) $this->request->getGet('q'))) !== '') {
            $c[] = '(message LIKE ? OR user_name LIKE ?)';
            $b[] = $b[] = '%' . addcslashes($q, '%_\\') . '%';
        }
        $w     = $c ? ' WHERE ' . implode(' AND ', $c) : '';
        $total = (int) Db::value("SELECT COUNT(*) FROM activity_log$w", $b);

        return $this->response->setJSON([
            'data' => Db::all("SELECT * FROM activity_log$w ORDER BY id DESC LIMIT $per OFFSET $off", $b),
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $per],
        ]);
    }
}
