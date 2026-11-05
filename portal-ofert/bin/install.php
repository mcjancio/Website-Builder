<?php

declare(strict_types=1);

/**
 * Instalator — przeprowadza przez pierwszą instalację (i bezpiecznie powtarza się przy kolejnych).
 *
 *   php bin/install.php --preset=presets/inwestycje-deweloperskie.json
 *
 * Kroki: 1) wymagania serwera  2) połączenie z bazą  3) migracje  4) import presetu
 *        5) konto administratora (jeśli żadnego nie ma)  6) podsumowanie: cron i adres /health
 *
 * Opcje: --check (tylko krok 1–2), --no-admin (pomiń krok 5).
 */

require __DIR__ . '/_cli.php';

use App\Core\DB;
use App\Core\Migrator;
use App\Presets\Importer;

[$opts] = cli_args($argv);
$php = PHP_BINARY ?: 'php';

out('== 1. Wymagania serwera ==');
$problems = 0;
$check = static function (bool $pass, string $label, string $hint = '') use (&$problems): void {
    if ($pass) {
        ok($label);
    } else {
        $problems++;
        warn($label . ($hint !== '' ? " — {$hint}" : ''));
    }
};
$check(PHP_VERSION_ID >= 80200, 'PHP ' . PHP_VERSION, 'wymagane 8.2+ (cPanel → MultiPHP Manager)');
foreach (['pdo_mysql', 'mbstring', 'json', 'curl', 'openssl', 'fileinfo', 'intl'] as $ext) {
    $check(extension_loaded($ext), "rozszerzenie {$ext}", 'włącz w cPanel → Select PHP Version → Extensions');
}
$check(extension_loaded('gd') || extension_loaded('imagick'), 'gd lub imagick (obrazy WebP)', 'włącz gd');
$check(extension_loaded('sodium'), 'sodium (szyfrowanie sekretów 2FA)', 'włącz sodium');
$check(defined('PASSWORD_ARGON2ID'), 'hasła Argon2id', 'brak — użyty zostanie bcrypt (akceptowalne)');
foreach (['logs', 'cache', 'uploads', 'sessions', 'backups'] as $dir) {
    $path = APP_ROOT . '/storage/' . $dir;
    if (!is_dir($path)) {
        @mkdir($path, 0750, true);
    }
    $check(is_dir($path) && is_writable($path), "zapis do storage/{$dir}", 'chmod 750 i właściciel = konto cPanel');
}
$cfg = getenv('PORTAL_CONFIG') ?: APP_ROOT . '/config.php';
$perms = fileperms($cfg) & 0777;
$check(($perms & 0077) === 0 || DIRECTORY_SEPARATOR === '\\', sprintf('uprawnienia config.php (%o)', $perms), 'ustaw chmod 600 config.php');
$check(config('app.url') !== 'https://inwestycje.example.pl', 'app.url ustawione', 'uzupełnij app.url w config.php');
$check(strlen((string) base64_decode((string) config('app.key'), true)) === 32, 'app.key (32 bajty base64)',
    'wygeneruj: php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"');
if (config('app.debug') && config('app.env') === 'production') {
    $check(false, 'app.debug wyłączone na produkcji', 'ustaw debug => false');
}

out('');
out('== 2. Baza danych ==');
try {
    $version = (string) DB::value('SELECT VERSION()');
    ok("Połączono: {$version}");
    $isMaria = stripos($version, 'mariadb') !== false;
    $num = (string) preg_replace('/^(\d+\.\d+).*/', '$1', $version);
    $check($isMaria ? version_compare($num, '10.6', '>=') : version_compare($num, '8.0', '>='),
        'wersja bazy', 'wymagane MySQL 8.0+ lub MariaDB 10.6+ (SKIP LOCKED, JSON)');
    $charset = (string) DB::value('SELECT @@character_set_database');
    $check($charset === 'utf8mb4', "kodowanie bazy: {$charset}", 'utwórz bazę z utf8mb4 (collation utf8mb4_polish_ci)');
} catch (\PDOException $e) {
    fail('Brak połączenia z bazą: ' . $e->getMessage() . "\nUzupełnij sekcję 'db' w config.php (cPanel → Bazy danych MySQL).");
}

if (isset($opts['check'])) {
    out('');
    $problems === 0 ? ok('Serwer gotowy do instalacji.') : warn("Do poprawienia: {$problems}.");
    exit($problems === 0 ? 0 : 1);
}
if ($problems > 0) {
    warn("Wykryto {$problems} problem(ów). Instalacja będzie kontynuowana, ale popraw je przed startem produkcyjnym.");
}

out('');
out('== 3. Migracje ==');
$migrator = new Migrator(APP_ROOT . '/migrations');
$done = $migrator->migrate(static fn(string $v, int $n) => ok("{$v} ({$n} instrukcji)"));
if ($done === []) {
    ok('Baza aktualna.');
}

out('');
out('== 4. Preset ==');
$presetPath = is_string($opts['preset'] ?? null) ? $opts['preset'] : '';
if ($presetPath === '') {
    $current = DB::value("SELECT JSON_UNQUOTE(JSON_EXTRACT(`value`, '$.name')) FROM settings WHERE `key` = 'preset'");
    if ($current) {
        ok("Zainstalowany preset: {$current} (aby zaktualizować: --preset=plik.json)");
    } else {
        $files = array_map(static fn($f) => 'presets/' . basename($f), glob(APP_ROOT . '/presets/*.json') ?: []);
        out('Dostępne presety: ' . implode(', ', $files));
        $presetPath = ask('Który preset zainstalować?', $files[0] ?? '');
    }
}
if ($presetPath !== '') {
    $full = is_file($presetPath) ? $presetPath : APP_ROOT . '/' . $presetPath;
    try {
        $report = (new Importer())->import(Importer::readFile($full));
    } catch (\RuntimeException $e) {
        fail($e->getMessage());
    }
    ok(sprintf('Zaimportowano %s: pola %d, kategorie %d, opcje %d.',
        basename($full),
        ($report['fields.created'] ?? 0) + ($report['fields.updated'] ?? 0) + ($report['fields.unchanged'] ?? 0),
        ($report['categories.created'] ?? 0) + ($report['categories.updated'] ?? 0) + ($report['categories.unchanged'] ?? 0),
        ($report['options.created'] ?? 0) + ($report['options.updated'] ?? 0) + ($report['options.unchanged'] ?? 0)));
}

out('');
out('== 5. Administrator ==');
$admins = (int) DB::value("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'");
if ($admins > 0) {
    ok("Konta administratorów: {$admins}.");
} elseif (isset($opts['no-admin'])) {
    warn("Brak administratora. Utwórz: {$php} bin/create-admin.php");
} else {
    passthru(escapeshellarg($php) . ' ' . escapeshellarg(APP_ROOT . '/bin/create-admin.php'), $code);
    if ($code !== 0) {
        warn("Nie utworzono administratora. Możesz to zrobić później: {$php} bin/create-admin.php");
    }
}

out('');
out('== 6. Do zrobienia w cPanelu ==');
out('  • Cron (cPanel → Zadania Cron), co minutę:');
out('      * * * * * ' . $php . ' ' . APP_ROOT . '/bin/worker.php >/dev/null 2>&1');
out('  • Monitoring (UptimeRobot / Better Stack): ' . rtrim((string) config('app.url'), '/') . '/health');
out('  • SSL: cPanel → SSL/TLS Status → AutoSSL dla subdomeny');
out('');
ok('Instalacja zakończona.');
