<?php

declare(strict_types=1);

/**
 * Moderacja z wiersza poleceń — do czasu panelu admina (etap 5).
 *
 *   php bin/moderate.php                         lista ofert i zmian czekających na akceptację
 *   php bin/moderate.php --approve=12            akceptacja (nowa oferta albo zmiany w opublikowanej)
 *   php bin/moderate.php --reject=12 --reason="Dodaj zdjęcia i uzupełnij harmonogram."
 *
 * Autor dostaje e-mail (przez kolejkę — worker z crona).
 */

require __DIR__ . '/_cli.php';

use App\Listings\ListingService;

[$opts] = cli_args($argv);
$base = rtrim((string) config('app.url'), '/');

if (isset($opts['approve'])) {
    try {
        $kind = ListingService::approve((int) $opts['approve'], null);
    } catch (\RuntimeException $e) {
        fail($e->getMessage());
    }
    ok(sprintf('Zaakceptowano %s #%d.', $kind === 'new' ? 'ofertę' : 'zmiany w ofercie', (int) $opts['approve']));
    exit(0);
}

if (isset($opts['reject'])) {
    $reason = is_string($opts['reason'] ?? null) ? trim($opts['reason']) : '';
    if ($reason === '') {
        fail('Podaj powód odrzucenia: --reason="…" (autor zobaczy go w e-mailu i przy ofercie).');
    }
    try {
        $kind = ListingService::reject((int) $opts['reject'], null, $reason);
    } catch (\RuntimeException $e) {
        fail($e->getMessage());
    }
    ok(sprintf('Odrzucono %s #%d.', $kind === 'new' ? 'ofertę' : 'zmiany w ofercie', (int) $opts['reject']));
    exit(0);
}

$queue = ListingService::pendingQueue();
if ($queue === []) {
    ok('Nic nie czeka na moderację.');
    exit(0);
}
out('Czeka na moderację: ' . count($queue));
foreach ($queue as $q) {
    out(sprintf('  #%-5d %-8s %s  (%s, wysłano %s UTC)', $q['id'], $q['kind'] === 'new' ? 'NOWA' : 'ZMIANY', $q['title'], $q['email'], $q['submitted_at']));
    out('         podgląd: ' . $base . url('/moje-oferty/' . $q['id'] . '/podglad'));
}
