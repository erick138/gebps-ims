<?php
/**
 * GEBPSOICS API - plain PHP bootstrap: autoloader, .env loader, helpers. No Composer needed.
 */
declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
define('STORAGE_PATH', ROOT_PATH . '/storage');

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $f = __DIR__ . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($f)) {
            require $f;
        }
    }
});

/** Reads ROOT/.env (KEY=value lines) once. */
function gebp_env_all(): array
{
    static $vars = null;
    if ($vars === null) {
        $vars = [];
        $file = ROOT_PATH . '/.env';
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || ! str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $v = trim($v);
                if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) {
                    $v = substr($v, 1, -1);
                }
                $vars[strtoupper(trim($k))] = $v;
            }
        }
    }

    return $vars;
}

/** env('jwt.secret') and env('JWT_SECRET') read the same setting (JWT_SECRET in .env). */
function env(string $key, $default = null)
{
    $k = strtoupper(str_replace('.', '_', $key));
    $v = gebp_env_all()[$k] ?? getenv($k);

    return ($v === false || $v === null || $v === '') ? $default : $v;
}

define('ENVIRONMENT', (string) env('APP_ENV', 'production'));
date_default_timezone_set((string) env('APP_TIMEZONE', 'Asia/Manila'));

function log_message(string $level, string $message): void
{
    $dir = STORAGE_PATH . '/logs';
    if (! is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', date('c') . " [$level] $message\n", FILE_APPEND | LOCK_EX);
}

/** Public base URL of this API (used in signed file links). */
function base_url(string $path = ''): string
{
    $b = (string) env('APP_URL', '');
    if ($b === '') {
        $https = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $dir   = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        $b     = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir;
    }

    return rtrim($b, '/') . '/' . ltrim($path, '/');
}
