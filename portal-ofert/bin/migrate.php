<?php

declare(strict_types=1);

/**
 * Migracje bazy.
 *   php bin/migrate.php            — wykonuje oczekujące migracje
 *   php bin/migrate.php --status   — pokazuje stan bez zmian
 */

require __DIR__ . '/_cli.php';

use App\Core\Migrator;

[$opts] = cli_args($argv);
$migrator = new Migrator(APP_ROOT . '/migrations');

try {
    $applied = $migrator->applied();
} catch (\PDOException $e) {
    fail('Brak połączenia z bazą: ' . $e->getMessage() . "\nSprawdź sekcję 'db' w config.php.");
}

if (isset($opts['status'])) {
    foreach ($migrator->available() as $v) {
        out((in_array($v, $applied, true) ? '  [x] ' : '  [ ] ') . $v);
    }
    $pending = $migrator->pending();
    out($pending === [] ? 'Baza jest aktualna.' : 'Oczekujące migracje: ' . count($pending));
    exit(0);
}

$pending = $migrator->pending();
if ($pending === []) {
    ok('Baza jest aktualna — brak migracji do wykonania.');
    exit(0);
}

if ($applied !== [] && config('app.env') === 'production') {
    warn('Produkcja: migracji DDL nie da się wycofać automatycznie. Upewnij się, że masz świeżą kopię bazy.');
}

try {
    $migrator->migrate(static fn(string $v, int $n) => ok("{$v} ({$n} instrukcji)"));
} catch (\RuntimeException $e) {
    fail($e->getMessage());
}
ok('Migracje zakończone.');
