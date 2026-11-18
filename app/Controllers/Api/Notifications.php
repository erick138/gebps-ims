<?php

namespace App\Controllers\Api;

use App\Libraries\Alerts;

class Notifications extends BaseApiController
{
    public function index()
    {
        $a = Alerts::forUser($this->user());

        return $this->response->setJSON(['data' => $a, 'meta' => ['unread' => count(array_filter($a, fn ($x) => ! $x['read']))]]);
    }

    /** POST /notifications/read  {keys: [...]} or {all: true} */
    public function read()
    {
        $in   = $this->input();
        if (! empty($in['unread'])) {
            Alerts::markUnread($this->user(), (array) ($in['keys'] ?? []));

            return ['ok' => true];
        }
        $keys = ! empty($in['all']) ? array_column(Alerts::forUser($this->user()), 'key') : (array) ($in['keys'] ?? []);
        Alerts::markRead($this->user(), $keys);

        return ['ok' => true];
    }
}
