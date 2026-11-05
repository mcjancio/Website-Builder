<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\Auth;
use App\Core\Request;
use App\Core\Response;

/** Ustala zalogowanego użytkownika (sesja albo „Zapamiętaj mnie”) — po StartSession, dla każdej strony. */
final class LoadUser
{
    public function __invoke(Request $request, callable $next): Response
    {
        Auth::forgetCache();
        Auth::user($request);
        return $next($request);
    }
}
