<?php

namespace App\Libraries;

/**
 * Thin helper over PDO (MySQL / MariaDB, InnoDB).
 *
 * - Positional "?" binds through real prepared statements.
 * - The session time zone is pinned to UTC, so every DATETIME column holds UTC. Rows leave this
 *   class with *_at columns already converted to ISO-8601 ("2026-11-14T07:21:04Z").
 * - DATE columns (issued_date, due_date ...) are plain school-calendar dates, with no time zone.
 *   Use Db::today() for "today" so it follows the school's time zone (APP_TIMEZONE, Asia/Manila).
 * - MySQL has no RETURNING and no triggers are used (shared hosts often forbid them), so numbers
 *   such as property numbers, barcodes and issuance references are generated here in PHP.
 * - Every value comes back as a string (like the older mysqli layer), so the JSON stays the same.
 */
class Db
{
    private static ?\PDO $pdo = null;
    private static int $depth = 0;

    public static function conn(): \PDO
    {
        if (self::$pdo === null) {
            $dsn = 'mysql:host=' . env('db.host', 'localhost') . ';port=' . env('db.port', 3306)
                 . ';dbname=' . env('db.name', '') . ';charset=utf8mb4';
            $sock = env('db.socket');
            if ($sock) {
                $dsn = 'mysql:unix_socket=' . $sock . ';dbname=' . env('db.name', '') . ';charset=utf8mb4';
            }
            self::$pdo = new \PDO($dsn, (string) env('db.user', ''), (string) env('db.pass', ''), [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES   => false,
                \PDO::ATTR_STRINGIFY_FETCHES  => true,
                \PDO::MYSQL_ATTR_FOUND_ROWS   => true,
                \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4, time_zone = '+00:00', "
                    . "sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'",
            ]);
        }

        return self::$pdo;
    }

    /** Runs one statement with positional binds. */
    private static function exec(string $sql, array $binds = []): \PDOStatement
    {
        $st = self::conn()->prepare($sql);
        $i  = 1;
        foreach ($binds as $v) {
            if ($v === null) {
                $st->bindValue($i, null, \PDO::PARAM_NULL);
            } elseif (is_bool($v)) {
                $st->bindValue($i, $v ? 1 : 0, \PDO::PARAM_INT);
            } elseif (is_int($v)) {
                $st->bindValue($i, $v, \PDO::PARAM_INT);
            } else {
                $st->bindValue($i, (string) $v, \PDO::PARAM_STR);
            }
            $i++;
        }
        $st->execute();

        return $st;
    }

    /** Today's date on the school calendar (Y-m-d). */
    public static function today(string $modify = ''): string
    {
        $d = new \DateTime('now', new \DateTimeZone(env('APP_TIMEZONE', 'Asia/Manila')));
        if ($modify !== '') {
            $d->modify($modify);
        }

        return $d->format('Y-m-d');
    }

    /** The school time zone's offset from UTC, in seconds (used to turn UTC timestamps into local dates in SQL). */
    public static function offsetSeconds(): int
    {
        return (new \DateTime('now', new \DateTimeZone(env('APP_TIMEZONE', 'Asia/Manila'))))->getOffset();
    }

    /** Current UTC time in MySQL DATETIME format. */
    public static function nowUtc(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /** Reads a TINYINT(1) / boolean value correctly ('1', '0', 1, true ...). */
    public static function bool(mixed $v): bool
    {
        return $v === true || $v === 't' || $v === 'true' || $v === '1' || $v === 1;
    }

    /** Converts the named columns of a row (or a list of rows) to real booleans. */
    public static function bools(?array $row, array $cols): ?array
    {
        if ($row === null) {
            return null;
        }
        if (isset($row[0]) && is_array($row[0])) {
            return array_map(fn ($r) => self::bools($r, $cols), $row);
        }
        foreach ($cols as $c) {
            if (array_key_exists($c, $row)) {
                $row[$c] = self::bool($row[$c]);
            }
        }

        return $row;
    }

    /** "2026-11-14 07:21:04" in a *_at column becomes "2026-11-14T07:21:04Z". */
    private static function fix(array $row): array
    {
        foreach ($row as $k => $v) {
            if (is_string($v) && str_ends_with($k, '_at') && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $v)) {
                $row[$k] = str_replace(' ', 'T', $v) . 'Z';
            }
        }

        return $row;
    }

    public static function all(string $sql, array $binds = []): array
    {
        return array_map([self::class, 'fix'], self::exec($sql, $binds)->fetchAll());
    }

    public static function one(string $sql, array $binds = []): ?array
    {
        $row = self::exec($sql, $binds)->fetch();

        return $row ? self::fix($row) : null;
    }

    public static function value(string $sql, array $binds = [])
    {
        $row = self::exec($sql, $binds)->fetch();

        return $row ? array_values($row)[0] : null;
    }

    public static function run(string $sql, array $binds = []): int
    {
        return self::exec($sql, $binds)->rowCount();
    }

    private static function ident(string $name): string
    {
        if (! preg_match('/^[a-z_][a-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException("Bad identifier: $name");
        }

        return '`' . $name . '`';
    }

    /** Next integer in a counter series. Atomic: the row stays locked until the transaction ends. */
    public static function nextInt(string $prefix): int
    {
        self::exec(
            'INSERT INTO doc_counters (prefix, last_number) VALUES (?, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1)',
            [$prefix]
        );

        return (int) self::value('SELECT LAST_INSERT_ID()');
    }

    /** nextNo('GEB-2026-IT-') => 'GEB-2026-IT-0001' */
    public static function nextNo(string $prefix, int $pad = 4): string
    {
        return $prefix . str_pad((string) self::nextInt($prefix), $pad, '0', STR_PAD_LEFT);
    }

    /** Fills columns the old database triggers used to fill. */
    private static function beforeInsert(string $table, array $data): array
    {
        if ($table === 'equipment') {
            if (empty($data['acquisition_date'])) {
                $data['acquisition_date'] = self::today();
            }
            if (! isset($data['property_no']) || $data['property_no'] === '') {
                $year = substr((string) $data['acquisition_date'], 0, 4);
                $code = (string) (self::value('SELECT code FROM categories WHERE id = ?', [$data['category_id'] ?? 0]) ?: 'GEN');
                $data['property_no'] = self::nextNo("GEB-$year-$code-");
            }
            if (! isset($data['barcode']) || $data['barcode'] === '') {
                $data['barcode'] = '4800' . str_pad((string) self::nextInt('BARCODE'), 8, '0', STR_PAD_LEFT);
            }
            $data['department_id'] = self::value('SELECT department_id FROM rooms WHERE id = ?', [$data['room_id'] ?? 0]);
        }
        if ($table === 'maintenance' && empty($data['reported_date'])) {
            $data['reported_date'] = self::today();
        }
        if ($table === 'issuances' && (! isset($data['ref_no']) || $data['ref_no'] === '')) {
            $data['ref_no'] = self::nextNo('ISS-' . substr(self::today(), 0, 4) . '-');
        }

        return $data;
    }

    /** INSERT, then read the row back (MySQL has no RETURNING). */
    public static function insert(string $table, array $data): array
    {
        $data = self::beforeInsert($table, $data);
        $cols = array_map([self::class, 'ident'], array_keys($data));
        $sql  = 'INSERT INTO ' . self::ident($table) . ' (' . implode(',', $cols) . ') VALUES ('
              . implode(',', array_fill(0, count($data), '?')) . ')';
        self::exec($sql, array_values($data));
        $id = (int) self::conn()->lastInsertId();

        return $id > 0 ? (self::one('SELECT * FROM ' . self::ident($table) . ' WHERE id = ?', [$id]) ?? []) : [];
    }

    /** UPDATE ... WHERE id = ?, then read the row back. Returns null when the row does not exist. */
    public static function update(string $table, int|string $id, array $data): ?array
    {
        if ($table === 'equipment' && array_key_exists('room_id', $data)) {
            $data['department_id'] = self::value('SELECT department_id FROM rooms WHERE id = ?', [$data['room_id']]);
        }
        if ($data !== []) {
            $sets = array_map(fn ($c) => self::ident($c) . ' = ?', array_keys($data));
            self::run('UPDATE ' . self::ident($table) . ' SET ' . implode(', ', $sets) . ' WHERE id = ?', [...array_values($data), $id]);
        }

        return self::one('SELECT * FROM ' . self::ident($table) . ' WHERE id = ?', [$id]);
    }

    public static function delete(string $table, int|string $id): int
    {
        return self::run('DELETE FROM ' . self::ident($table) . ' WHERE id = ?', [$id]);
    }

    /** Runs $fn inside a transaction (nested calls join the outer one); rolls back and rethrows on any error. */
    public static function transaction(callable $fn)
    {
        $c = self::conn();
        if (self::$depth++ === 0) {
            $c->beginTransaction();
        }
        try {
            $result = $fn();
            if (--self::$depth === 0) {
                $c->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if (--self::$depth === 0 && $c->inTransaction()) {
                $c->rollBack();
            }

            throw $e;
        }
    }
}
