<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/**
 * Strona tylko dla zalogowanych. Dodatkowo: admin i moderator MUSZĄ mieć włączone 2FA —
 * do tego czasu każda strona konta prowadzi do konfiguracji 2FA.
 */
final class RequireAuth
{
    private const ALLOWED_WITHOUT_2FA = ['/konto/2fa', '/wyloguj'];

    public function __invoke(Request $request, callable $next): Response
    {
        $user = Auth::user($request);
        if ($user === null) {
            if ($request->wantsJson()) {
                return Response::json(['error' => __('auth.login_required')], 401);
            }
            Session::flash('notice', __('auth.login_required'));
            $next_ = $request->method === 'GET' ? $request->path : '/konto';
            return Response::redirect(url('/logowanie', ['next' => $next_]));
        }

        if (in_array($user['role'], ['admin', 'moderator'], true) && $user['totp_enabled_at'] === null
            && !in_array($request->path, self::ALLOWED_WITHOUT_2FA, true)) {
            Session::flash('notice', __('twofa.required_for_role'));
            return Response::redirect(url('/konto/2fa'));
        }
        return $next($request);
    }
}
