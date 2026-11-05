<?php

declare(strict_types=1);

/**
 * Trasy portalu. Kolejne etapy dopisują tu swoje grupy (/oferty, /admin…).
 *
 * @var \App\Core\Router $router
 */

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AccountSettingsController;
use App\Http\Controllers\Admin;
use App\Http\Controllers\MemberController;
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
use App\Http\Middleware\RequireAdmin;
use App\Http\Middleware\RequireAuth;
use App\Http\Middleware\RequireStaff;
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
        $g->post('/oferta/{id:\d+}/obserwuj', [MemberController::class, 'toggleFavorite']);

        // Moje konto (etap 5)
        $g->get('/konto/profil', [AccountSettingsController::class, 'profile']);
        $g->post('/konto/profil', [AccountSettingsController::class, 'saveProfile']);
        $g->get('/konto/bezpieczenstwo', [AccountSettingsController::class, 'security']);
        $g->post('/konto/haslo', [AccountSettingsController::class, 'changePassword']);
        $g->post('/konto/urzadzenia/wyloguj', [AccountSettingsController::class, 'logoutDevices']);
        $g->get('/konto/usun', [AccountSettingsController::class, 'deleteForm']);
        $g->post('/konto/usun', [AccountSettingsController::class, 'delete']);
        $g->get('/konto/obserwowane', [MemberController::class, 'favorites']);
        $g->get('/konto/wyszukiwania', [MemberController::class, 'searches']);
        $g->post('/konto/wyszukiwania', [MemberController::class, 'saveSearch']);
        $g->post('/konto/wyszukiwania/{id:\d+}', [MemberController::class, 'updateSearch']);
        $g->get('/konto/dostepy', [MemberController::class, 'access']);

        // Oferty autora (etap 3): kreator, pliki, wysyłka do moderacji
        $g->get('/moje-oferty', [MyListingsController::class, 'index']);
        $g->get('/moje-oferty/{id:\d+}/podglad', [MyListingsController::class, 'preview']);
        $g->get('/moje-oferty/{id:\d+}/statystyki', [MyListingsController::class, 'stats']);
        $g->post('/moje-oferty/{id:\d+}/status', [MyListingsController::class, 'status']);
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

        // Panel administracyjny (etap 5): admin + moderator; sekcje ustawień tylko admin
        $g->group('/admin', [RequireStaff::class], static function ($a): void {
            $a->get('/', [Admin\DashboardController::class, 'index']);
            $a->get('/moderacja', [Admin\ModerationController::class, 'index']);
            $a->get('/moderacja/{id:\d+}', [Admin\ModerationController::class, 'show']);
            $a->post('/moderacja/{id:\d+}', [Admin\ModerationController::class, 'decide']);
            $a->get('/oferty', [Admin\ListingsAdminController::class, 'index']);
            $a->post('/oferty/{id:\d+}', [Admin\ListingsAdminController::class, 'action']);
            $a->get('/zapytania', [Admin\InquiriesController::class, 'index']);
            $a->get('/zapytania.csv', [Admin\InquiriesController::class, 'export']);
            $a->get('/zapytania/{id:\d+}', [Admin\InquiriesController::class, 'show']);
            $a->post('/zapytania/{id:\d+}', [Admin\InquiriesController::class, 'update']);
            $a->get('/dostepy', [Admin\AccessController::class, 'index']);
            $a->post('/dostepy/{id:\d+}', [Admin\AccessController::class, 'decide']);

            $a->group('', [RequireAdmin::class], static function ($s): void {
                $s->get('/uzytkownicy', [Admin\UsersController::class, 'index']);
                $s->get('/uzytkownicy.csv', [Admin\UsersController::class, 'export']);
                $s->get('/uzytkownicy/{id:\d+}', [Admin\UsersController::class, 'show']);
                $s->post('/uzytkownicy/{id:\d+}', [Admin\UsersController::class, 'action']);
                $s->get('/kryteria', [Admin\FieldsController::class, 'index']);
                $s->get('/kryteria/eksport.json', [Admin\FieldsController::class, 'export']);
                $s->get('/kryteria/nowe', [Admin\FieldsController::class, 'edit']);
                $s->post('/kryteria/nowe', [Admin\FieldsController::class, 'save']);
                $s->get('/kryteria/{id:\d+}', [Admin\FieldsController::class, 'edit']);
                $s->post('/kryteria/{id:\d+}', [Admin\FieldsController::class, 'save']);
                $s->post('/kryteria/{id:\d+}/opcje', [Admin\FieldsController::class, 'saveOption']);
                $s->post('/kryteria/{kind:pole|opcja|kategoria}/{id:\d+}', [Admin\FieldsController::class, 'action']);
                $s->post('/kategorie', [Admin\FieldsController::class, 'saveCategory']);
                $s->get('/strony', [Admin\ContentController::class, 'pages']);
                $s->get('/strony/nowa', [Admin\ContentController::class, 'editPage']);
                $s->post('/strony/nowa', [Admin\ContentController::class, 'savePage']);
                $s->get('/strony/{id:\d+}', [Admin\ContentController::class, 'editPage']);
                $s->post('/strony/{id:\d+}', [Admin\ContentController::class, 'savePage']);
                $s->get('/ustawienia', [Admin\ContentController::class, 'settings']);
                $s->post('/ustawienia', [Admin\ContentController::class, 'saveSettings']);
                $s->post('/tryb-kampanii', [Admin\ContentController::class, 'campaign']);
                $s->get('/dziennik', [Admin\ContentController::class, 'audit']);
            });
        });
    });

    // Zdjęcia i dokumenty z kontrolą dostępu (Gate) — także dla niezalogowanych (poziom 1)
    $r->get('/media/{id:\d+}/{size:400|960|1600}', [FileController::class, 'image']);
    $r->get('/plik/{id:\d+}', [FileController::class, 'attachment']);

    // Strony treści — trasa ogólna, MUSI być ostatnia (łapie każdy pojedynczy segment).
    $r->get('/{slug:[a-z0-9\-]+}', [PageController::class, 'show']);
});
