<?php

declare(strict_types=1);

/**
 * Import presetu branżowego (kategorie, pola ofert, opcje, strony treści, ustawienia).
 *
 *   php bin/import-preset.php presets/inwestycje-deweloperskie.json
 *
 * Opcje:
 *   --dry-run    pokaż, co by się zmieniło, bez zapisu
 *   --sync       ukryj (is_active = 0) kategorie, pola i opcje, których nie ma w pliku
 *   --overwrite  nadpisz strony treści i ustawienia zmienione później w panelu
 *   --validate   tylko sprawdź poprawność pliku (bez bazy)
 *
 * Import można powtarzać — niczego nie dubluje i niczego nie usuwa.
 */

require __DIR__ . '/_cli.php';

use App\Presets\Importer;
use App\Presets\PresetException;

[$opts, $pos] = cli_args($argv);
$path = $pos[0] ?? '';
if ($path === '') {
    fail('Podaj ścieżkę do presetu, np.: php bin/import-preset.php presets/inwestycje-deweloperskie.json');
}
if (!is_file($path) && is_file(APP_ROOT . '/' . $path)) {
    $path = APP_ROOT . '/' . $path;
}

try {
    $preset = Importer::readFile($path);
} catch (\RuntimeException $e) {
    fail($e->getMessage());
}

$errors = Importer::validate($preset);
if ($errors !== []) {
    fail("Preset zawiera błędy:\n - " . implode("\n - ", $errors));
}
if (isset($opts['validate'])) {
    ok(sprintf('Preset "%s" jest poprawny (%d pól, %d kategorii).',
        $preset['preset'], count($preset['fields']), count($preset['categories'] ?? [])));
    exit(0);
}

$importer = new Importer(sync: isset($opts['sync']), overwrite: isset($opts['overwrite']));
try {
    $report = $importer->import($preset, dryRun: isset($opts['dry-run']));
} catch (PresetException $e) {
    fail($e->getMessage());
} catch (\PDOException $e) {
    fail('Błąd bazy: ' . $e->getMessage() . "\nCzy wykonano migracje? php bin/migrate.php");
}

out(isset($opts['dry-run']) ? 'PRÓBA (bez zapisu) — zmiany, które zostałyby wprowadzone:' : "Import presetu \"{$preset['preset']}\":");
foreach ($report as $what => $n) {
    out(sprintf('  %-26s %d', $what, $n));
}
$changed = array_sum(array_filter(
    $report,
    static fn($k) => !str_ends_with($k, '.unchanged') && !str_ends_with($k, '.kept'),
    ARRAY_FILTER_USE_KEY
));
ok($changed === 0 ? 'Brak zmian — baza zgodna z presetem.' : "Zmian: {$changed}.");
if (!isset($opts['overwrite']) && (($report['pages.kept'] ?? 0) + ($report['settings.kept'] ?? 0)) > 0) {
    out('  (istniejące strony treści i ustawienia pozostawiono bez zmian — użyj --overwrite, aby je nadpisać)');
}
