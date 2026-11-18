<?php

namespace App\Libraries;

/** Holds the authenticated user for the current request (set by AuthFilter). */
class CurrentUser
{
    private static ?array $user = null;

    public static function set(?array $user): void
    {
        self::$user = $user;
    }

    public static function get(): ?array
    {
        return self::$user;
    }

    public static function require(): array
    {
        return self::$user ?? throw new ApiException('Authentication required.', 401);
    }
}
