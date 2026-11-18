<?php

namespace App\Controllers\Api;

use App\Libraries\Activity;
use App\Libraries\ApiException;
use App\Libraries\Db;
use App\Libraries\InventoryService;

/**
 * Declarative CRUD. A subclass sets $table and $fields; write access is enforced by the route filter.
 * Field spec: ['type' => string|int|num|date|bool|enum|fk, 'required' => bool, 'max' => int, 'values' => [...], 'min' => n]
 */
abstract class CrudController extends BaseApiController
{
    protected string $table;
    protected string $label = 'Record';
    protected array $fields = [];
    protected string $listSql = '';
    protected string $orderBy = 'id';

    public function index()
    {
        return Db::all($this->listSql ?: 'SELECT * FROM `' . $this->table . '` ORDER BY ' . $this->orderBy);
    }

    public function show($id)
    {
        return Db::one('SELECT * FROM `' . $this->table . '` WHERE id = ?', [$this->id($id)]) ?? throw new ApiException($this->label . ' not found.', 404);
    }

    public function create()
    {
        $row = Db::insert($this->table, $this->clean($this->input(), true));
        Activity::log($this->user(), 'data', $this->label . ' added: ' . $this->describe($row));

        return $this->ok($this->after($row), 201);
    }

    public function update($id)
    {
        $id = $this->id($id);
        $this->show($id);
        $row = Db::update($this->table, $id, $this->clean($this->input(), false));
        Activity::log($this->user(), 'data', $this->label . ' updated: ' . $this->describe($row));

        return $this->after($row);
    }

    public function delete($id)
    {
        $row = $this->show($this->id($id));
        $this->beforeDelete($row);
        Db::delete($this->table, (int) $row['id']);
        Activity::log($this->user(), 'data', $this->label . ' deleted: ' . $this->describe($row));

        return ['deleted' => true];
    }

    protected function describe(array $row): string
    {
        return (string) ($row['name'] ?? $row['id']);
    }

    protected function after(array $row): array
    {
        return $row;
    }

    protected function beforeDelete(array $row): void
    {
    }

    /** Validates and casts. On update only supplied fields are touched. */
    protected function clean(array $in, bool $creating): array
    {
        $out = [];
        $err = [];
        foreach ($this->fields as $name => $spec) {
            $present = array_key_exists($name, $in);
            if (! $present) {
                if ($creating && ! empty($spec['required'])) {
                    $err[$name] = 'Required.';
                }

                continue;
            }
            $v    = $in[$name];
            $type = $spec['type'] ?? 'string';
            $empty = $v === null || (is_string($v) && trim($v) === '');
            if ($empty) {
                if (! empty($spec['required'])) {
                    $err[$name] = 'Required.';
                } else {
                    $out[$name] = in_array($type, ['string'], true) ? '' : ($type === 'bool' ? 0 : null);
                }

                continue;
            }

            try {
                $out[$name] = match ($type) {
                    'string' => $this->str($v, $spec),
                    'int'    => $this->num($v, $spec, true),
                    'num'    => $this->num($v, $spec, false),
                    'date'   => InventoryService::date($v, $name),
                    'bool'   => filter_var($v, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
                    'enum'   => in_array($v, $spec['values'], true) ? $v : throw new ApiException('Must be one of: ' . implode(', ', $spec['values'])),
                    'fk'     => ctype_digit((string) $v) ? (int) $v : throw new ApiException('Invalid reference.'),
                    default  => throw new \LogicException("Bad field type $type"),
                };
            } catch (ApiException $e) {
                $err[$name] = $e->getMessage();
            }
        }
        if ($err) {
            throw new ApiException('Please fix the highlighted fields.', 422, $err);
        }

        return $out;
    }

    private function str($v, array $spec): string
    {
        if (! is_scalar($v)) {
            throw new ApiException('Must be text.');
        }
        $v = trim((string) $v);
        if (mb_strlen($v) > ($spec['max'] ?? 255)) {
            throw new ApiException('Too long (max ' . ($spec['max'] ?? 255) . ').');
        }
        if (isset($spec['pattern']) && ! preg_match($spec['pattern'], $v)) {
            throw new ApiException($spec['hint'] ?? 'Invalid format.');
        }

        return $v;
    }

    private function num($v, array $spec, bool $int)
    {
        if (! is_numeric($v) || ($int && (string) (int) $v !== (string) ((int) (float) $v))) {
            throw new ApiException('Must be a number.');
        }
        $n = $int ? (int) $v : round((float) $v, 2);
        if ($n < ($spec['min'] ?? 0)) {
            throw new ApiException('Must be at least ' . ($spec['min'] ?? 0) . '.');
        }

        return $n;
    }
}
