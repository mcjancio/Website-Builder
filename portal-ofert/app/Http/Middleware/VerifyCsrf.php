<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Csrf;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;

/** Każdy POST bez poprawnego tokenu CSRF kończy się 419 (sesja wygasła / podrobiony formularz). */
final class VerifyCsrf
{
    public function __invoke(Request $request, callable $next): Response
    {
        if ($request->isPost() && !Csrf::validate($request)) {
            Logger::warning('Odrzucony POST bez poprawnego tokenu CSRF', ['path' => $request->path, 'ip' => $request->ip()]);
            return $request->wantsJson()
                ? Response::json(['error' => __('errors.419.text')], 419)
                : Response::view('errors/419', [], 419);
        }
        return $next($request);
    }
}
