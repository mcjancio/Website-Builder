<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;

/**
 * Dla akcji wymagających potwierdzonego e-maila (np. dodanie oferty — etap 3).
 * Nowe konto ma okres łaski (registration.email_grace_hours, domyślnie 24 h).
 * Używać ZA RequireAuth.
 */
final class RequireVerifiedEmail
{
    public function __invoke(Request $request, callable $next): Response
    {
        $user = Auth::user($request);
        if ($user !== null && !self::isVerifiedOrInGrace($user)) {
            Session::flash('notice', __('verify_email.required'));
            return Response::redirect(url('/konto'));
        }
        return $next($request);
    }

    public static function isVerifiedOrInGrace(array $user): bool
    {
        if ($user['email_verified_at'] !== null) {
            return true;
        }
        $grace = (int) Settings::get('registration.email_grace_hours', 24) * 3600;
        return strtotime($user['created_at'] . ' UTC') + $grace > time();
    }
}
