<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

final class StartSession
{
    public function __invoke(Request $request, callable $next): Response
    {
        Session::start($request);
        return $next($request);
    }
}
