<?php

declare(strict_types=1);

namespace App\Core;

/** Sesja PHP z bezpiecznymi ciasteczkami + komunikaty flash. */
final class Session
{
    private const FLASH = '_flash';
    private const FLASH_OLD = '_flash_old';

    public static function start(Request $request): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if (PHP_SAPI === 'cli') {
            $_SESSION ??= [];
            return; // testy/CLI: sesja w pamięci, bez nagłówków
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_maxlifetime', (string) (int) Config::get('session.lifetime', 7200));

        session_name((string) Config::get('session.name', 'portal_sid'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $request->isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        $savePath = APP_ROOT . '/storage/sessions';
        if (is_dir($savePath) && is_writable($savePath)) {
            session_save_path($savePath); // własny katalog: inne strony na koncie nie sprzątają nam sesji
        }
        session_start();

        // Przesunięcie flash: to, co ustawiono w poprzednim żądaniu, jest dostępne teraz, potem znika.
        $_SESSION[self::FLASH_OLD] = $_SESSION[self::FLASH] ?? [];
        $_SESSION[self::FLASH] = [];
    }

    /** Wywołuj po zalogowaniu/wylogowaniu — ochrona przed session fixation. */
    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        Csrf::rotate();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function flash(string $key, mixed $value): void
    {
        $_SESSION[self::FLASH][$key] = $value;
    }

    public static function getFlash(string $key, mixed $default = null): mixed
    {
        return $_SESSION[self::FLASH_OLD][$key] ?? $default;
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 3600,
                'path' => $p['path'],
                'secure' => $p['secure'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_destroy();
        }
    }
}
