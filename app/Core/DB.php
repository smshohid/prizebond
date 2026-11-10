<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

/** Thin PDO wrapper. All queries are prepared statements; timestamps are UTC. */
final class DB
{
    private static ?PDO $pdo = null;
    private static int $depth = 0;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                env('DB_HOST', '127.0.0.1'), env('DB_PORT', '3306'), env('DB_DATABASE', 'prizebond'));
            self::$pdo = self::connect($dsn, (string) env('DB_USERNAME', 'root'), (string) env('DB_PASSWORD', ''));
        }
        return self::$pdo;
    }

    public static function connect(string $dsn, string $user, string $pass): PDO
    {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00', sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        return $pdo;
    }

    public static function set(?PDO $pdo): void
    {
        self::$pdo = $pdo;
        self::$depth = 0;
    }

    public static function q(string $sql, array $params = []): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $i = 1;
        foreach ($params as $k => $v) {
            $name = is_int($k) ? $i++ : $k;
            $type = match (true) {
                is_int($v) => PDO::PARAM_INT,
                is_bool($v) => PDO::PARAM_INT,
                $v === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $st->bindValue($name, is_bool($v) ? (int) $v : $v, $type);
        }
        $st->execute();
        return $st;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::q($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $r = self::q($sql, $params)->fetch();
        return $r === false ? null : $r;
    }

    public static function val(string $sql, array $params = []): mixed
    {
        $r = self::q($sql, $params)->fetchColumn();
        return $r === false ? null : $r;
    }

    public static function exec(string $sql, array $params = []): int
    {
        return self::q($sql, $params)->rowCount();
    }

    public static function insert(string $table, array $data): int
    {
        $cols = implode(',', array_map(fn($c) => "`$c`", array_keys($data)));
        $ph = implode(',', array_fill(0, count($data), '?'));
        self::q("INSERT INTO `$table` ($cols) VALUES ($ph)", array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $set = implode(',', array_map(fn($c) => "`$c` = ?", array_keys($data)));
        return self::exec("UPDATE `$table` SET $set WHERE $where", [...array_values($data), ...$whereParams]);
    }

    /** Nested-safe transaction: only the outermost call commits. */
    public static function tx(callable $fn): mixed
    {
        $pdo = self::pdo();
        $outer = self::$depth === 0;
        if ($outer) {
            $pdo->beginTransaction();
        }
        self::$depth++;
        try {
            $res = $fn();
            self::$depth--;
            if ($outer) {
                $pdo->commit();
            }
            return $res;
        } catch (\Throwable $e) {
            self::$depth--;
            if ($outer && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    public static function placeholders(int $n): string
    {
        return implode(',', array_fill(0, $n, '?'));
    }
}
