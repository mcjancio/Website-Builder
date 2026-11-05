<?php

declare(strict_types=1);

/**
 * Raport problemów z ostatniej doby (błędy z logów, nieudane zadania, wiek kopii zapasowej).
 * Worker wysyła go sam raz na dobę — ten skrypt służy do podglądu i ręcznej wysyłki.
 *
 *   php bin/error-report.php            podgląd w konsoli
 *   php bin/error-report.php --send     wyślij e-mail do administratorów (tylko gdy są problemy)
 *   php bin/error-report.php --hours=72 inny zakres
 */

require __DIR__ . '/_cli.php';

use App\Core\ErrorReport;

[$opts] = cli_args($argv);
$since = time() - max(1, (int) ($opts['hours'] ?? 24)) * 3600;
$r = ErrorReport::collect($since);

out('Kopia zapasowa: ' . ($r['backup_age_h'] === null ? 'BRAK' : "{$r['backup_age_h']} h temu") . ($r['backup_stale'] ? '  ← za stara' : ''));
out("Nieudane zadania kolejki: {$r['failed_jobs']}");
out('Błędy w logach: ' . count($r['errors']));
foreach ($r['errors'] as $e) {
    out("  {$e['count']}×  {$e['message']}  (ostatnio {$e['last']})");
}
if (isset($opts['send'])) {
    $n = ErrorReport::send($since);
    $n > 0 ? ok("Raport w kolejce do {$n} administratorów.") : out('Brak problemów — nic nie wysłano.');
}
