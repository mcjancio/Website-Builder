<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Wszystkie teksty interfejsu w jednym pliku (lang/pl.php).
 * Brakujący klucz zwraca sam klucz i trafia do logu — łatwo wyłapać w testach.
 */
final class Lang
{
    private static array $lines = [];

    public static function load(string $file): void
    {
        $lines = is_file($file) ? require $file : [];
        self::$lines = is_array($lines) ? $lines : [];
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::$lines);
    }

    public static function get(string $key, array $params = []): string
    {
        if (!array_key_exists($key, self::$lines)) {
            Logger::warning('Brak tłumaczenia', ['key' => $key]);
            return $key;
        }
        $text = (string) self::$lines[$key];
        foreach ($params as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }
        return $text;
    }

    public static function all(): array
    {
        return self::$lines;
    }
}
