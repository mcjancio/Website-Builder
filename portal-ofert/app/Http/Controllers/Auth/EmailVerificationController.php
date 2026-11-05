<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Auth\Audit;
use App\Auth\Auth;
use App\Auth\Tokens;
use App\Core\DB;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

final class EmailVerificationController
{
    public function verify(Request $request): Response
    {
        $userId = Tokens::consume($request->params['selector'], $request->params['validator'], 'email_verify');
        if ($userId === null) {
            Session::flash('error', __('verify_email.invalid'));
            return Response::redirect(url(Auth::id() ? '/konto' : '/logowanie'));
        }
        DB::query('UPDATE users SET email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP()) WHERE id = ?', [$userId]);
        Audit::log('user.email_verified', 'user', $userId, [], $request, $userId);
        Auth::refresh();
        Session::flash('success', __('verify_email.done'));
        return Response::redirect(url(Auth::id() ? '/konto' : '/logowanie'));
    }

    public function resend(Request $request): Response
    {
        $user = Auth::user();
        if ($user === null || $user['email_verified_at'] !== null) {
            return Response::redirect(url('/konto'));
        }
        if (!RateLimiter::attempt('verify_email:user:' . $user['id'], 3, 3600)) {
            Session::flash('error', __('auth.too_many_attempts'));
            return Response::redirect(url('/konto'));
        }
        RegisterController::sendVerificationEmail((int) $user['id'], (string) $user['email'], (string) $user['first_name']);
        Session::flash('success', __('verify_email.resent', ['email' => $user['email']]));
        return Response::redirect(url('/konto'));
    }
}
