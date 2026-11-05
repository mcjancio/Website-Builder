<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\Auth;
use App\Core\Request;
use App\Core\Response;

/** Strony logowania/rejestracji — zalogowany trafia od razu do swojego konta. */
final class RequireGuest
{
    public function __invoke(Request $request, callable $next): Response
    {
        if (Auth::user($request) !== null) {
            return Response::redirect(url('/konto'));
        }
        return $next($request);
    }
}
