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
use App\Http\Controllers\FileController;
use App\Http\Controllers\ListingsController;
use App\Http\Controllers\ListingWizardController;
use App\Http\Controllers\SeoController;
use App\Http\Controllers\MyListingsController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ThemeController;
use App\Http\Controllers\WebhookController;
use App\Http\Middleware\LoadUser;
use App\Http\Middleware\RequireAuth;
use App\Http\Middleware\RequireGuest;
use App\Http\Middleware\RequireVerifiedEmail;
use App\Http\Middleware\StartSession;
use App\Http\Middleware\VerifyCsrf;

// Bez sesji: monitoring, motyw, webhooki (zabezpieczone podpisem), API NIP (limit na IP).
$router->get('/health', [HealthController::class, 'show']);
$router->get('/theme.css', [ThemeController::class, 'css']);
$router->get('/sitemap.xml', [SeoController::class, 'sitemap']);
$router->get('/robots.txt', [SeoController::class, 'robots']);
$router->get('/webhook/whatsapp', [WebhookController::class, 'whatsappVerify']);
$router->post('/webhook/whatsapp', [WebhookController::class, 'whatsapp']);
$router->get('/api/firma/{nip:[0-9A-Za-z\- ]{10,16}}', [CompanyLookupController::class, 'show']);

$router->group('', [StartSession::class, VerifyCsrf::class, LoadUser::class], static function ($r): void {
    $r->get('/', [HomeController::class, 'index']);

    // Publiczna lista i strona oferty (etap 4)
    $r->get('/oferty', [ListingsController::class, 'index']);
    $r->get('/oferta/{slug:[a-z0-9\-]+?}-{id:\d+}', [ListingsController::class, 'show']);
    $r->post('/oferta/{id:\d+}/zapytanie', [ListingsController::class, 'inquiry']);

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

        $g->post('/oferta/{id:\d+}/dostep', [ListingsController::class, 'requestAccess']);

        // Oferty autora (etap 3): kreator, pliki, wysyłka do moderacji
        $g->get('/moje-oferty', [MyListingsController::class, 'index']);
        $g->get('/moje-oferty/{id:\d+}/podglad', [MyListingsController::class, 'preview']);
        $g->post('/podglad-markdown', [ListingWizardController::class, 'markdownPreview']);
        $g->group('', [RequireVerifiedEmail::class], static function ($v): void {
            $v->get('/dodaj-oferte', [ListingWizardController::class, 'create']);
            $v->post('/dodaj-oferte', [ListingWizardController::class, 'store']);
            $v->get('/moje-oferty/{id:\d+}/edycja', [ListingWizardController::class, 'edit']);
            $v->get('/moje-oferty/{id:\d+}/edycja/{step:[1-4]}', [ListingWizardController::class, 'edit']);
            $v->post('/moje-oferty/{id:\d+}/edycja/{step:[1-4]}', [ListingWizardController::class, 'update']);
            $v->post('/moje-oferty/{id:\d+}/zdjecia', [ListingWizardController::class, 'uploadImages']);
            $v->post('/moje-oferty/{id:\d+}/dokumenty', [ListingWizardController::class, 'uploadAttachments']);
            $v->post('/moje-oferty/{id:\d+}/{kind:zdjecia|dokumenty}/{item:\d+}', [ListingWizardController::class, 'mediaAction']);
            $v->post('/moje-oferty/{id:\d+}/wyslij', [ListingWizardController::class, 'submit']);
            $v->post('/moje-oferty/{id:\d+}/usun', [ListingWizardController::class, 'destroy']);
        });
    });

    // Zdjęcia i dokumenty z kontrolą dostępu (Gate) — także dla niezalogowanych (poziom 1)
    $r->get('/media/{id:\d+}/{size:400|960|1600}', [FileController::class, 'image']);
    $r->get('/plik/{id:\d+}', [FileController::class, 'attachment']);

    // Strony treści — trasa ogólna, MUSI być ostatnia (łapie każdy pojedynczy segment).
    $r->get('/{slug:[a-z0-9\-]+}', [PageController::class, 'show']);
});
