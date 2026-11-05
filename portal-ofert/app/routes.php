<?php

declare(strict_types=1);

/**
 * Trasy portalu. Kolejne etapy dopisują tu swoje grupy (/oferty, /admin…).
 *
 * @var \App\Core\Router $router
 */

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\CompanyLookupController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ThemeController;
use App\Http\Controllers\WebhookController;
use App\Http\Middleware\LoadUser;
use App\Http\Middleware\RequireAuth;
use App\Http\Middleware\RequireGuest;
use App\Http\Middleware\StartSession;
use App\Http\Middleware\VerifyCsrf;

// Bez sesji: monitoring, motyw, webhooki (zabezpieczone podpisem), API NIP (limit na IP).
$router->get('/health', [HealthController::class, 'show']);
$router->get('/theme.css', [ThemeController::class, 'css']);
$router->get('/webhook/whatsapp', [WebhookController::class, 'whatsappVerify']);
$router->post('/webhook/whatsapp', [WebhookController::class, 'whatsapp']);
$router->get('/api/firma/{nip:[0-9A-Za-z\- ]{10,16}}', [CompanyLookupController::class, 'show']);

$router->group('', [StartSession::class, VerifyCsrf::class, LoadUser::class], static function ($r): void {
    $r->get('/', [HomeController::class, 'index']);

    // Konto — tylko dla niezalogowanych
    $r->group('', [RequireGuest::class], static function ($g): void {
        $g->get('/rejestracja', [RegisterController::class, 'show']);
        $g->post('/rejestracja', [RegisterController::class, 'store']);
        $g->get('/rejestracja/weryfikacja', [RegisterController::class, 'verifyForm']);
        $g->post('/rejestracja/weryfikacja', [RegisterController::class, 'verify']);
        $g->post('/rejestracja/wyslij-ponownie', [RegisterController::class, 'resend']);
        $g->post('/rejestracja/wyslij-sms', [RegisterController::class, 'smsFallback']);
        $g->get('/rejestracja/status', [RegisterController::class, 'status']);
        $g->get('/logowanie', [LoginController::class, 'show']);
        $g->post('/logowanie', [LoginController::class, 'login']);
        $g->get('/logowanie/2fa', [LoginController::class, 'twoFactorForm']);
        $g->post('/logowanie/2fa', [LoginController::class, 'twoFactor']);
    });

    // Reset hasła i potwierdzenie e-maila — dla wszystkich (także zalogowanych)
    $r->get('/reset-hasla', [PasswordController::class, 'forgotForm']);
    $r->post('/reset-hasla', [PasswordController::class, 'sendLink']);
    $r->get('/reset-hasla/{selector:[a-f0-9]{24}}/{validator:[a-f0-9]{64}}', [PasswordController::class, 'resetForm']);
    $r->post('/reset-hasla/{selector:[a-f0-9]{24}}/{validator:[a-f0-9]{64}}', [PasswordController::class, 'reset']);
    $r->get('/potwierdz-email/{selector:[a-f0-9]{24}}/{validator:[a-f0-9]{64}}', [EmailVerificationController::class, 'verify']);

    // Zalogowani
    $r->group('', [RequireAuth::class], static function ($g): void {
        $g->post('/wyloguj', [LoginController::class, 'logout']);
        $g->get('/konto', [AccountController::class, 'index']);
        $g->post('/konto/potwierdz-email', [EmailVerificationController::class, 'resend']);
        $g->get('/konto/2fa', [AccountController::class, 'twoFactor']);
        $g->post('/konto/2fa', [AccountController::class, 'enableTwoFactor']);
        $g->post('/konto/2fa/wylacz', [AccountController::class, 'disableTwoFactor']);
    });

    // Strony treści — trasa ogólna, MUSI być ostatnia (łapie każdy pojedynczy segment).
    $r->get('/{slug:[a-z0-9\-]+}', [PageController::class, 'show']);
});
