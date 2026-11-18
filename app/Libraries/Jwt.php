<?php

namespace App\Libraries;

/** Minimal HS256 JSON Web Token (no Composer package needed). */
class Jwt
{
    private static function secret(): string
    {
        $s = (string) env('jwt.secret', '');
        if (strlen($s) < 32) {
            throw new \RuntimeException('JWT_SECRET must be set to at least 32 characters in .env');
        }

        return $s;
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function unb64(string $s): string
    {
        $r = base64_decode(strtr($s, '-_', '+/'), true);
        if ($r === false) {
            throw new \RuntimeException('Bad token.');
        }

        return $r;
    }

    public static function issue(array $user): string
    {
        $now  = time();
        $head = self::b64(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $body = self::b64(json_encode([
            'iss'  => 'gebpsoics-api',
            'sub'  => (int) $user['id'],
            'role' => $user['role'],
            'iat'  => $now,
            'exp'  => $now + (int) env('jwt.ttl', 28800), // 8 hours
        ]));

        return "$head.$body." . self::b64(hash_hmac('sha256', "$head.$body", self::secret(), true));
    }

    /** Returns the claims, or throws when the token is malformed, forged or expired. */
    public static function verify(string $token): object
    {
        $p = explode('.', $token);
        if (count($p) !== 3) {
            throw new \RuntimeException('Bad token.');
        }
        $h = json_decode(self::unb64($p[0]));
        if (! is_object($h) || ($h->alg ?? '') !== 'HS256') {
            throw new \RuntimeException('Bad token.');
        }
        if (! hash_equals(self::b64(hash_hmac('sha256', "$p[0].$p[1]", self::secret(), true)), $p[2])) {
            throw new \RuntimeException('Bad signature.');
        }
        $c = json_decode(self::unb64($p[1]));
        if (! is_object($c) || ! isset($c->exp, $c->sub) || (int) $c->exp < time()) {
            throw new \RuntimeException('Expired.');
        }

        return $c;
    }
}
