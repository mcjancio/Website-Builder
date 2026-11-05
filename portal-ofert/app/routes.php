<?php

declare(strict_types=1);

/**
 * Trasy portalu. Kolejne etapy dopisują tu swoje grupy (/konto, /oferty, /admin…).
 *
 * @var \App\Core\Router $router
 */

use App\Http\Controllers\HealthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ThemeController;
use App\Http\Middleware\StartSession;
use App\Http\Middleware\VerifyCsrf;

// /health i /theme.css bez sesji — nie tworzą ciasteczek ani plików sesji przy każdym sprawdzeniu monitoringu.
$router->get('/health', [HealthController::class, 'show']);
$router->get('/theme.css', [ThemeController::class, 'css']);

$router->group('', [StartSession::class, VerifyCsrf::class], static function ($r): void {
    $r->get('/', [HomeController::class, 'index']);

    // Strony treści — trasa ogólna, MUSI być ostatnia w grupie (łapie każdy pojedynczy segment).
    $r->get('/{slug:[a-z0-9\-]+}', [PageController::class, 'show']);
});
