<?php

namespace App\Libraries;

/**
 * Private file storage on the server's own disk (no outside service needed).
 *
 * Files live in  storage/uploads/<bucket>/...  which is outside the public web folder, so nobody can
 * open them by guessing an address. Browsers receive short-lived signed links that the API itself
 * checks (see Controllers/Api/Files.php). Set  storage.path  in .env to keep uploads elsewhere.
 */
class FileStore
{
    public const ALLOWED   = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const BUCKETS   = ['equipment-photos', 'request-attachments'];

    private const EXT = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

    public static function root(): string
    {
        $p = (string) env('storage.path', '');

        return rtrim($p !== '' ? $p : STORAGE_PATH . '/uploads', '/\\');
    }

    /** Detects the real type from the bytes, never from the client-supplied header. */
    public static function sniff(string $bytes): ?string
    {
        $m = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        return in_array($m, self::ALLOWED, true) ? $m : null;
    }

    public static function ext(string $mime): string
    {
        return self::EXT[$mime] ?? 'bin';
    }

    public static function mimeForPath(string $path): string
    {
        return array_flip(self::EXT)[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    }

    /** Only paths this class creates are accepted: <bucket>/<folder>/<id>/<32 hex>.<ext> */
    private static function valid(string $bucket, string $path): bool
    {
        return in_array($bucket, self::BUCKETS, true)
            && (bool) preg_match('#^[a-z]+/\d+/[a-f0-9]{32}\.(jpg|png|webp|pdf)$#', $path);
    }

    private static function full(string $bucket, string $path): string
    {
        if (! self::valid($bucket, $path)) {
            throw new ApiException('Invalid file reference.', 400);
        }

        return self::root() . '/' . $bucket . '/' . $path;
    }

    /** Stores bytes under a random name; returns the path inside the bucket. */
    public static function put(string $bucket, string $folder, string $bytes, string $mime): string
    {
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new ApiException('File is larger than 5 MB.', 413);
        }
        $path = trim($folder, '/') . '/' . bin2hex(random_bytes(16)) . '.' . self::ext($mime);
        $file = self::full($bucket, $path);
        $dir  = dirname($file);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new ApiException('Upload folder is not writable. Check the permissions of storage/uploads.', 503);
        }
        if (@file_put_contents($file, $bytes, LOCK_EX) === false) {
            throw new ApiException('Could not save the file. Check the permissions of storage/uploads.', 503);
        }

        return $path;
    }

    public static function read(string $bucket, string $path): ?string
    {
        $f = self::full($bucket, $path);

        return is_file($f) ? (string) file_get_contents($f) : null;
    }

    public static function remove(string $bucket, string $path): void
    {
        try {
            $f = self::full($bucket, $path);
        } catch (ApiException) {
            return;
        }
        if (is_file($f)) {
            @unlink($f);
        }
    }

    // ------------------------------------------------------------ signed links
    private static function sign(string $bucket, string $path, int $exp): string
    {
        $secret = (string) env('jwt.secret', '');
        if (strlen($secret) < 32) {
            throw new ApiException('jwt.secret is not configured.', 503);
        }

        return hash_hmac('sha256', "$bucket|$path|$exp", hash('sha256', 'file-link|' . $secret, true));
    }

    /** A link that opens the file without a sign-in header, valid for $ttl seconds. */
    public static function signedUrl(string $bucket, string $path, int $ttl = 300): ?string
    {
        if (! self::valid($bucket, $path)) {
            return null;
        }
        $exp = time() + $ttl;

        return base_url('api/v1/files') . '?' . http_build_query(['b' => $bucket, 'p' => $path, 'e' => $exp, 's' => self::sign($bucket, $path, $exp)]);
    }

    public static function checkSignature(string $bucket, string $path, int $exp, string $sig): bool
    {
        return $exp >= time() && self::valid($bucket, $path) && hash_equals(self::sign($bucket, $path, $exp), $sig);
    }
}
