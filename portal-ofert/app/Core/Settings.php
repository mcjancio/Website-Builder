<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Ustawienia portalu z tabeli `settings` (wartości JSON), wczytywane raz na żądanie.
 * Klucz pierwszego poziomu = wiersz w tabeli, dalsze poziomy = ścieżka w JSON:
 * Settings::get('portal.theme.accent').
 */
final class Settings
{
    private static ?array $cache = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();
        $value = $all;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            try {
                foreach (DB::fetchAll('SELECT `key`, `value` FROM settings') as $row) {
                    self::$cache[$row['key']] = json_decode((string) $row['value'], true, 512, JSON_THROW_ON_ERROR);
                }
            } catch (\PDOException $e) {
                // Przed migracją tabeli nie ma — portal pokazuje wartości domyślne zamiast 500.
                Logger::warning('Nie udało się wczytać ustawień', ['error' => $e->getMessage()]);
            }
        }
        return self::$cache;
    }

    public static function set(string $key, mixed $value): void
    {
        DB::query(
            'INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
            [$key, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]
        );
        self::$cache = null;
    }

    public static function has(string $key): bool
    {
        return DB::value('SELECT 1 FROM settings WHERE `key` = ?', [$key]) !== null;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
