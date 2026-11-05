<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\Auth;
use App\Core\Request;
use App\Core\Response;

/** Panel /admin — admin i moderator (2FA wymusza już RequireAuth, który musi stać przed tym). */
final class RequireStaff
{
    public function __invoke(Request $request, callable $next): Response
    {
        $user = Auth::user($request);
        if ($user === null || !in_array($user['role'], ['admin', 'moderator'], true)) {
            return Response::view('errors/404', [], 404); // nie zdradzamy istnienia panelu
        }
        return $next($request);
    }
}
