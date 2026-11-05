<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Auth\Audit;
use App\Auth\Auth;
use App\Auth\Totp;
use App\Core\Crypto;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Core\View;
use App\Http\Middleware\RequireVerifiedEmail;

/** Panel „Moje konto” (etap 2: dane, status weryfikacji, 2FA). Pełny panel — etap 5. */
final class AccountController
{
    public function index(Request $request): Response
    {
        $user = Auth::user();
        $graceLeft = null;
        if ($user['email_verified_at'] === null) {
            $deadline = strtotime($user['created_at'] . ' UTC') + (int) Settings::get('registration.email_grace_hours', 24) * 3600;
            $graceLeft = max(0, (int) ceil(($deadline - time()) / 3600));
        }
        $accountTypeLabel = null;
        foreach ((array) Settings::get('account_types', []) as $t) {
            if (($t['key'] ?? null) === $user['account_type']) {
                $accountTypeLabel = $t['label'];
            }
        }
        return Response::view('account/index', [
            'title' => __('account.title'),
            'user' => $user,
            'accountTypeLabel' => $accountTypeLabel,
            'graceLeft' => $graceLeft,
            'emailOk' => RequireVerifiedEmail::isVerifiedOrInGrace($user),
            'noindex' => true,
        ]);
    }

    public function twoFactor(Request $request): Response
    {
        $user = Auth::user();
        if ($user['totp_enabled_at'] !== null) {
            $left = count(json_decode((string) DB::value('SELECT recovery_codes FROM users WHERE id = ?', [$user['id']]), true) ?: []);
            return Response::view('account/two-factor', $this->data($user, ['enabled' => true, 'recoveryLeft' => $left]));
        }
        $secret = Session::get('totp_setup');
        if (!is_string($secret)) {
            $secret = Totp::generateSecret();
            Session::set('totp_setup', $secret);
        }
        return Response::view('account/two-factor', $this->data($user, ['enabled' => false, 'secret' => $secret]));
    }

    public function enableTwoFactor(Request $request): Response
    {
        $user = Auth::user();
        $secret = Session::get('totp_setup');
        if ($user['totp_enabled_at'] !== null || !is_string($secret)) {
            return Response::redirect(url('/konto/2fa'));
        }
        if (!Totp::verify($secret, $request->str('code'))) {
            return Response::html(View::render('account/two-factor', $this->data($user, [
                'enabled' => false, 'secret' => $secret, 'errors' => ['code' => __('twofa.invalid')],
            ])), 422);
        }
        [$codes, $hashes] = Totp::recoveryCodes();
        DB::update('users', [
            'totp_secret' => Crypto::encrypt($secret),
            'totp_enabled_at' => gmdate('Y-m-d H:i:s'),
            'recovery_codes' => json_encode($hashes),
        ], ['id' => $user['id']]);
        Session::forget('totp_setup');
        Session::regenerate();
        Audit::log('2fa.enabled', 'user', (int) $user['id'], [], $request);
        Auth::refresh();
        // Kody awaryjne pokazujemy JEDEN raz — nie ma ich w bazie w postaci jawnej.
        return Response::view('account/recovery-codes', ['title' => __('twofa.recovery_title'), 'codes' => $codes, 'noindex' => true]);
    }

    public function disableTwoFactor(Request $request): Response
    {
        $user = Auth::user();
        if (in_array($user['role'], ['admin', 'moderator'], true)) {
            Session::flash('error', __('twofa.cannot_disable'));
            return Response::redirect(url('/konto/2fa'));
        }
        if (!\App\Core\RateLimiter::attempt('reauth:user:' . $user['id'], 5, 900)) {
            Session::flash('error', __('auth.too_many_attempts'));
            return Response::redirect(url('/konto/2fa'));
        }
        $secret = Crypto::decrypt((string) DB::value('SELECT totp_secret FROM users WHERE id = ?', [$user['id']]));
        if ($secret === null || !Totp::verify($secret, $request->str('code'))) {
            Session::flash('error', __('twofa.invalid'));
            return Response::redirect(url('/konto/2fa'));
        }
        DB::update('users', ['totp_secret' => null, 'totp_enabled_at' => null, 'recovery_codes' => null], ['id' => $user['id']]);
        Audit::log('2fa.disabled', 'user', (int) $user['id'], [], $request);
        Session::flash('success', __('twofa.disabled'));
        return Response::redirect(url('/konto'));
    }

    private function data(array $user, array $extra): array
    {
        $issuer = (string) Settings::get('portal.name', __('app.default_name'));
        $extra['uri'] = isset($extra['secret']) ? Totp::uri($extra['secret'], $user['email'], $issuer) : null;
        return $extra + ['title' => __('twofa.setup_title'), 'user' => $user, 'errors' => [], 'noindex' => true];
    }
}
