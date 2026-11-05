<?php

declare(strict_types=1);

/**
 * Import z dotychczasowej strony WordPress. Pełny opis: docs/IMPORT-WP.md
 *
 *   1. Rozpoznanie (nic nie zmienia):
 *      php bin/import-wp.php --discover
 *      php bin/import-wp.php --discover --post-type=product --write-mapping=storage/import/mapowanie.json
 *   2. Próba (wszystko w transakcji wycofanej na końcu, bez kopiowania plików) + raport:
 *      php bin/import-wp.php --mapping=storage/import/mapowanie.json --dry-run
 *   3. Import (można powtarzać — przeniesione rekordy są pomijane):
 *      php bin/import-wp.php --mapping=storage/import/mapowanie.json [--owner=admin@example.pl]
 *   4. Zaproszenia do ustawienia hasła (po starcie nowej wersji):
 *      php bin/import-wp.php --send-invites [--limit=200] [--days=14]
 *
 * Połączenie z bazą WordPressa: config.php → import_wp.
 */

require __DIR__ . '/_cli.php';

use App\Core\Config;
use App\Core\DB;
use App\Import\WpImporter;

[$opts] = cli_args($argv);

if (isset($opts['send-invites'])) {
    $n = WpImporter::sendInvites((int) ($opts['limit'] ?? 200), (int) ($opts['days'] ?? 14));
    ok("Zaproszenia w kolejce: {$n}. Wyśle je worker (cron) w ciągu minuty.");
    exit(0);
}

$c = (array) Config::get('import_wp', []);
if (trim((string) ($c['name'] ?? '')) === '') {
    fail('Uzupełnij config.php → import_wp (baza WordPressa, katalog uploads).');
}
$dsn = !empty($c['socket'])
    ? "mysql:unix_socket={$c['socket']};dbname={$c['name']};charset=utf8mb4"
    : 'mysql:host=' . ($c['host'] ?? 'localhost') . ';port=' . (int) ($c['port'] ?? 3306) . ";dbname={$c['name']};charset=utf8mb4";
try {
    $wp = new PDO($dsn, (string) ($c['user'] ?? ''), (string) ($c['pass'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    fail('Brak połączenia z bazą WordPressa: ' . $e->getMessage());
}
$prefix = (string) ($c['prefix'] ?? 'wp_');
$uploads = rtrim((string) ($c['uploads'] ?? ''), '/');

if (isset($opts['discover'])) {
    $postType = is_string($opts['post-type'] ?? null) ? $opts['post-type'] : null;
    $d = (new WpImporter($wp, $prefix, [], $uploads))->discover($postType);
    out('== Typy wpisów (post_type / status / liczba)');
    foreach ($d['post_types'] as $r) {
        out(sprintf('  %-28s %-10s %6d', $r['post_type'], $r['post_status'], $r['n']));
    }
    out('== Role kont');
    foreach ($d['user_roles'] as $role => $n) {
        out(sprintf('  %-28s %6d', $role, $n));
    }
    out('== Dane kont (usermeta)');
    foreach ($d['user_meta'] as $r) {
        out(sprintf('  %-32s %6d  %s', $r['meta_key'], $r['n'], mb_substr((string) $r['sample'], 0, 40)));
    }
    if ($postType !== null) {
        out("== Pola wpisów „{$postType}” (postmeta)");
        foreach ($d['meta'] as $r) {
            out(sprintf('  %-32s %6d  %s', $r['meta_key'], $r['n'], mb_substr(str_replace("\n", ' ', (string) $r['sample']), 0, 50)));
        }
        out('== Taksonomie (kategorie, atrybuty)');
        foreach ($d['taxonomies'] as $r) {
            out(sprintf('  %-24s %-30s %6d  %s', $r['taxonomy'], $r['slug'], $r['n'], $r['name']));
        }
        if (is_string($opts['write-mapping'] ?? null)) {
            $skeleton = [
                'post_type' => $postType,
                'statuses' => ['publish' => 'published', 'draft' => 'draft', 'pending' => 'pending', 'private' => 'paused'],
                'category' => ['taxonomy' => $d['taxonomies'][0]['taxonomy'] ?? 'category', 'map' => (object) [], 'default' => ''],
                'core' => ['title' => 'post_title', 'short_desc' => 'post_excerpt', 'full_desc_md' => 'post_content'],
                'fields' => (object) [],
                'images' => ['featured' => true, 'gallery_meta' => '', 'from_content' => true],
                'attachments' => [],
                'users' => ['roles' => array_values(array_diff(array_keys($d['user_roles']), ['administrator'])),
                    'meta' => ['first_name' => 'first_name', 'last_name' => 'last_name', 'phone' => 'billing_phone', 'company_name' => 'billing_company', 'nip' => ''],
                    'account_type' => ['from' => '', 'values' => (object) [], 'default' => '']],
                'redirects' => ['paths' => ['/{slug}/'], 'query' => ['p']],
                '_znalezione_pola' => array_column($d['meta'], 'sample', 'meta_key'),
                '_znalezione_kategorie' => array_values(array_unique(array_map(static fn($t) => $t['taxonomy'] . ': ' . $t['slug'], $d['taxonomies']))),
            ];
            @mkdir(dirname($opts['write-mapping']), 0750, true);
            file_put_contents($opts['write-mapping'], json_encode($skeleton, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            ok("Szkielet mapowania: {$opts['write-mapping']} — uzupełnij „fields” i „category.map”.");
        }
    }
    exit(0);
}

$mappingFile = is_string($opts['mapping'] ?? null) ? $opts['mapping'] : fail('Podaj --mapping=plik.json (albo zacznij od --discover).');
$map = json_decode((string) @file_get_contents($mappingFile), true);
if (!is_array($map)) {
    fail("Nie mogę odczytać JSON z {$mappingFile}");
}
if ($uploads === '' || !is_dir($uploads)) {
    warn('Katalog uploads (import_wp.uploads) nie istnieje — zdjęcia i dokumenty nie zostaną przeniesione.');
}
$owner = null;
if (is_string($opts['owner'] ?? null)) {
    $owner = DB::value('SELECT id FROM users WHERE email = ?', [mb_strtolower($opts['owner'])]);
    $owner !== null || fail("Nie ma konta {$opts['owner']}");
}
$dry = isset($opts['dry-run']);
$importer = new WpImporter($wp, $prefix, $map, $uploads, $dry, $owner !== null ? (int) $owner : null);
$r = $importer->run();

$dir = APP_ROOT . '/storage/import';
@mkdir($dir, 0750, true);
$stamp = gmdate('Ymd-His');
$reportFile = "{$dir}/raport-wp-{$stamp}" . ($dry ? '-proba' : '') . '.md';
file_put_contents($reportFile, App\Import\ImportReport::markdown($r, $dry));
if (!$dry && $r['redirects'] !== []) {
    file_put_contents("{$dir}/przekierowania-wp.htaccess", WpImporter::htaccess($r['redirects']));
}

out(($dry ? '[PRÓBA — nic nie zapisano] ' : '') . sprintf(
    'Konta: %d nowych, %d już istniało, %d pominiętych. Oferty: %d nowych, %d już istniało, %d pominiętych. Zdjęcia: %d, dokumenty: %d.',
    $r['users']['imported'], $r['users']['existing'], count($r['users']['skipped']),
    $r['listings']['imported'], $r['listings']['existing'], count($r['listings']['skipped']),
    $r['media']['images'], $r['media']['attachments']
));
$problems = count($r['values']['rejected']) + count($r['values']['unknown_options']) + count($r['unmapped_meta']) + count($r['media']['missing']) + count($r['unmapped_terms']);
$problems > 0 ? warn("Do sprawdzenia: {$problems} pozycji — szczegóły w raporcie.") : ok('Brak niezmapowanych danych.');
ok('Raport: ' . $reportFile);
if (!$dry && $r['redirects'] !== []) {
    ok("Przekierowania 301: {$dir}/przekierowania-wp.htaccess");
}
