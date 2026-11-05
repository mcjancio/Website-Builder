<?php

declare(strict_types=1);

/**
 * Wspólny start aplikacji dla WWW (public/index.php) i CLI (bin/*.php).
 * Zwraca nic — po załadowaniu dostępne są klasy App\* i helpery z helpers.php.
 */

use App\Core\Config;
use App\Core\ErrorHandler;
use App\Core\Lang;
use App\Core\Logger;

if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    exit("Portal wymaga PHP 8.2 lub nowszego (jest " . PHP_VERSION . ").\n");
}

define('APP_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $file = APP_ROOT . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require APP_ROOT . '/app/helpers.php';

// Konfiguracja: PORTAL_CONFIG (testy, staging) albo config.php obok katalogu app/.
$configFile = getenv('PORTAL_CONFIG') ?: APP_ROOT . '/config.php';
if (!is_file($configFile)) {
    $msg = "Brak pliku konfiguracji: {$configFile}. Skopiuj config.example.php do config.php i uzupełnij dane.";
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $msg . "\n");
        exit(1);
    }
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Portal nie jest skonfigurowany.');
}
Config::load($configFile);

date_default_timezone_set('UTC'); // w bazie i w kodzie UTC; strefa portalu tylko przy wyświetlaniu
mb_internal_encoding('UTF-8');

Logger::init(APP_ROOT . '/storage/logs', (string) Config::get('app.log_level', 'info'));
Lang::load(APP_ROOT . '/lang/' . Config::get('app.locale', 'pl') . '.php');
ErrorHandler::register((bool) Config::get('app.debug', false));
