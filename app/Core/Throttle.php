<?php

namespace App\Core;

/** Tiny file-based rate limiter: at most $max hits per $seconds for a key. */
class Throttle
{
    public static function check(string $key, int $max, int $seconds = 60): bool
    {
        $dir = STORAGE_PATH . '/cache';
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file = $dir . '/throttle-' . md5($key) . '.json';
        $fh   = @fopen($file, 'c+');
        if (! $fh) {
            return true; // never lock people out because the cache folder is not writable
        }
        flock($fh, LOCK_EX);
        $d   = json_decode((string) stream_get_contents($fh), true) ?: [];
        $now = time();
        $hits = array_values(array_filter($d['hits'] ?? [], fn ($t) => $t > $now - $seconds));
        $ok   = count($hits) < $max;
        if ($ok) {
            $hits[] = $now;
        }
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode(['hits' => $hits]));
        flock($fh, LOCK_UN);
        fclose($fh);
        if (random_int(1, 50) === 1) {
            foreach (glob($dir . '/throttle-*.json') ?: [] as $f) {
                if (filemtime($f) < $now - 3600) {
                    @unlink($f);
                }
            }
        }

        return $ok;
    }
}
