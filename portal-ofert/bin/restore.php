<?php

declare(strict_types=1);

/**
 * Odtworzenie kopii — na stagingu (test raz na kwartał) albo po awarii.
 * NADPISUJE bazę wskazaną w config.php i pliki w storage/uploads.
 *
 *   php bin/restore.php --list
 *   php bin/restore.php storage/backups/baza-20261105-031700.sql.gz [storage/backups/pliki-….tar.gz] --yes
 */

require __DIR__ . '/_cli.php';

use App\Core\Backup;
use App\Core\Config;
use App\Core\Migrator;

[$opts, $args] = cli_args($argv);

if (isset($opts['list']) || $args === []) {
    $list = glob(Backup::dir() . '/*.gz') ?: [];
    rsort($list);
    out($list === [] ? 'Brak kopii w storage/backups.' : implode(PHP_EOL, array_map(static fn($f) => basename($f) . '  ' . \App\Core\Str::bytes((int) filesize($f)), $list)));
    out('Użycie: php bin/restore.php <baza.sql.gz> [pliki.tar.gz] --yes');
    exit(0);
}

$db = $args[0];
$files = $args[1] ?? null;
foreach (array_filter([$db, $files]) as $f) {
    if (!is_file($f)) {
        fail("Nie ma pliku {$f}");
    }
}
$target = Config::get('db.name') . ' (' . Config::get('app.env') . ')';
if (!isset($opts['yes']) && strtolower(ask("Nadpisać bazę {$target}? Wpisz TAK", '')) !== 'tak') {
    fail('Przerwano.');
}
if (Config::get('app.env') === 'production') {
    warn('To jest PRODUKCJA. Upewnij się, że to odtworzenie po awarii, a nie test.');
}

$n = Backup::restoreDatabase($db);
ok("Baza odtworzona ({$n} instrukcji).");
if ($files !== null) {
    Backup::restoreFiles($files);
    ok('Pliki odtworzone w storage/uploads.');
}
$pending = (new Migrator(APP_ROOT . '/migrations'))->pending();
if ($pending !== []) {
    warn('Kopia jest starsza niż kod — uruchom: php bin/migrate.php (' . implode(', ', $pending) . ')');
}
out('Sprawdź /health i zaloguj się do panelu.');
