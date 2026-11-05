<?php

declare(strict_types=1);

namespace App\Core;

/** Token CSRF w sesji; sprawdzany dla każdego POST (pole _csrf albo nagłówek X-CSRF-Token). */
final class Csrf
{
    private const KEY = '_csrf_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::KEY];
    }

    public static function rotate(): void
    {
        $_SESSION[self::KEY] = bin2hex(random_bytes(32));
    }

    public static function validate(Request $request): bool
    {
        $sent = $request->post['_csrf'] ?? $request->header('X-CSRF-Token') ?? '';
        $expected = $_SESSION[self::KEY] ?? '';
        return is_string($sent) && $expected !== '' && hash_equals($expected, $sent);
    }
}
