<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Cookies;
use App\Core\DB;
use App\Core\Request;
use App\Core\Session;

/**
 * Zalogowany użytkownik: sesja + opcjonalnie „Zapamiętaj mnie” (ciasteczko selector:validator,
 * 30 dni, rotowane przy każdym użyciu — skradzione ciasteczko działa najwyżej raz).
 */
final class Auth
{
    public const REMEMBER_COOKIE = 'portal_remember';
    public const REMEMBER_DAYS = 30;

    private static ?array $user = null;
    private static bool $resolved = false;

    public static function user(?Request $request = null): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;

        $id = Session::get('user_id');
        if (is_int($id)) {
            self::$user = self::load($id);
            // Sesja sprzed zmiany hasła / „wyloguj wszędzie” / blokady jest nieważna.
            if (self::$user !== null && (int) Session::get('sv', 0) !== (int) self::$user['session_version']) {
                self::$user = null;
            }
            if (self::$user === null) {
                Session::forget('user_id');
                Session::forget('sv');
            }
        } elseif ($request !== null && ($cookie = $request->cookie(self::REMEMBER_COOKIE)) !== null) {
            self::$user = self::fromRememberCookie($cookie, $request);
        }
        return self::$user;
    }

    public static function id(): ?int
    {
        return isset(self::$user['id']) ? (int) self::$user['id'] : null;
    }

    public static function login(int $userId, Request $request, bool $remember = false): void
    {
        Session::regenerate();
        Session::set('user_id', $userId);
        Session::set('sv', (int) DB::value('SELECT session_version FROM users WHERE id = ?', [$userId]));
        Session::forget('2fa_pending');
        DB::query('UPDATE users SET last_login_at = UTC_TIMESTAMP(), failed_logins = 0, locked_until = NULL WHERE id = ?', [$userId]);
        if ($remember) {
            $t = Tokens::create($userId, 'remember', self::REMEMBER_DAYS * 86400, $request->userAgent());
            Cookies::queue(self::REMEMBER_COOKIE, $t['selector'] . ':' . $t['validator'], time() + self::REMEMBER_DAYS * 86400, $request->isHttps());
        }
        self::$user = self::load($userId);
        self::$resolved = true;
        Audit::log('login.ok', 'user', $userId, [], $request);
    }

    public static function logout(Request $request): void
    {
        $cookie = $request->cookie(self::REMEMBER_COOKIE);
        if ($cookie !== null && str_contains($cookie, ':')) {
            Tokens::deleteBySelector(explode(':', $cookie, 2)[0]);
        }
        Cookies::forget(self::REMEMBER_COOKIE, $request->isHttps());
        Session::destroy();
        self::forgetCache();
    }

    /** Po zmianie danych użytkownika w bazie (np. włączenie 2FA). */
    public static function refresh(): void
    {
        if (self::$user !== null) {
            self::$user = self::load((int) self::$user['id']);
        }
    }

    /**
     * Unieważnia wszystkie sesje i ciasteczka „Zapamiętaj mnie” użytkownika.
     * $keepCurrent — bieżąca sesja tego użytkownika zostaje (np. po zmianie własnego hasła).
     */
    public static function invalidateSessions(int $userId, bool $keepCurrent = false): void
    {
        DB::query('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$userId]);
        Tokens::revokeAll($userId, 'remember');
        if ($keepCurrent && Session::get('user_id') === $userId) {
            Session::set('sv', (int) DB::value('SELECT session_version FROM users WHERE id = ?', [$userId]));
        }
    }

    public static function forgetCache(): void
    {
        self::$user = null;
        self::$resolved = false;
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
    }

    public static function requiresTwoFactor(array $user): bool
    {
        return in_array($user['role'], ['admin', 'moderator'], true) || $user['totp_enabled_at'] !== null;
    }

    private static function load(int $id): ?array
    {
        return DB::fetch(
            "SELECT u.id, u.email, u.first_name, u.last_name, u.phone_e164, u.role, u.account_type, u.status,
                    u.email_verified_at, u.phone_verified_at, u.totp_enabled_at, u.created_at, u.session_version,
                    c.name AS company_name, c.nip AS company_nip
             FROM users u LEFT JOIN companies c ON c.id = u.company_id
             WHERE u.id = ? AND u.status = 'active'",
            [$id]
        );
    }

    private static function fromRememberCookie(string $cookie, Request $request): ?array
    {
        [$selector, $validator] = array_pad(explode(':', $cookie, 2), 2, '');
        $userId = Tokens::consume($selector, $validator, 'remember', false);
        if ($userId === null) {
            Cookies::forget(self::REMEMBER_COOKIE, $request->isHttps());
            return null;
        }
        $user = self::load($userId);
        if ($user === null) {
            return null;
        }
        // Konta z 2FA nie wpuszczamy samym ciasteczkiem — wymagamy kodu (sesja 2fa_pending).
        if (self::requiresTwoFactor($user) && $user['totp_enabled_at'] !== null) {
            Session::set('2fa_pending', ['user_id' => $userId, 'remember' => false, 'at' => time()]);
            return null;
        }
        // Rotacja: stary token kasujemy, wydajemy nowy.
        Tokens::deleteBySelector($selector);
        Session::regenerate();
        Session::set('user_id', $userId);
        Session::set('sv', (int) $user['session_version']);
        $t = Tokens::create($userId, 'remember', self::REMEMBER_DAYS * 86400, $request->userAgent());
        Cookies::queue(self::REMEMBER_COOKIE, $t['selector'] . ':' . $t['validator'], time() + self::REMEMBER_DAYS * 86400, $request->isHttps());
        Audit::log('login.remember', 'user', $userId, [], $request);
        return $user;
    }
}
