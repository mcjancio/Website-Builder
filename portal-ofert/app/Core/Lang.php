<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Teksty interfejsu.
 *
 * 1. lang/pl.php — NEUTRALNE teksty domyślne (silnik nie zna żadnej branży).
 * 2. Słownik z presetu (settings.terms) — nadpisuje wybrane klucze dla danego portalu,
 *    np. "term.listing" => "Inwestycja", "term.offerer" => "Deweloper".
 *
 * Brakujący klucz zwraca sam klucz i trafia do logu — łatwo wyłapać w testach.
 */
final class Lang
{
    private static array $lines = [];
    private static ?array $overrides = null;
    /** @var (callable(): array)|null */
    private static $overrideLoader = null;

    public static function load(string $file): void
    {
        $lines = is_file($file) ? require $file : [];
        self::$lines = is_array($lines) ? $lines : [];
        self::$overrides = null;
    }

    /** Źródło nadpisań (bootstrap: słownik z ustawień portalu). Wczytywane leniwie przy pierwszym tekście. */
    public static function setOverrideLoader(?callable $loader): void
    {
        self::$overrideLoader = $loader;
        self::$overrides = null;
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::$lines);
    }

    public static function get(string $key, array $params = []): string
    {
        $overrides = self::overrides();
        if (array_key_exists($key, $overrides)) {
            $text = (string) $overrides[$key];
        } elseif (array_key_exists($key, self::$lines)) {
            $text = (string) self::$lines[$key];
        } else {
            Logger::warning('Brak tłumaczenia', ['key' => $key]);
            return $key;
        }
        foreach ($params as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }
        return $text;
    }

    public static function all(): array
    {
        return array_merge(self::$lines, self::overrides());
    }

    private static function overrides(): array
    {
        if (self::$overrides === null) {
            self::$overrides = [];
            if (self::$overrideLoader !== null) {
                $loaded = (self::$overrideLoader)();
                // Nadpisać można tylko istniejące klucze tekstowe — literówka w presecie nie tworzy nowych.
                self::$overrides = is_array($loaded)
                    ? array_filter($loaded, static fn($v, $k) => is_string($v) && isset(self::$lines[$k]), ARRAY_FILTER_USE_BOTH)
                    : [];
            }
        }
        return self::$overrides;
    }
}
