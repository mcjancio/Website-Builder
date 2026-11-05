<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

/**
 * Cienka nakładka na PDO. Tylko prepared statements; nazwy tabel/kolumn
 * w insert()/update() sprawdzane wyrażeniem regularnym (nie pochodzą od użytkownika,
 * ale lepiej, żeby błąd w kodzie nie stał się luką).
 */
final class DB
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $c = Config::get('db', []);
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $c['host'] ?? 'localhost',
                (int) ($c['port'] ?? 3306),
                $c['name'] ?? '',
                $c['charset'] ?? 'utf8mb4'
            );
            if (!empty($c['socket'])) {
                $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $c['socket'], $c['name'] ?? '', $c['charset'] ?? 'utf8mb4');
            }
            self::$pdo = new PDO($dsn, (string) ($c['user'] ?? ''), (string) ($c['pass'] ?? ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            // Czasy w bazie zawsze w UTC (NOW(), CURRENT_TIMESTAMP).
            self::$pdo->exec("SET time_zone = '+00:00', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        }
        return self::$pdo;
    }

    /** Do testów i długich procesów CLI (np. worker po utracie połączenia). */
    public static function disconnect(): void
    {
        self::$pdo = null;
    }

    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($name, $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    public static function fetch(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::query($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** @return int ID wstawionego wiersza (0 dla tabel bez AUTO_INCREMENT) */
    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            self::ident($table),
            implode(', ', array_map([self::class, 'ident'], $cols)),
            implode(', ', array_fill(0, count($cols), '?'))
        );
        self::query($sql, array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    /** UPDATE z warunkiem w postaci kolumna => wartość (AND). Zwraca liczbę zmienionych wierszy. */
    public static function update(string $table, array $data, array $where): int
    {
        if ($where === []) {
            throw new \InvalidArgumentException('DB::update wymaga warunku WHERE.');
        }
        $set = implode(', ', array_map(static fn($c) => self::ident($c) . ' = ?', array_keys($data)));
        $cond = implode(' AND ', array_map(static fn($c) => self::ident($c) . ' = ?', array_keys($where)));
        $sql = sprintf('UPDATE %s SET %s WHERE %s', self::ident($table), $set, $cond);
        return self::query($sql, [...array_values($data), ...array_values($where)])->rowCount();
    }

    /**
     * Transakcja: commit po sukcesie, rollback przy wyjątku (wyjątek leci dalej).
     * Uwaga: DDL w MySQL (CREATE/ALTER) robi niejawny commit — nie używać do migracji.
     */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            // Zagnieżdżenie (np. import w trybie próbnym): całość i tak zatwierdza/wycofuje zewnętrzna transakcja.
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function ident(string $name): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $name)) {
            throw new \InvalidArgumentException("Niedozwolona nazwa identyfikatora SQL: {$name}");
        }
        return '`' . $name . '`';
    }
}
