<?php

namespace App\Controllers\Api;

use App\Libraries\ApiException;
use App\Libraries\CurrentUser;
use App\Core\Controller;
use App\Core\Response;

/**
 * All API controllers extend this. _remap() wraps every action so thrown ApiExceptions and
 * database constraint errors become clean JSON responses instead of stack traces.
 */
abstract class BaseApiController extends Controller
{
    public function _remap($method, ...$params)
    {
        try {
            if (! method_exists($this, $method)) {
                throw new ApiException('Not found.', 404);
            }
            $out = $this->{$method}(...$params);

            return $out instanceof Response ? $out : $this->ok($out);
        } catch (ApiException $e) {
            return $this->error($e->getMessage(), $e->status, $e->details);
        } catch (\PDOException $e) {
            return $this->dbError($e);
        } catch (\Throwable $e) {
            log_message('error', $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

            return $this->error(ENVIRONMENT === 'production' ? 'Something went wrong.' : $e->getMessage(), 500);
        }
    }

    protected function user(): array
    {
        return CurrentUser::require();
    }

    protected function ok($data = null, int $status = 200)
    {
        return $this->response->setStatusCode($status)->setJSON(['data' => $data]);
    }

    protected function error(string $msg, int $status = 422, array $details = [])
    {
        return $this->response->setStatusCode($status)->setJSON(['error' => $msg] + ($details ? ['details' => $details] : []));
    }

    private function dbError(\Throwable $e)
    {
        $m = $e->getMessage();
        if (stripos($m, 'duplicate entry') !== false) {
            return $this->error('That value already exists.', 409);
        }
        if (stripos($m, 'foreign key') !== false) {
            return $this->error('This record is still in use by other records, so it cannot be changed or removed.', 409);
        }
        if (stripos($m, 'data too long') !== false) {
            return $this->error('One of the values is too long.', 422);
        }
        if (stripos($m, 'check constraint') !== false || stripos($m, 'cannot be null') !== false || stripos($m, 'incorrect') !== false
            || stripos($m, 'data truncated') !== false || stripos($m, "doesn't have a default") !== false || stripos($m, 'out of range') !== false) {
            return $this->error('One of the values is not allowed.', 422);
        }
        log_message('error', 'DB: ' . $m);

        return $this->error('Database error.', 500);
    }

    /** JSON body, falling back to form fields. */
    protected function input(): array
    {
        $j = $this->request->getJSON(true);

        return is_array($j) ? $j : ($this->request->getPost() ?: []);
    }

    protected function id($v): int
    {
        if (! ctype_digit((string) $v)) {
            throw new ApiException('Invalid id.', 400);
        }

        return (int) $v;
    }

    protected function paging(): array
    {
        $per  = max(1, min(200, (int) ($this->request->getGet('per_page') ?? 50)));
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));

        return [$per, ($page - 1) * $per, $page];
    }
}
