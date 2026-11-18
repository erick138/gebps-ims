<?php

namespace App\Controllers\Api;

use App\Libraries\Activity;
use App\Libraries\ApiException;
use App\Libraries\Db;
use App\Libraries\InventoryService;
use App\Libraries\Rbac;
use App\Libraries\FileStore;

/** Equipment requests with supporting documents (memo, quotation, photo) attached for verification. */
class Requests extends BaseApiController
{
    private const MAX_FILES = 5;

    private function bucket(): string
    {
        return 'request-attachments';
    }

    public function index()
    {
        $u = $this->user();
        $w = Rbac::can($u['role'], 'approve') ? '' : ' WHERE r.requested_by = ' . (int) $u['id'];

        $rows = Db::all(
            "SELECT r.*, u.name AS requester, d.name AS department_name, dec.name AS decided_by_name,
                    (SELECT COUNT(*) FROM request_attachments a WHERE a.request_id = r.id) AS attachment_count
             FROM equipment_requests r JOIN users u ON u.id = r.requested_by LEFT JOIN departments d ON d.id = r.department_id
             LEFT JOIN users dec ON dec.id = r.decided_by$w ORDER BY (r.status = 'Pending') DESC, r.id DESC LIMIT 1000"
        );
        $by = [];
        if ($rows) {
            $ids = array_column($rows, 'id');
            foreach (Db::all('SELECT id, request_id, file_name, mime_type, size_bytes FROM request_attachments WHERE request_id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY id') as $a) {
                $by[$a['request_id']][] = ['id' => $a['id'], 'file_name' => $a['file_name'], 'mime_type' => $a['mime_type'], 'size_bytes' => $a['size_bytes']];
            }
        }
        foreach ($rows as &$r) {
            $r['attachments'] = $by[$r['id']] ?? [];
        }
        unset($r);

        return $rows;
    }

    private function load(int $id): array
    {
        $r = Db::one('SELECT * FROM equipment_requests WHERE id = ?', [$id]) ?? throw new ApiException('Request not found.', 404);
        $u = $this->user();
        if (! Rbac::can($u['role'], 'approve') && (int) $r['requested_by'] !== (int) $u['id']) {
            throw new ApiException('Request not found.', 404);
        }

        return $r;
    }

    public function show($id)
    {
        $r = $this->load($this->id($id));
        $r['attachments'] = Db::all('SELECT id, file_name, mime_type, size_bytes, created_at FROM request_attachments WHERE request_id = ? ORDER BY id', [$r['id']]);

        return $r;
    }

    /** POST /requests — JSON, or multipart with files[] attached in the same call. */
    public function create()
    {
        $u  = $this->user();
        $in = $this->input() ?: $this->request->getPost();
        $item   = trim((string) ($in['item'] ?? ''));
        $reason = trim((string) ($in['reason'] ?? ''));
        $qty    = (int) ($in['qty'] ?? 1);
        $err    = [];
        if ($item === '' || mb_strlen($item) > 160) {
            $err['item'] = 'Describe the item you need.';
        }
        if ($reason === '' || mb_strlen($reason) > 2000) {
            $err['reason'] = $reason === '' ? 'Give a reason for the request.' : 'Too long (max 2000).';
        }
        if ($qty < 1 || $qty > 1000) {
            $err['qty'] = 'Quantity must be between 1 and 1000.';
        }
        $needed = InventoryService::date($in['needed_by'] ?? null, 'needed_by');
        $files  = $this->incomingFiles();
        if ($err) {
            throw new ApiException('Please fix the highlighted fields.', 422, $err);
        }

        $r = Db::transaction(function () use ($u, $in, $item, $reason, $qty, $needed, $files) {
            $r = Db::insert('equipment_requests', [
                'item' => $item, 'qty' => $qty, 'reason' => $reason, 'needed_by' => $needed, 'requested_by' => $u['id'],
                'department_id' => ! empty($in['department_id']) ? (int) $in['department_id'] : ($u['department_id'] ?? null),
            ]);
            foreach ($files as $f) {
                $this->store($r['id'], $f, $u);
            }

            return $r;
        });
        Activity::log($u, 'request', "Equipment request submitted: $item.");

        return $this->ok($r, 201);
    }

    /** @return array<int, array{name:string, bytes:string}> */
    private function incomingFiles(): array
    {
        $out = [];
        foreach ($this->request->getFiles()['files'] ?? [] as $f) {
            if ($f->isValid()) {
                $out[] = ['name' => $f->getClientName(), 'bytes' => (string) file_get_contents($f->getTempName())];
            }
        }
        foreach ((array) ($this->input()['attachments'] ?? []) as $a) {
            $raw = base64_decode(preg_replace('#^data:[^;]+;base64,#', '', (string) ($a['data'] ?? '')), true);
            if ($raw) {
                $out[] = ['name' => (string) ($a['name'] ?? 'attachment'), 'bytes' => $raw];
            }
        }
        if (count($out) > self::MAX_FILES) {
            throw new ApiException('You can attach up to ' . self::MAX_FILES . ' files.');
        }

        return $out;
    }

    private function store(int $requestId, array $f, array $u): array
    {
        $mime = FileStore::sniff($f['bytes']);
        if (! $mime) {
            throw new ApiException('Attachments must be JPG, PNG, WebP or PDF files.', 415);
        }
        if (strlen($f['bytes']) > FileStore::MAX_BYTES) {
            throw new ApiException('Each attachment must be 5 MB or smaller.', 413);
        }
        $path = FileStore::put($this->bucket(), 'requests/' . $requestId, $f['bytes'], $mime);
        $name = preg_replace('/[^\w.\- ()]+/u', '_', basename($f['name'])) ?: 'attachment';

        return Db::insert('request_attachments', [
            'request_id' => $requestId, 'file_name' => mb_substr($name, 0, 150), 'mime_type' => $mime,
            'size_bytes' => strlen($f['bytes']), 'storage_path' => $path, 'uploaded_by' => $u['id'],
        ]);
    }

    /** POST /requests/{id}/attachments — only while the request is pending. */
    public function addAttachments($id)
    {
        $r = $this->load($this->id($id));
        if ($r['status'] !== 'Pending') {
            throw new ApiException('Files can only be added to pending requests.');
        }
        $have  = (int) Db::value('SELECT COUNT(*) FROM request_attachments WHERE request_id = ?', [$r['id']]);
        $files = $this->incomingFiles();
        if (! $files) {
            throw new ApiException('No file received.', 400);
        }
        if ($have + count($files) > self::MAX_FILES) {
            throw new ApiException('A request can have at most ' . self::MAX_FILES . ' attachments.');
        }
        $saved = [];
        foreach ($files as $f) {
            $a = $this->store((int) $r['id'], $f, $this->user());
            unset($a['storage_path']);
            $saved[] = $a;
        }

        return $this->ok($saved, 201);
    }

    /** GET /requests/{id}/attachments/{aid} — returns a 5-minute signed URL (never the raw storage path). */
    public function attachment($id, $aid)
    {
        $r = $this->load($this->id($id));
        $a = Db::one('SELECT * FROM request_attachments WHERE id = ? AND request_id = ?', [$this->id($aid), $r['id']]) ?? throw new ApiException('Attachment not found.', 404);
        $url = FileStore::signedUrl($this->bucket(), $a['storage_path'], 300) ?? throw new ApiException('Could not open the file.', 502);

        return ['url' => $url, 'file_name' => $a['file_name'], 'mime_type' => $a['mime_type'], 'expires_in' => 300];
    }

    public function removeAttachment($id, $aid)
    {
        $r = $this->load($this->id($id));
        if ($r['status'] !== 'Pending') {
            throw new ApiException('Files cannot be removed after a decision.');
        }
        $a = Db::one('SELECT * FROM request_attachments WHERE id = ? AND request_id = ?', [$this->id($aid), $r['id']]) ?? throw new ApiException('Attachment not found.', 404);
        Db::delete('request_attachments', (int) $a['id']);
        FileStore::remove($this->bucket(), $a['storage_path']);

        return ['deleted' => true];
    }

    /** POST /requests/{id}/decision  {status: Approved|Rejected, remarks} — route requires the approve permission. */
    public function decision($id)
    {
        $in = $this->input();

        return InventoryService::decideRequest($this->id($id), (string) ($in['status'] ?? ''), (string) ($in['remarks'] ?? ''), $this->user());
    }

    public function delete($id)
    {
        $r = $this->load($this->id($id));
        if ($r['status'] !== 'Pending') {
            throw new ApiException('Only pending requests can be withdrawn.');
        }
        foreach (Db::all('SELECT storage_path FROM request_attachments WHERE request_id = ?', [$r['id']]) as $a) {
            FileStore::remove($this->bucket(), $a['storage_path']);
        }
        Db::delete('equipment_requests', (int) $r['id']);
        Activity::log($this->user(), 'request', 'Equipment request withdrawn: ' . $r['item'] . '.');

        return ['deleted' => true];
    }
}
