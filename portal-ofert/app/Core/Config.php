<?php

declare(strict_types=1);

namespace App\Core;

/** Konfiguracja z config.php (zwykła tablica PHP), odczyt kluczami z kropkami: db.host. */
final class Config
{
    private static array $items = [];

    public static function load(string $file): void
    {
        $data = require $file;
        if (!is_array($data)) {
            throw new \RuntimeException("Plik konfiguracji {$file} musi zwracać tablicę.");
        }
        self::$items = $data;
    }

    public static function set(array $items): void
    {
        self::$items = $items;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$items;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }
}
