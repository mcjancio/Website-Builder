<?php

declare(strict_types=1);

/**
 * Kopia zapasowa: baza (.sql.gz) + pliki ofert (.tar.gz) w storage/backups, rotacja, opcjonalnie
 * wysyłka do magazynu S3 (config.php → backup.s3). Uruchamiaj z crona raz dziennie, np. o 3:17:
 *
 *   17 3 * * *  /usr/local/bin/php /home/KONTO/portal/bin/backup.php >/dev/null 2>&1
 *
 * Opcje:
 *   --no-files      tylko baza
 *   --keep=14       ile dni trzymać kopie lokalne (domyślnie backup.keep_days albo 14)
 *   --no-upload     nie wysyłaj poza serwer, nawet gdy S3 jest skonfigurowane
 */

require __DIR__ . '/_cli.php';

use App\Core\Backup;
use App\Core\Config;
use App\Core\Logger;
use App\Integrations\S3Storage;

[$opts] = cli_args($argv);
$keep = max(1, (int) ($opts['keep'] ?? Config::get('backup.keep_days', 14)));
$started = microtime(true);

try {
    $files = [Backup::dumpDatabase()];
    ok('Baza: ' . basename($files[0]) . ' (' . \App\Core\Str::bytes((int) filesize($files[0])) . ')');
    if (!isset($opts['no-files']) && Config::get('backup.include_files', true)) {
        $archive = Backup::archiveFiles();
        if ($archive !== null) {
            $files[] = $archive;
            ok('Pliki: ' . basename($archive) . ' (' . \App\Core\Str::bytes((int) filesize($archive)) . ')');
        } else {
            out('Pliki: brak plików do archiwizacji.');
        }
    }

    $s3 = isset($opts['no-upload']) ? null : S3Storage::fromConfig();
    if ($s3 !== null) {
        foreach ($files as $f) {
            ok('Wysłano poza serwer: ' . $s3->put($f));
        }
    } elseif (!isset($opts['no-upload'])) {
        warn('Kopia tylko na tym serwerze — skonfiguruj backup.s3 w config.php, żeby awaria serwera nie zabrała kopii.');
    }

    $removed = Backup::rotate($keep);
    $removed !== [] && out('Usunięto stare kopie: ' . implode(', ', $removed));
    Logger::info('Kopia zapasowa gotowa', ['files' => array_map('basename', $files), 'uploaded' => $s3 !== null, 'seconds' => round(microtime(true) - $started, 1)]);
} catch (\Throwable $e) {
    // Trafia do dziennego raportu błędów e-mailem.
    Logger::error('Kopia zapasowa nieudana', ['error' => $e->getMessage()]);
    fail('Kopia nieudana: ' . $e->getMessage());
}
