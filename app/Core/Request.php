<?php

namespace App\Core;

/** The current HTTP request, built from PHP's globals. */
class Request
{
    private ?array $json = null;
    private bool $jsonRead = false;

    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /** Path below the folder that holds index.php, e.g. "api/v1/equipment". */
    public function path(): string
    {
        $uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $uri  = rawurldecode($uri);
        $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        if ($base !== '' && str_starts_with($uri, $base . '/')) {
            $uri = substr($uri, strlen($base));
        }
        $uri = preg_replace('#^/index\.php#', '', $uri);

        return trim($uri, '/');
    }

    public function getHeaderLine(string $name): string
    {
        $k = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$k])) {
            return (string) $_SERVER[$k];
        }
        if (strtolower($name) === 'authorization') {
            // Some Apache/CGI set-ups drop the header; the .htaccess puts it in REDIRECT_HTTP_AUTHORIZATION.
            return (string) ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? (function_exists('getallheaders') ? (getallheaders()['Authorization'] ?? '') : ''));
        }

        return '';
    }

    public function getGet(?string $key = null)
    {
        return $key === null ? $_GET : ($_GET[$key] ?? null);
    }

    public function getPost(?string $key = null)
    {
        return $key === null ? $_POST : ($_POST[$key] ?? null);
    }

    /** The JSON body as an array (or null when the body is empty / not JSON). */
    public function getJSON(bool $assoc = true)
    {
        if (! $this->jsonRead) {
            $this->jsonRead = true;
            $raw            = file_get_contents('php://input');
            $j              = $raw !== '' && $raw !== false ? json_decode($raw, true) : null;
            $this->json     = is_array($j) ? $j : null;
        }

        return $this->json;
    }

    public function getIPAddress(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function getFile(string $name): ?UploadedFile
    {
        $f = $_FILES[$name] ?? null;

        return is_array($f) && ! is_array($f['name']) ? new UploadedFile($f) : null;
    }

    /** ['files' => [UploadedFile, ...]] for <input name="files[]" multiple>. */
    public function getFiles(): array
    {
        $out = [];
        foreach ($_FILES as $field => $f) {
            if (is_array($f['name'])) {
                foreach (array_keys($f['name']) as $i) {
                    $out[$field][] = new UploadedFile(['name' => $f['name'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]]);
                }
            } else {
                $out[$field][] = new UploadedFile($f);
            }
        }

        return $out;
    }
}
