<?php

declare(strict_types=1);

/**
 * Dostęp do treści poufnych (poziom 3) — do czasu panelu admina (etap 5).
 *
 *   php bin/access.php                       prośby czekające na decyzję
 *   php bin/access.php --grant=7             nadaj dostęp (prośba #7)
 *   php bin/access.php --grant=7 --days=90   dostęp ważny 90 dni
 *   php bin/access.php --deny=7              odmów
 *   php bin/access.php --revoke=7            odbierz wcześniej nadany dostęp
 *
 * Użytkownik dostaje e-mail (przez kolejkę).
 */

require __DIR__ . '/_cli.php';

use App\Access\AccessService;

[$opts] = cli_args($argv);

try {
    if (isset($opts['grant'])) {
        $days = (int) ($opts['days'] ?? 0);
        AccessService::decide((int) $opts['grant'], true, null, $days > 0 ? gmdate('Y-m-d H:i:s', time() + $days * 86400) : null);
        ok('Nadano dostęp (prośba #' . (int) $opts['grant'] . ')' . ($days > 0 ? ", ważny {$days} dni." : '.'));
        exit(0);
    }
    if (isset($opts['deny'])) {
        AccessService::decide((int) $opts['deny'], false, null);
        ok('Odmówiono dostępu (prośba #' . (int) $opts['deny'] . ').');
        exit(0);
    }
    if (isset($opts['revoke'])) {
        AccessService::revoke((int) $opts['revoke'], null);
        ok('Odebrano dostęp (#' . (int) $opts['revoke'] . ').');
        exit(0);
    }
} catch (\RuntimeException $e) {
    fail($e->getMessage());
}

$pending = AccessService::pending();
if ($pending === []) {
    ok('Brak próśb o dostęp.');
    exit(0);
}
out('Prośby o dostęp: ' . count($pending));
foreach ($pending as $p) {
    out(sprintf('  #%-5d oferta #%d „%s”', $p['id'], $p['listing_id'], $p['title']));
    out(sprintf('         %s %s <%s>%s', $p['first_name'], $p['last_name'], $p['email'], $p['company'] ? ', ' . $p['company'] : ''));
    if ($p['message']) {
        out('         „' . str_replace("\n", ' ', $p['message']) . '”');
    }
}
