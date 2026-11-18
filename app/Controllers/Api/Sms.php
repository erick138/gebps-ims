<?php

namespace App\Controllers\Api;

use App\Libraries\SmsQueue;

class Sms extends BaseApiController
{
    public function queue()
    {
        return ['settings' => SmsQueue::settings(), 'pending' => SmsQueue::pending()];
    }

    public function markSent()
    {
        $keys = (array) ($this->input()['keys'] ?? []);

        return ['marked' => SmsQueue::markSent(array_map('strval', $keys), $this->user())];
    }

    public function log()
    {
        return SmsQueue::log((int) ($this->request->getGet('limit') ?? 100));
    }

    public function settings()
    {
        return SmsQueue::saveSettings($this->input());
    }
}
