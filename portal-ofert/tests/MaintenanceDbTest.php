<?php

declare(strict_types=1);

/**
 * Etap 6: kopia zapasowa i odtworzenie, raport problemów, import z WordPressa (konta, oferty, pola,
 * zdjęcia, dokumenty, przekierowania 301, raport niezmapowanych danych, zaproszenia).
 * WordPress jest symulowany tabelami z prefiksem wpt_ w bazie testowej.
 */

use App\Core\Backup;
use App\Core\DB;
use App\Core\ErrorReport;
use App\Core\Mailer;
use App\Import\ImportReport;
use App\Import\WpImporter;
use App\Integrations\S3Storage;
use App\Listings\ValueStore;

function wp_fixture(): string
{
    $p = 'wpt_';
    $sql = [
        "CREATE TABLE {$p}posts (ID BIGINT PRIMARY KEY, post_author BIGINT, post_date DATETIME, post_date_gmt DATETIME, post_content LONGTEXT, post_title TEXT, post_excerpt TEXT, post_status VARCHAR(20), post_name VARCHAR(200), post_type VARCHAR(20), guid VARCHAR(255) DEFAULT '')",
        "CREATE TABLE {$p}postmeta (meta_id BIGINT AUTO_INCREMENT PRIMARY KEY, post_id BIGINT, meta_key VARCHAR(255), meta_value LONGTEXT)",
        "CREATE TABLE {$p}users (ID BIGINT PRIMARY KEY, user_email VARCHAR(100), user_registered DATETIME, display_name VARCHAR(250))",
        "CREATE TABLE {$p}usermeta (umeta_id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id BIGINT, meta_key VARCHAR(255), meta_value LONGTEXT)",
        "CREATE TABLE {$p}terms (term_id BIGINT PRIMARY KEY, name VARCHAR(200), slug VARCHAR(200))",
        "CREATE TABLE {$p}term_taxonomy (term_taxonomy_id BIGINT PRIMARY KEY, term_id BIGINT, taxonomy VARCHAR(32))",
        "CREATE TABLE {$p}term_relationships (object_id BIGINT, term_taxonomy_id BIGINT, term_order INT DEFAULT 0)",
    ];
    foreach ($sql as $s) {
        DB::pdo()->exec($s);
    }
    $ins = static fn(string $t, array $row) => DB::insert($p . $t, $row);
    $caps = static fn(string $role) => serialize([$role => true]);

    // Konta: admin (pomijany), klient z telefonem i NIP, klient bez telefonu, zły e-mail
    $ins('users', ['ID' => 1, 'user_email' => 'admin@stara.pl', 'user_registered' => '2023-01-01 10:00:00', 'display_name' => 'Admin']);
    $ins('usermeta', ['user_id' => 1, 'meta_key' => 'wpt_capabilities', 'meta_value' => $caps('administrator')]);
    $ins('users', ['ID' => 2, 'user_email' => 'Deweloper@Firma.pl', 'user_registered' => '2024-03-05 08:30:00', 'display_name' => 'Piotr Budowlany']);
    foreach (['wpt_capabilities' => $caps('customer'), 'first_name' => 'Piotr', 'last_name' => 'Budowlany', 'billing_phone' => '601 222 333',
        'billing_company' => 'Budowlany Sp. z o.o.', 'billing_nip' => '526-025-02-74', 'typ_konta' => 'Deweloper'] as $k => $v) {
        $ins('usermeta', ['user_id' => 2, 'meta_key' => $k, 'meta_value' => $v]);
    }
    $ins('users', ['ID' => 3, 'user_email' => 'inwestor@poczta.pl', 'user_registered' => '2024-06-01 12:00:00', 'display_name' => 'Ola Inwestorka']);
    $ins('usermeta', ['user_id' => 3, 'meta_key' => 'wpt_capabilities', 'meta_value' => $caps('subscriber')]);
    $ins('users', ['ID' => 4, 'user_email' => 'to-nie-email', 'user_registered' => '2024-06-01 12:00:00', 'display_name' => 'X']);
    $ins('usermeta', ['user_id' => 4, 'meta_key' => 'wpt_capabilities', 'meta_value' => $caps('customer')]);

    // Kategorie (taksonomia product_cat) i atrybut formy inwestycji
    $ins('terms', ['term_id' => 10, 'name' => 'Kapitał', 'slug' => 'kapital']);
    $ins('term_taxonomy', ['term_taxonomy_id' => 10, 'term_id' => 10, 'taxonomy' => 'product_cat']);
    $ins('terms', ['term_id' => 11, 'name' => 'Inne', 'slug' => 'inne']);
    $ins('term_taxonomy', ['term_taxonomy_id' => 11, 'term_id' => 11, 'taxonomy' => 'product_cat']);
    $ins('terms', ['term_id' => 20, 'name' => 'Obligacje', 'slug' => 'obligacje']);
    $ins('term_taxonomy', ['term_taxonomy_id' => 20, 'term_id' => 20, 'taxonomy' => 'pa_forma']);
    $ins('terms', ['term_id' => 21, 'name' => 'Pożyczka', 'slug' => 'pozyczka']);
    $ins('term_taxonomy', ['term_taxonomy_id' => 21, 'term_id' => 21, 'taxonomy' => 'pa_forma']);

    // Pliki w „wp-content/uploads”
    $uploads = tmp_dir() . '/wp-uploads';
    @mkdir($uploads . '/2024/05', 0700, true);
    copy(make_image('wp-a.jpg', 1200, 800), $uploads . '/2024/05/osiedle.jpg');
    copy(make_image('wp-b.jpg', 1000, 700), $uploads . '/2024/05/wizualizacja.jpg');
    copy(fake_pdf('wp-teaser.pdf'), $uploads . '/2024/05/teaser.pdf');
    foreach ([100 => '2024/05/osiedle.jpg', 101 => '2024/05/wizualizacja.jpg', 102 => '2024/05/teaser.pdf', 103 => '2024/05/brak.jpg'] as $id => $file) {
        $ins('posts', ['ID' => $id, 'post_author' => 2, 'post_date' => '2024-05-01 10:00:00', 'post_date_gmt' => '2024-05-01 08:00:00', 'post_content' => '', 'post_title' => basename($file), 'post_excerpt' => '', 'post_status' => 'inherit', 'post_name' => 'f' . $id, 'post_type' => 'attachment']);
        $ins('postmeta', ['post_id' => $id, 'meta_key' => '_wp_attached_file', 'meta_value' => $file]);
    }

    // Oferty (WooCommerce product)
    $ins('posts', ['ID' => 500, 'post_author' => 2, 'post_date' => '2024-05-10 14:00:00', 'post_date_gmt' => '2024-05-10 12:00:00',
        'post_content' => '<h2>Lokalizacja</h2><p>Osiedle przy <strong>parku</strong>.</p>[vc_row]<ul><li>36 lokali</li><li>PnB</li></ul>',
        'post_title' => 'Osiedle Zielone &#8211; Poznań', 'post_excerpt' => '', 'post_status' => 'publish', 'post_name' => 'osiedle-zielone-poznan', 'post_type' => 'product']);
    foreach (['_thumbnail_id' => '100', '_product_image_gallery' => '101,103', 'typ' => 'Mieszkaniowa wielorodzinna', 'wojewodztwo' => 'Wielkopolskie',
        'miasto' => 'Poznań', 'kwota' => '2 500 000 zł', 'koszt' => '29500000', 'etap' => 'PnB', 'okres' => 'średni', 'roi' => '18,5%', 'esg' => 'tak',
        'teaser' => '102', 'dane_poufne' => '<p>Działka 123/4</p>', 'stare_pole' => 'coś', '_edit_lock' => '1'] as $k => $v) {
        $ins('postmeta', ['post_id' => 500, 'meta_key' => $k, 'meta_value' => $v]);
    }
    $ins('term_relationships', ['object_id' => 500, 'term_taxonomy_id' => 10]);
    $ins('term_relationships', ['object_id' => 500, 'term_taxonomy_id' => 20]);
    $ins('term_relationships', ['object_id' => 500, 'term_taxonomy_id' => 21]);

    $ins('posts', ['ID' => 501, 'post_author' => 1, 'post_date' => '2024-07-01 09:00:00', 'post_date_gmt' => '2024-07-01 07:00:00',
        'post_content' => "Biurowiec w centrum.\n\nDrugi akapit.", 'post_title' => 'Biurowiec Kraków', 'post_excerpt' => 'Biurowiec klasy A.',
        'post_status' => 'draft', 'post_name' => 'biurowiec-krakow', 'post_type' => 'product']);
    foreach (['typ' => 'Biurowiec XXL', 'wojewodztwo' => 'małopolskie', 'kwota' => 'dużo'] as $k => $v) {
        $ins('postmeta', ['post_id' => 501, 'meta_key' => $k, 'meta_value' => $v]);
    }
    $ins('term_relationships', ['object_id' => 501, 'term_taxonomy_id' => 11]);
    // wpis innego typu — nie importujemy
    $ins('posts', ['ID' => 600, 'post_author' => 1, 'post_date' => '2024-01-01', 'post_date_gmt' => '2024-01-01', 'post_content' => 'x', 'post_title' => 'Blog', 'post_excerpt' => '', 'post_status' => 'publish', 'post_name' => 'blog', 'post_type' => 'post']);
    return $uploads;
}

function wp_mapping(): array
{
    return [
        'post_type' => 'product',
        'category' => ['taxonomy' => 'product_cat', 'map' => ['kapital' => 'pozyskanie-kapitalu'], 'default' => 'sprzedaz-projektu'],
        'core' => ['title' => 'post_title', 'short_desc' => 'post_excerpt', 'full_desc_md' => 'post_content', 'confidential_md' => 'meta:dane_poufne'],
        'fields' => [
            'investment_type' => ['from' => 'meta:typ'],
            'region' => 'meta:wojewodztwo',
            'city' => 'meta:miasto',
            'capital_needed' => ['from' => 'meta:kwota', 'currency' => 'PLN'],
            'project_cost' => ['from' => 'meta:koszt'],
            'project_stage' => ['from' => 'meta:etap', 'values' => ['PnB' => 'permit']],
            'investment_period' => ['from' => 'meta:okres', 'values' => ['średni' => 'medium']],
            'expected_roi' => 'meta:roi',
            'esg' => 'meta:esg',
            'investment_form' => ['from' => 'taxonomy:pa_forma', 'values' => ['Obligacje' => 'bonds', 'Pożyczka' => 'loan']],
        ],
        'images' => ['featured' => true, 'gallery_meta' => '_product_image_gallery'],
        'attachments' => [['from' => 'meta:teaser', 'label' => 'Teaser', 'access_level' => 2]],
        'users' => ['roles' => ['customer', 'subscriber'], 'meta' => ['phone' => 'billing_phone', 'company_name' => 'billing_company', 'nip' => 'billing_nip'],
            'account_type' => ['from' => 'meta:typ_konta', 'values' => ['Deweloper' => 'developer'], 'default' => 'investor']],
        'redirects' => ['paths' => ['/inwestycje/{slug}/', '/produkt/{slug}/'], 'query' => ['p']],
    ];
}

return [
    'Kopia zapasowa: zrzut bazy i plików, odtworzenie przywraca dane (także binarne i znaki specjalne), rotacja' => function (): void {
        auth_setup();
        $uid = create_user("o'brien@example.pl", 'haslo-testowe-123');
        DB::update('users', ['first_name' => "Zażółć \"gęślą\"\njaźń; \\ test", 'totp_secret' => "\x00\x01\xFF bin"], ['id' => $uid]);
        DB::insert('audit_log', ['action' => 'test.ip', 'ip' => inet_pton('2001:db8::1')]);
        @mkdir(APP_ROOT . '/storage/uploads/img/zz', 0750, true);
        file_put_contents(APP_ROOT . '/storage/uploads/img/zz/test-kopii.txt', 'plik do kopii');

        $dump = Backup::dumpDatabase(tmp_dir() . '/baza-test.sql.gz');
        $files = Backup::archiveFiles(tmp_dir() . '/pliki-test.tar.gz');
        T::assert(is_file($dump) && filesize($dump) > 500 && is_file((string) $files));

        DB::query('DELETE FROM users');
        DB::pdo()->exec('DROP TABLE favorites');
        unlink(APP_ROOT . '/storage/uploads/img/zz/test-kopii.txt');

        $n = Backup::restoreDatabase($dump);
        Backup::restoreFiles((string) $files);
        T::assert($n > 20, "instrukcji: {$n}");
        $u = DB::fetch('SELECT email, first_name, totp_secret FROM users WHERE id = ?', [$uid]);
        T::same(["o'brien@example.pl", "Zażółć \"gęślą\"\njaźń; \\ test", "\x00\x01\xFF bin"], [$u['email'], $u['first_name'], $u['totp_secret']]);
        T::same('2001:db8::1', inet_ntop((string) DB::value("SELECT ip FROM audit_log WHERE action = 'test.ip'")));
        T::same(0, (int) DB::value('SELECT COUNT(*) FROM favorites'), 'usunięta tabela wróciła');
        T::same('plik do kopii', file_get_contents(APP_ROOT . '/storage/uploads/img/zz/test-kopii.txt'));
        T::same([], (new \App\Core\Migrator(APP_ROOT . '/migrations'))->pending());

        // Niekompletny zrzut jest odrzucany
        $broken = tmp_dir() . '/baza-urwana.sql.gz';
        file_put_contents($broken, gzencode("SET NAMES utf8mb4;\nDROP TABLE IF EXISTS `x_test`;\n"));
        T::throws(fn() => Backup::restoreDatabase($broken), \RuntimeException::class);

        // Rotacja tylko starych kopii z katalogu kopii
        $old = Backup::dir() . '/baza-20000101-000000.sql.gz';
        $new = Backup::dir() . '/baza-29990101-000000.sql.gz';
        file_put_contents($old, 'x');
        file_put_contents($new, 'x');
        touch($old, time() - 20 * 86400);
        T::same(['baza-20000101-000000.sql.gz'], Backup::rotate(14));
        T::assert(is_file($new));
        unlink($new);
        @unlink(APP_ROOT . '/storage/uploads/img/zz/test-kopii.txt');
        @rmdir(APP_ROOT . '/storage/uploads/img/zz');
    },

    'Kopia poza serwer: podpis S3 (wektor z dokumentacji AWS) i wysyłka do magazynu' => function (): void {
        $e = hash('sha256', '');
        $auth = S3Storage::authorization('GET', 'https://examplebucket.s3.amazonaws.com/test.txt',
            ['host' => 'examplebucket.s3.amazonaws.com', 'range' => 'bytes=0-9', 'x-amz-content-sha256' => $e, 'x-amz-date' => '20130524T000000Z'],
            $e, 'AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-east-1', '20130524T000000Z');
        T::assert(str_ends_with($auth, 'Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41'), $auth);

        $sent = [];
        S3Storage::$transport = static function ($m, $url, $h, $file) use (&$sent): int {
            $sent[] = [$m, $url, $h['x-amz-content-sha256'], $file];
            return 200;
        };
        $f = make_file('baza-x.sql.gz', 'abc');
        $key = (new S3Storage('https://s3.eu-central-003.backblazeb2.com', 'eu-central-003', 'kopie', 'K', 'S', 'portal'))->put($f);
        T::same('portal/baza-x.sql.gz', $key);
        T::same(['PUT', 'https://s3.eu-central-003.backblazeb2.com/kopie/portal/baza-x.sql.gz', hash('sha256', 'abc'), $f], $sent[0]);
        S3Storage::$transport = static fn() => 403;
        T::throws(fn() => (new S3Storage('https://s3.example', 'r', 'b', 'K', 'S'))->put($f), \RuntimeException::class);
        S3Storage::$transport = null;
    },

    'Raport problemów: tylko gdy są błędy / brak kopii; grupowanie błędów z logów' => function (): void {
        auth_setup();
        create_user('admin@example.pl', 'haslo-admina-123', 'admin');
        foreach (glob(Backup::dir() . '/baza-*.sql.gz') ?: [] as $f) {
            unlink($f);
        }
        $log = APP_ROOT . '/storage/logs/app-' . gmdate('Y-m-d') . '.log';
        $before = is_file($log) ? (string) file_get_contents($log) : null;
        $line = static fn($lvl, $msg) => json_encode(['time' => gmdate('Y-m-d\TH:i:s\Z'), 'level' => $lvl, 'message' => $msg, 'context' => ['x' => 1]]) . "\n";
        // Na czas testu w katalogu logów jest tylko nasz plik (inne dni i wcześniejsze wpisy odkładamy na bok).
        $aside = [];
        foreach (glob(APP_ROOT . '/storage/logs/app-*.log') ?: [] as $f) {
            if ($f !== $log && filemtime($f) > time() - 86400 * 2) {
                $aside[$f] = (string) file_get_contents($f);
                unlink($f);
            }
        }
        file_put_contents($log, $line('error', 'SMTP nie odpowiada') . $line('error', 'SMTP nie odpowiada') . $line('warning', 'drobiazg') . $line('critical', 'Baza padła'));

        $r = ErrorReport::collect(time() - 3600);
        T::same(['SMTP nie odpowiada', 'Baza padła'], array_column($r['errors'], 'message'));
        T::same(2, $r['errors'][0]['count']);
        T::assert($r['backup_stale'], 'brak kopii = problem');
        Mailer::$sent = [];
        T::same(1, ErrorReport::send(time() - 3600));
        run_jobs();
        T::same('error-report', Mailer::$sent[0]['template'] ?? null);

        file_put_contents($log, '');
        Backup::dumpDatabase();
        $r = ErrorReport::collect(time() - 3600);
        T::assert(!ErrorReport::hasProblems($r), 'świeża kopia, brak błędów = brak e-maila');
        T::same('ok', json_decode(Browser::get('/health')->body, true)['checks']['backup']);
        $before === null ? unlink($log) : file_put_contents($log, $before);
        foreach ($aside as $f => $content) {
            file_put_contents($f, $content);
        }
        foreach (glob(Backup::dir() . '/baza-*.sql.gz') ?: [] as $f) {
            unlink($f);
        }
    },

    'HTML z WordPressa → Markdown portalu' => function (): void {
        $md = \App\Import\HtmlToMarkdown::convert('<h1>Tytuł</h1><p>Tekst <b>gruby</b> i <i>kursywa</i> &amp; <a href="https://ex.pl/x">link</a>.</p>[vc_column]<script>x()</script><ol><li>Jeden</li><li>Dwa</li></ol><div style="color:red"><p>W divie</p></div>');
        T::same("## Tytuł\n\nTekst **gruby** i _kursywa_ & [link](https://ex.pl/x).\n\n1. Jeden\n2. Dwa\n\nW divie", $md);
        T::same("Akapit 1\nlinia\n\nAkapit 2", \App\Import\HtmlToMarkdown::convert("Akapit 1\nlinia\n\nAkapit 2"));
        T::same('', \App\Import\HtmlToMarkdown::convert('[gallery ids="1,2"] <p> </p>'));
        T::same([], WpImporter::unserialize('O:8:"stdClass":0:{}'), 'bez obiektów');
    },

    'Import WP: rozpoznanie, próba bez zmian, import kont, ofert, pól, plików; ponowny import pomija; przekierowania; zaproszenia' => function (): void {
        auth_setup();
        $admin = create_user('admin@example.pl', 'haslo-admina-123', 'admin');
        $uploads = wp_fixture();

        $d = (new WpImporter(DB::pdo(), 'wpt_', [], $uploads))->discover('product');
        T::assert(in_array('kwota', array_column($d['meta'], 'meta_key'), true));
        T::same(2, $d['user_roles']['customer'] ?? null);

        // Próba: raport jest, w bazie nic
        $dry = (new WpImporter(DB::pdo(), 'wpt_', wp_mapping(), $uploads, true))->run();
        T::same(2, $dry['listings']['imported']);
        T::same(0, (int) DB::value('SELECT COUNT(*) FROM listings'));
        T::same(1, (int) DB::value('SELECT COUNT(*) FROM users'), 'tylko admin portalu');

        $r = (new WpImporter(DB::pdo(), 'wpt_', wp_mapping(), $uploads))->run();
        T::same([2, 0], [$r['users']['imported'], $r['users']['existing']]);
        T::assert(count(array_filter($r['users']['skipped'], static fn($s) => str_contains($s, 'administrator'))) === 1);
        T::assert(count(array_filter($r['users']['skipped'], static fn($s) => str_contains($s, 'niepoprawny e-mail'))) === 1);
        $dev = DB::fetch('SELECT u.*, c.nip, c.name AS company FROM users u LEFT JOIN companies c ON c.id = u.company_id WHERE email = ?', ['deweloper@firma.pl']);
        T::same(['Piotr', '+48601222333', '5260250274', 'Budowlany Sp. z o.o.', 'developer', '2024-03-05 08:30:00'],
            [$dev['first_name'], $dev['phone_e164'], $dev['nip'], $dev['company'], $dev['account_type'], $dev['created_at']]);
        $inv = DB::fetch("SELECT phone_e164, account_type FROM users WHERE email = 'inwestor@poczta.pl'");
        T::same([null, 'investor'], [$inv['phone_e164'], $inv['account_type']], 'bez telefonu, typ domyślny');
        T::assert(!password_verify('', (string) $dev['password_hash']), 'losowe hasło');

        // Oferta 1: komplet
        $l = DB::fetch("SELECT * FROM listings WHERE title = 'Osiedle Zielone – Poznań'");
        T::assert($l !== null, 'encje HTML w tytule zdekodowane');
        T::same([(int) $dev['id'], 'published', '2024-05-10 12:00:00', 'wielkopolskie', 'Poznań'], [(int) $l['user_id'], $l['status'], $l['published_at'], $l['region'], $l['city']]);
        T::same('pozyskanie-kapitalu', DB::value('SELECT slug FROM categories WHERE id = ?', [$l['category_id']]));
        T::assert(str_starts_with((string) $l['full_desc_md'], "## Lokalizacja\n\nOsiedle przy **parku**."), (string) $l['full_desc_md']);
        T::same('Działka 123/4', $l['confidential_md']);
        T::assert(str_starts_with((string) $l['short_desc'], 'Lokalizacja Osiedle przy parku'), 'krótki opis z treści, gdy brak zajawki: ' . $l['short_desc']);
        $v = ValueStore::load((int) $l['id']);
        T::same('residential_multi', $v['investment_type'], 'opcja dopasowana po etykiecie');
        T::same('permit', $v['project_stage'], 'opcja z mapy values');
        T::same('medium', $v['investment_period']);
        T::same(['bonds', 'loan'], $v['investment_form'], 'taksonomia → multiselect');
        T::same(2500000.0, (float) $v['capital_needed']['amount']);
        T::same(18.5, (float) $v['expected_roi']);
        T::same(true, (bool) $v['esg']);
        T::same(2, (int) DB::value('SELECT COUNT(*) FROM media WHERE listing_id = ?', [$l['id']]), 'okładka + galeria (brakujący plik pominięty)');
        T::same(['Teaser', 2, 'live'], array_values(DB::fetch('SELECT label, access_level, state FROM attachments WHERE listing_id = ?', [$l['id']])));
        T::assert(in_array('2024/05/brak.jpg', array_map(static fn($m) => substr($m, -16), $r['media']['missing']), true));

        // Oferta 2: autor-admin → właściciel zastępczy, nieznane wartości w raporcie, kategoria domyślna
        $b = DB::fetch("SELECT * FROM listings WHERE title = 'Biurowiec Kraków'");
        T::same([$admin, 'draft', null, 'Biurowiec klasy A.'], [(int) $b['user_id'], $b['status'], $b['published_at'], $b['short_desc']]);
        T::same('sprzedaz-projektu', DB::value('SELECT slug FROM categories WHERE id = ?', [$b['category_id']]));
        T::same(1, $r['values']['unknown_options']['investment_type = Biurowiec XXL'] ?? null);
        T::assert(count(array_filter($r['values']['rejected'], static fn($s) => str_contains($s, 'capital_needed'))) === 1, 'kwota „dużo” odrzucona');
        T::same(1, $r['unmapped_meta']['stare_pole']['count'] ?? null);
        T::assert(!isset($r['unmapped_meta']['_edit_lock']), 'wewnętrzne pola WP pominięte');
        T::same(1, $r['unmapped_terms']['product_cat: inne'] ?? null);

        // Przekierowania
        $ht = WpImporter::htaccess($r['redirects']);
        T::assert(str_contains($ht, 'RewriteRule ^inwestycje/osiedle\-zielone\-poznan/?$ /oferta/' . $l['slug'] . ' [R=301,L,NE]'), $ht);
        T::assert(str_contains($ht, 'RewriteRule ^produkt/biurowiec\-krakow/?$ /oferta/' . $b['slug']));
        T::assert(str_contains($ht, 'RewriteCond %{QUERY_STRING} (^|&)p=500(&|$)'));
        T::assert(str_contains(ImportReport::markdown($r, false), '| `stare_pole` | 1 | coś |'));

        // Ponowny import: nic nowego
        $again = (new WpImporter(DB::pdo(), 'wpt_', wp_mapping(), $uploads))->run();
        T::same([0, 2, 0, 2], [$again['users']['imported'], $again['users']['existing'], $again['listings']['imported'], $again['listings']['existing']]);
        T::same(2, (int) DB::value('SELECT COUNT(*) FROM listings'));
        T::same(4, count(array_filter($again['redirects'], static fn($x) => $x['type'] === 'path')), 'przekierowania także przy ponownym imporcie');

        // Zaproszenia: raz na konto, link do ustawienia hasła działa
        Mailer::$sent = [];
        T::same(2, WpImporter::sendInvites());
        T::same(0, WpImporter::sendInvites(), 'nie dubluje');
        run_jobs();
        $mail = array_values(array_filter(Mailer::$sent, static fn($m) => $m['template'] === 'account-migrated' && $m['to'] === 'deweloper@firma.pl'))[0];
        $path = path_of($mail['data']['url']);
        Browser::fresh();
        Browser::post($path, ['password' => 'nowe-haslo-dewelopera', 'password_confirmation' => 'nowe-haslo-dewelopera']);
        T::same('/konto', Browser::post('/logowanie', ['email' => 'deweloper@firma.pl', 'password' => 'nowe-haslo-dewelopera'])->headers['Location'] ?? null);
        T::assert(str_contains(Browser::get('/konto')->body, 'Piotr'));
        T::same(200, Browser::get('/oferta/' . $l['slug'])->status);
    },
];
