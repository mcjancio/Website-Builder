<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Auth\Audit;
use App\Auth\Auth;
use App\Auth\Tokens;
use App\Core\DB;
use App\Core\Mailer;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;

/** Reset hasła linkiem e-mail (ważny 1 h). Działa też dla zalogowanego użytkownika. */
final class PasswordController
{
    public function forgotForm(Request $request): Response
    {
        return Response::view('auth/forgot-password', ['title' => __('password.forgot_title'), 'errors' => [], 'old' => [], 'noindex' => true]);
    }

    public function sendLink(Request $request): Response
    {
        $email = mb_strtolower($request->str('email'));
        $v = Validator::make(['email' => $email], ['email' => 'required|email']);
        if ($v->fails()) {
            return Response::html(View::render('auth/forgot-password', [
                'title' => __('password.forgot_title'), 'errors' => $v->errors(), 'old' => ['email' => $email], 'noindex' => true,
            ]), 422);
        }

        $allowed = RateLimiter::attempt('reset:ip:' . $request->ip(), 10, 3600)
            && RateLimiter::attempt('reset:email:' . $email, 3, 3600);
        $user = DB::fetch("SELECT id, first_name FROM users WHERE email = ? AND status = 'active'", [$email]);
        if ($allowed && $user !== null) {
            Tokens::revokeAll((int) $user['id'], 'password_reset');
            $t = Tokens::create((int) $user['id'], 'password_reset', 3600, $request->userAgent());
            Mailer::queue($email, 'reset-password', [
                'name' => $user['first_name'],
                'url' => rtrim((string) config('app.url'), '/') . url('/reset-hasla/' . $t['selector'] . '/' . $t['validator']),
            ]);
            Audit::log('password.reset_requested', 'user', (int) $user['id'], [], $request);
        }
        // Ta sama odpowiedź niezależnie od tego, czy konto istnieje.
        Session::flash('success', __('password.link_sent'));
        return Response::redirect(url('/reset-hasla'));
    }

    public function resetForm(Request $request): Response
    {
        if (Tokens::consume($request->params['selector'], $request->params['validator'], 'password_reset', false) === null) {
            return Response::view('auth/reset-invalid', ['title' => __('password.invalid_title'), 'noindex' => true], 410);
        }
        return Response::view('auth/reset-password', ['title' => __('password.reset_title'), 'errors' => [], 'noindex' => true]);
    }

    public function reset(Request $request): Response
    {
        $password = (string) ($request->post['password'] ?? '');
        $v = Validator::make($request->post, ['password' => 'required|minlength:10|maxlength:200'], ['password' => __('register.password')]);
        $errors = $v->errors();
        if (!isset($errors['password']) && $password !== ($request->post['password_confirmation'] ?? null)) {
            $errors['password_confirmation'] = __('register.password_mismatch');
        }
        if ($errors !== []) {
            return Response::html(View::render('auth/reset-password', ['title' => __('password.reset_title'), 'errors' => $errors, 'noindex' => true]), 422);
        }

        $userId = Tokens::consume($request->params['selector'], $request->params['validator'], 'password_reset');
        if ($userId === null) {
            return Response::view('auth/reset-invalid', ['title' => __('password.invalid_title'), 'noindex' => true], 410);
        }
        DB::update('users', ['password_hash' => Auth::hashPassword($password), 'failed_logins' => 0, 'locked_until' => null], ['id' => $userId]);
        Tokens::revokeAll($userId, 'remember');      // wyloguj „zapamiętane” urządzenia
        Tokens::revokeAll($userId, 'password_reset');
        Audit::log('password.reset', 'user', $userId, [], $request, $userId);

        if (Auth::id() !== null) {
            Auth::logout($request);
        }
        Session::flash('success', __('password.changed'));
        return Response::redirect(url('/logowanie'));
    }
}
