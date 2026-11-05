<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Ciasteczka ustawiane w trakcie żądania; wysyła je Response::send().
 * Kolejka zamiast setcookie() pozwala testować np. „Zapamiętaj mnie”.
 */
final class Cookies
{
    private static array $queued = [];

    public static function queue(string $name, string $value, int $expires, bool $secure): void
    {
        self::$queued[$name] = [
            'value' => $value,
            'options' => ['expires' => $expires, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax'],
        ];
    }

    public static function forget(string $name, bool $secure): void
    {
        self::queue($name, '', time() - 3600, $secure);
    }

    /** @return array<string,array{value:string,options:array}> */
    public static function queued(): array
    {
        return self::$queued;
    }

    public static function flush(): void
    {
        if (!headers_sent()) {
            foreach (self::$queued as $name => $c) {
                setcookie($name, $c['value'], $c['options']);
            }
        }
        self::$queued = [];
    }

    public static function reset(): void
    {
        self::$queued = [];
    }
}
