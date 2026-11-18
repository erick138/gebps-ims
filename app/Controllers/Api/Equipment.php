<?php

namespace App\Controllers\Api;

use App\Libraries\Activity;
use App\Libraries\ApiException;
use App\Libraries\CurrentUser;
use App\Libraries\Db;
use App\Libraries\Rbac;
use App\Libraries\FileStore;

class Equipment extends CrudController
{
    protected string $table = 'equipment';
    protected string $label = 'Equipment';
    protected array $fields = [
        'property_no'      => ['max' => 40, 'pattern' => '/^[A-Za-z0-9][A-Za-z0-9\-\/._ ]*$/', 'hint' => 'Property number: letters, numbers, dash, slash, dot or space.'],
        'name'             => ['required' => true, 'max' => 160],
        'description'      => ['max' => 1000],
        'specs'            => ['max' => 1000],
        'brand'            => ['max' => 80],
        'model'            => ['max' => 80],
        'serial_no'        => ['max' => 80],
        'acquisition_date' => ['type' => 'date'],
        'funding_source'   => ['max' => 120],
        'category_id'      => ['type' => 'fk', 'required' => true],
        'condition'        => ['type' => 'enum', 'values' => ['New', 'Good', 'Fair', 'Poor']],
        'room_id'          => ['type' => 'fk', 'required' => true],
    ];

    protected function describe(array $row): string
    {
        return ($row['property_no'] ?? '') . ' ' . $row['name'];
    }

    /** GET /equipment?q=&status=&category_id=&department_id=&room_id=&condition=&page=&per_page= */
    public function index()
    {
        $u = $this->user();
        [$per, $off, $page] = $this->paging();
        $c = [];
        $b = [];
        if (! Rbac::can($u['role'], 'viewAll')) {
            $c[] = 'assigned_to = ?';
            $b[] = (int) ($u['personnel_id'] ?? 0);
        }
        foreach (['category_id', 'department_id', 'room_id'] as $k) {
            if ($this->request->getGet($k)) {
                $c[] = "$k = ?";
                $b[] = (int) $this->request->getGet($k);
            }
        }
        foreach (['status', 'condition'] as $k) {
            if ($this->request->getGet($k)) {
                $c[] = "`$k` = ?";
                $b[] = (string) $this->request->getGet($k);
            }
        }
        if (($q = trim((string) $this->request->getGet('q'))) !== '') {
            $c[] = '(property_no LIKE ? OR name LIKE ? OR serial_no LIKE ? OR barcode = ? OR brand LIKE ? OR assigned_name LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($b, $like, $like, $like, $q, $like, $like);
        }
        $w     = $c ? ' WHERE ' . implode(' AND ', $c) : '';
        $total = (int) Db::value("SELECT COUNT(*) FROM v_equipment$w", $b);
        $rows  = Db::all("SELECT * FROM v_equipment$w ORDER BY property_no LIMIT $per OFFSET $off", $b);

        return $this->response->setJSON(['data' => $rows, 'meta' => ['total' => $total, 'page' => $page, 'per_page' => $per]]);
    }

    private function checkPropertyNo(string $no, ?int $exceptId): void
    {
        if ($no !== '' && Db::one('SELECT 1 FROM equipment WHERE lower(property_no) = lower(?) AND (? IS NULL OR id <> ?)', [$no, $exceptId, $exceptId])) {
            throw new ApiException('That property number is already used by another item.', 422, ['property_no' => 'Already in use.']);
        }
    }

    private function canSee(array $e): bool
    {
        $u = $this->user();

        return Rbac::can($u['role'], 'viewAll') || ((int) $e['assigned_to'] === (int) ($u['personnel_id'] ?? 0) && $e['assigned_to'] !== null);
    }

    public function show($id)
    {
        $e = Db::one('SELECT * FROM v_equipment WHERE id = ?', [$this->id($id)]) ?? throw new ApiException('Equipment not found.', 404);
        if (! $this->canSee($e)) {
            throw new ApiException('Equipment not found.', 404);
        }
        $e['photo_url'] = $e['photo_path'] ? FileStore::signedUrl($this->bucket(), $e['photo_path']) : null;

        return $e;
    }

    /** GET /equipment/lookup?code=  — property no., barcode, serial or the QR payload (GEBPSOICS:<property no>). */
    public function lookup()
    {
        $code = trim(preg_replace('/^GEBPSOICS:/i', '', trim((string) $this->request->getGet('code'))));
        if ($code === '') {
            throw new ApiException('Provide a code.');
        }
        $e = Db::one('SELECT * FROM v_equipment WHERE lower(property_no) = lower(?) OR barcode = ? OR lower(serial_no) = lower(?) LIMIT 1', [$code, $code, $code]);
        if (! $e || ! $this->canSee($e)) {
            throw new ApiException('No equipment matches that code.', 404);
        }

        return $e;
    }

    public function create()
    {
        $d = $this->clean($this->input(), true);
        $this->checkPropertyNo($d['property_no'] ?? '', null);
        if (($d['property_no'] ?? '') === '') {
            unset($d['property_no']); // blank = the API numbers it (GEB-YYYY-CAT-NNNN)
        }
        $d['created_by'] = $this->user()['id'];
        $e = Db::insert('equipment', $d);
        Activity::log($this->user(), 'equipment', 'Equipment added: ' . $this->describe($e) . '.');

        return $this->ok(Db::one('SELECT * FROM v_equipment WHERE id = ?', [$e['id']]), 201);
    }

    public function update($id)
    {
        $id = $this->id($id);
        $e  = Db::one('SELECT * FROM equipment WHERE id = ?', [$id]) ?? throw new ApiException('Equipment not found.', 404);
        $in = $this->input();
        $d  = $this->clean($in, false);
        if (array_key_exists('property_no', $d)) {
            if ($d['property_no'] === '') {
                unset($d['property_no']); // never blank an existing number
            } else {
                $this->checkPropertyNo($d['property_no'], $id);
            }
        }
        if (isset($in['status']) && $in['status'] !== $e['status']) {
            if (! in_array($in['status'], ['Available', 'Defective', 'Condemned'], true)) {
                throw new ApiException('Status changes to Deployed and Under Repair happen through issuance and maintenance.');
            }
            if ($e['assigned_to'] !== null) {
                throw new ApiException('Return the equipment before changing its status.');
            }
            $d['status'] = $in['status'];
        }
        $row = Db::update('equipment', $id, $d);
        Activity::log($this->user(), 'equipment', 'Equipment updated: ' . $this->describe($row) . '.');

        return Db::one('SELECT * FROM v_equipment WHERE id = ?', [$id]);
    }

    protected function beforeDelete(array $row): void
    {
        if ((int) Db::value('SELECT COUNT(*) FROM issuances WHERE equipment_id = ?', [$row['id']]) > 0
            || (int) Db::value('SELECT COUNT(*) FROM maintenance WHERE equipment_id = ?', [$row['id']]) > 0) {
            throw new ApiException('This item has issuance or maintenance history. Mark it Condemned instead of deleting it.', 409);
        }
        if ($row['photo_path']) {
            FileStore::remove($this->bucket(), $row['photo_path']);
        }
    }

    private function bucket(): string
    {
        return 'equipment-photos';
    }

    /** POST /equipment/{id}/photo — multipart field "photo", or JSON {data: "<base64>"}. */
    public function photo($id)
    {
        $e = Db::one('SELECT * FROM equipment WHERE id = ?', [$this->id($id)]) ?? throw new ApiException('Equipment not found.', 404);
        $bytes = $this->uploadedBytes('photo');
        $mime  = FileStore::sniff($bytes);
        if (! $mime || $mime === 'application/pdf') {
            throw new ApiException('Photo must be a JPG, PNG or WebP image.', 415);
        }
        $path = FileStore::put($this->bucket(), 'equipment/' . $e['id'], $bytes, $mime);
        if ($e['photo_path']) {
            FileStore::remove($this->bucket(), $e['photo_path']);
        }
        Db::update('equipment', $e['id'], ['photo_path' => $path]);
        Activity::log($this->user(), 'equipment', 'Photo updated for ' . $this->describe($e) . '.');

        return ['photo_url' => FileStore::signedUrl($this->bucket(), $path)];
    }

    protected function uploadedBytes(string $field): string
    {
        $f = $this->request->getFile($field);
        if ($f && $f->isValid()) {
            return (string) file_get_contents($f->getTempName());
        }
        $b64 = (string) ($this->input()['data'] ?? '');
        $b64 = preg_replace('#^data:[^;]+;base64,#', '', $b64);
        $raw = $b64 !== '' ? base64_decode($b64, true) : false;
        if ($raw === false || $raw === '') {
            throw new ApiException('No file received.', 400);
        }

        return $raw;
    }

    /** GET /equipment/{id}/history — issuances, transfers, maintenance and activity for one item. */
    public function history($id)
    {
        $e = $this->show($id);

        return [
            'equipment'   => $e,
            'issuances'   => Db::all('SELECT i.*, p.name AS person FROM issuances i JOIN personnel p ON p.id = i.personnel_id WHERE i.equipment_id = ? ORDER BY i.id DESC', [$e['id']]),
            'transfers'   => Db::all('SELECT t.*, rf.name AS from_room, rt.name AS to_room FROM transfers t LEFT JOIN rooms rf ON rf.id = t.from_room_id JOIN rooms rt ON rt.id = t.to_room_id WHERE t.equipment_id = ? ORDER BY t.id DESC', [$e['id']]),
            'maintenance' => Db::all('SELECT m.*, u.name AS tech FROM maintenance m LEFT JOIN users u ON u.id = m.assigned_to WHERE m.equipment_id = ? ORDER BY m.id DESC', [$e['id']]),
        ];
    }

    /** GET /equipment/mine — what is assigned to me (Teacher / Staff view). */
    public function mine()
    {
        $pid = (int) ($this->user()['personnel_id'] ?? 0);

        return $pid ? Db::all('SELECT * FROM v_equipment WHERE assigned_to = ? ORDER BY property_no', [$pid]) : [];
    }
}
