<?php

declare(strict_types=1);

/**
 * Jedyny punkt wejścia WWW (front controller).
 *
 * Na cPanelu ten katalog (public/) jest kopiowany do public_html/<subdomena>/,
 * a reszta aplikacji leży POZA public_html (np. /home/konto/portal).
 * Ścieżkę do aplikacji wskazuje (w kolejności):
 *   1. zmienna środowiskowa PORTAL_ROOT,
 *   2. plik .portal-root obok tego pliku (zapisuje go wdrożenie; .htaccess blokuje do niego dostęp),
 *   3. katalog nadrzędny (układ repozytorium / lokalny serwer deweloperski).
 */

// Wbudowany serwer PHP (php -S): pliki statyczne serwuje sam.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if ($file !== __FILE__ && is_file($file)) {
        return false;
    }
}

$root = getenv('PORTAL_ROOT') ?: '';
if ($root === '' && is_file(__DIR__ . '/.portal-root')) {
    $root = trim((string) file_get_contents(__DIR__ . '/.portal-root'));
}
if ($root === '') {
    $root = dirname(__DIR__);
}
if (!is_file($root . '/app/bootstrap.php')) {
    http_response_code(500);
    exit('Nie znaleziono aplikacji portalu.');
}

require $root . '/app/bootstrap.php';

$router = new App\Core\Router();
require APP_ROOT . '/app/routes.php';

try {
    $response = $router->dispatch(App\Core\Request::fromGlobals());
} catch (Throwable $e) {
    App\Core\ErrorHandler::handle($e);
    exit;
}
$response->send();
