<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\Auth;
use App\Core\Request;
use App\Core\Response;

/** Sekcje tylko dla administratora: użytkownicy i role, kryteria, strony, ustawienia, dziennik. */
final class RequireAdmin
{
    public function __invoke(Request $request, callable $next): Response
    {
        $user = Auth::user($request);
        if ($user === null || $user['role'] !== 'admin') {
            return Response::view('errors/403', [], 403);
        }
        return $next($request);
    }
}
