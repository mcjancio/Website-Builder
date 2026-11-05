<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Auth\Audit;
use App\Auth\Auth;
use App\Auth\Totp;
use App\Core\Crypto;
use App\Core\DB;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

/**
 * Logowanie e-mail + hasło, blokada po 5 nieudanych próbach (narastająco), 2FA (TOTP lub kod awaryjny),
 * „Zapamiętaj mnie” na 30 dni. Komunikat o błędzie nie zdradza, czy konto istnieje.
 */
final class LoginController
{
    private const MAX_FAILS = 5;            // błędne hasła z jednego adresu IP dla jednego konta…
    private const LOCK_SECONDS = 900;        // …w ciągu 15 minut
    private const MAX_FAILS_ACCOUNT = 30;    // błędne hasła dla konta ze wszystkich adresów w ciągu godziny

    public function show(Request $request): Response
    {
        if (is_array(Session::get('2fa_pending'))) {
            return Response::redirect(url('/logowanie/2fa'));
        }
        return Response::view('auth/login', $this->data([], ['next' => $request->str('next')]));
    }

    public function login(Request $request): Response
    {
        $email = mb_strtolower($request->str('email'));
        $password = (string) ($request->post['password'] ?? '');
        $old = ['email' => $email, 'next' => $request->str('next'), 'remember' => $request->str('remember')];

        if (!RateLimiter::attempt('login:ip:' . $request->ip(), 30, 900)) {
            return Response::html(View::render('auth/login', $this->data(['email' => __('auth.too_many_attempts')], $old)), 429);
        }

        $user = $email !== '' ? DB::fetch('SELECT id, password_hash, status, role, totp_enabled_at, failed_logins FROM users WHERE email = ?', [$email]) : null;

        // Blokada po 5 błędach liczona dla pary e-mail + IP: atakujący nie zablokuje cudzego konta ze swojego adresu,
        // a odpowiedź jest taka sama dla kont istniejących i nieistniejących (bez zdradzania, kto jest zarejestrowany).
        // Dodatkowo limit na konto ze wszystkich adresów (zgadywanie rozproszone).
        $acct = 'login:acct:' . hash('sha256', $email);
        $acctIp = $acct . ':ip:' . $request->ip();
        if (RateLimiter::count($acctIp, self::LOCK_SECONDS) >= self::MAX_FAILS || RateLimiter::count($acct, 3600) >= self::MAX_FAILS_ACCOUNT) {
            $user !== null && Audit::log('login.locked', 'user', (int) $user['id'], [], $request);
            $wait = max(RateLimiter::retryAfter($acctIp, self::LOCK_SECONDS), 60);
            return Response::html(View::render('auth/login', $this->data(
                ['email' => __('auth.locked', ['minutes' => (int) ceil($wait / 60)])], $old)), 429);
        }

        // Stały czas odpowiedzi także dla nieistniejącego konta.
        $hash = $user['password_hash'] ?? Auth::hashPassword(bin2hex(random_bytes(8)));
        $valid = password_verify($password, (string) $hash) && $user !== null && $user['status'] === 'active';

        if (!$valid) {
            RateLimiter::hit($acctIp, self::LOCK_SECONDS);
            RateLimiter::hit($acct, 3600);
            if ($user !== null) {
                DB::query('UPDATE users SET failed_logins = failed_logins + 1 WHERE id = ?', [$user['id']]);
                Audit::log('login.fail', 'user', (int) $user['id'], ['fails' => (int) $user['failed_logins'] + 1], $request);
            }
            return Response::html(View::render('auth/login', $this->data(['email' => __('auth.invalid_credentials')], $old)), 422);
        }

        if (password_needs_rehash((string) $user['password_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT)) {
            DB::update('users', ['password_hash' => Auth::hashPassword($password)], ['id' => $user['id']]);
        }

        RateLimiter::clear($acctIp);
        $remember = $request->str('remember') !== '';
        if ($user['totp_enabled_at'] !== null) {
            Session::regenerate();
            Session::set('2fa_pending', ['user_id' => (int) $user['id'], 'remember' => $remember, 'next' => $old['next'], 'at' => time()]);
            return Response::redirect(url('/logowanie/2fa'));
        }

        Auth::login((int) $user['id'], $request, $remember);
        return Response::redirect($this->target($old['next']));
    }

    public function twoFactorForm(Request $request): Response
    {
        if ($this->pending2fa() === null) {
            return Response::redirect(url('/logowanie'));
        }
        return Response::view('auth/two-factor', ['title' => __('twofa.title'), 'errors' => [], 'noindex' => true]);
    }

    public function twoFactor(Request $request): Response
    {
        $pending = $this->pending2fa();
        if ($pending === null) {
            Session::flash('notice', __('twofa.session_expired'));
            return Response::redirect(url('/logowanie'));
        }
        $userId = (int) $pending['user_id'];
        if (!RateLimiter::attempt('2fa:user:' . $userId, 5, 300)) {
            return Response::html(View::render('auth/two-factor', ['title' => __('twofa.title'), 'errors' => ['code' => __('auth.too_many_attempts')], 'noindex' => true]), 429);
        }

        $row = DB::fetch('SELECT totp_secret, recovery_codes, totp_last_step FROM users WHERE id = ?', [$userId]);
        $code = trim($request->str('code'));
        $ok = false;
        $usedRecovery = false;
        $secret = $row && $row['totp_secret'] !== null ? Crypto::decrypt((string) $row['totp_secret']) : null;
        $step = $secret !== null ? Totp::matchStep($secret, $code) : null;
        if ($step !== null && $step > (int) ($row['totp_last_step'] ?? 0)) {
            $ok = true;
            DB::update('users', ['totp_last_step' => $step], ['id' => $userId]); // ten sam kod nie zadziała drugi raz
        } elseif ($row && preg_match('/^[a-f0-9]{6}-[a-f0-9]{6}$/i', $code)) {
            $hashes = json_decode((string) ($row['recovery_codes'] ?? '[]'), true) ?: [];
            $h = hash('sha256', strtolower($code));
            if (in_array($h, $hashes, true)) {
                $ok = $usedRecovery = true;
                DB::update('users', ['recovery_codes' => json_encode(array_values(array_diff($hashes, [$h])))], ['id' => $userId]);
            }
        }

        if (!$ok) {
            Audit::log('login.2fa_fail', 'user', $userId, [], $request);
            return Response::html(View::render('auth/two-factor', ['title' => __('twofa.title'), 'errors' => ['code' => __('twofa.invalid')], 'noindex' => true]), 422);
        }

        RateLimiter::clear('2fa:user:' . $userId);
        Auth::login($userId, $request, (bool) $pending['remember']);
        if ($usedRecovery) {
            Audit::log('login.recovery_code', 'user', $userId, [], $request);
            Session::flash('notice', __('twofa.recovery_used'));
        }
        return Response::redirect($this->target((string) ($pending['next'] ?? '')));
    }

    public function logout(Request $request): Response
    {
        if (Auth::id() !== null) {
            Audit::log('logout', 'user', Auth::id(), [], $request);
        }
        Auth::logout($request);
        return Response::redirect(url('/'));
    }

    private function pending2fa(): ?array
    {
        $p = Session::get('2fa_pending');
        // Krok 2FA ważny 10 minut od poprawnego hasła.
        if (!is_array($p) || (isset($p['at']) && time() - (int) $p['at'] > 600)) {
            Session::forget('2fa_pending');
            return null;
        }
        return $p;
    }

    /** Tylko ścieżki wewnętrzne — ochrona przed open redirect przez ?next=. */
    private function target(string $next): string
    {
        return preg_match('#^/(?!/)[a-z0-9\-/]*$#i', $next) && !str_starts_with($next, '/logowanie') ? url($next) : url('/konto');
    }

    private function data(array $errors, array $old): array
    {
        return ['title' => __('login.title'), 'errors' => $errors, 'old' => $old, 'noindex' => true];
    }
}
