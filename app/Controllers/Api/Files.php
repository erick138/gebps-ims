<?php

namespace App\Controllers\Api;

use App\Libraries\FileStore;
use App\Controllers\Api\BaseApiController as Controller;

/** GET /api/v1/files?b=&p=&e=&s=  — serves a private file when the signed, expiring link is valid. */
class Files extends Controller
{
    public function serve()
    {
        $q   = $this->request->getGet();
        $b   = (string) ($q['b'] ?? '');
        $p   = (string) ($q['p'] ?? '');
        $e   = (int) ($q['e'] ?? 0);
        $s   = (string) ($q['s'] ?? '');
        $bad = $this->response->setStatusCode(404)->setJSON(['error' => 'File not found or the link has expired.']);

        try {
            if (! FileStore::checkSignature($b, $p, $e, $s)) {
                return $bad;
            }
            $bytes = FileStore::read($b, $p);
        } catch (\Throwable) {
            return $bad;
        }
        if ($bytes === null) {
            return $bad;
        }

        return $this->response
            ->setStatusCode(200)
            ->setHeader('Content-Type', FileStore::mimeForPath($p))
            ->setHeader('Content-Disposition', 'inline')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Content-Security-Policy', "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox")
            ->setHeader('Cache-Control', 'private, max-age=60')
            ->setBody($bytes);
    }
}
