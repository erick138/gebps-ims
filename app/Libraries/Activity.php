<?php

namespace App\Libraries;

/** Writes the audit trail shown on the Activity Log screen. */
class Activity
{
    public static function log(?array $user, string $kind, string $message): void
    {
        Db::run(
            'INSERT INTO activity_log (user_id, user_name, kind, message) VALUES (?, ?, ?, ?)',
            [$user['id'] ?? null, $user['name'] ?? 'System', $kind, $message]
        );
    }
}
