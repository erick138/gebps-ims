<?php

namespace App\Core;

use App\Libraries\CurrentUser;
use App\Libraries\Db;
use App\Libraries\Jwt;
use App\Libraries\Rbac;

/** Front controller logic: CORS, routing, the auth / permission filters, dispatch. */
class Kernel
{
    public static Request $request;

    public static function run(): void
    {
        self::$request = new Request();
        $res = self::handle(self::$request);
        $res = self::cors($res);
        $res->send();
    }

    private static function json(array $data, int $status): Response
    {
        return (new Response())->setStatusCode($status)->setJSON($data);
    }

    private static function cors(Response $res): Response
    {
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        $list   = array_filter(array_map('trim', explode(',', (string) env('cors.origins', ''))));
        if ($origin !== '' && in_array($origin, $list, true)) {
            $res->setHeader('Access-Control-Allow-Origin', $origin)
                ->setHeader('Vary', 'Origin')
                ->setHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type')
                ->setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
                ->setHeader('Access-Control-Max-Age', '600');
        }

        return $res->setHeader('X-Content-Type-Options', 'nosniff');
    }

    private static function handle(Request $req): Response
    {
        if ($req->method() === 'OPTIONS') {
            return (new Response())->setStatusCode(204);
        }

        $router = new Router();
        require __DIR__ . '/../routes.php';

        $path = $req->path();
        if ($path === '' || $path === 'api' || $path === 'api/v1') {
            $path = 'api/v1/health';
        }
        $hit = $router->match($req->method(), $path);
        if (! $hit) {
            return self::json(['error' => 'Not found.'], 404);
        }
        [$class, $action, $params, $filters] = $hit;

        foreach ($filters as $f) {
            $deny = $f === 'auth' ? self::auth($req) : self::perm(substr($f, 5));
            if ($deny) {
                return $deny;
            }
        }

        $cls = 'App\\Controllers\\Api\\' . $class;
        if (! class_exists($cls)) {
            return self::json(['error' => 'Not found.'], 404);
        }

        return (new $cls())->_remap($action, ...$params);
    }

    /** Requires a valid bearer token and a still-active account. The role is read from the database, not the token. */
    private static function auth(Request $req): ?Response
    {
        if (! preg_match('/^Bearer\s+(\S+)$/i', $req->getHeaderLine('Authorization'), $m)) {
            return self::json(['error' => 'Sign in required.'], 401);
        }
        try {
            $claims = Jwt::verify($m[1]);
        } catch (\Throwable $e) {
            return self::json(['error' => 'Your session has expired. Please sign in again.'], 401);
        }

        try {
            $u = Db::one('SELECT id, username, email, name, role, status, position, department_id, personnel_id, phone, sms_opt_in, must_change_password FROM users WHERE id = ?', [(int) $claims->sub]);
        } catch (\Throwable $e) {
            log_message('error', 'Auth lookup failed: ' . $e->getMessage());

            return self::json(['error' => 'Database error.'], 500);
        }
        if (! $u || $u['status'] !== 'active') {
            return self::json(['error' => 'This account is not active.'], 401);
        }
        CurrentUser::set($u);

        // A flagged account may only change its password until it does.
        $path = $req->path();
        if (Db::bool($u['must_change_password']) && ! str_ends_with($path, 'auth/change-password') && ! str_ends_with($path, 'auth/me')) {
            return self::json(['error' => 'Change your password first.', 'code' => 'must_change_password'], 403);
        }

        return null;
    }

    private static function perm(string $perm): ?Response
    {
        $u = CurrentUser::get();
        if (! $u || ! Rbac::can($u['role'], $perm)) {
            return self::json(['error' => 'You do not have permission to do that.'], 403);
        }

        return null;
    }
}
