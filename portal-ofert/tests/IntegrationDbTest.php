<?php

declare(strict_types=1);

/**
 * Testy na prawdziwej bazie (PORTAL_TEST_DB=1). Każdy zestaw zaczyna od czystej bazy.
 */

use App\Core\DB;
use App\Core\Migrator;
use App\Core\Queue;
use App\Core\RateLimiter;
use App\Core\Settings;
use App\Presets\Importer;
use App\Presets\PresetException;

function test_reset_db(): void
{
    DB::pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (DB::fetchAll('SHOW TABLES') as $row) {
        DB::pdo()->exec('DROP TABLE `' . array_values($row)[0] . '`');
    }
    DB::pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
    (new Migrator(APP_ROOT . '/migrations'))->migrate();
    Settings::flush();
}

function preset_file(string $name): array
{
    return Importer::readFile(APP_ROOT . '/presets/' . $name . '.json');
}

return [
    'DB: migracja na czystej bazie, ponowne uruchomienie bez zmian' => function (): void {
        test_reset_db();
        $m = new Migrator(APP_ROOT . '/migrations');
        T::same([], $m->pending());
        T::same([], $m->migrate(), 'drugi raz nic nie robi');
        T::same(29, (int) DB::value('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'));
        T::same('+00:00', DB::value('SELECT @@session.time_zone'));
    },

    'Preset: import dwa razy — bez duplikatów, drugi raz bez zmian' => function (): void {
        test_reset_db();
        $preset = preset_file('inwestycje-deweloperskie');

        $first = (new Importer())->import($preset);
        T::same(31, $first['fields.created'] ?? 0);
        T::same(5, $first['categories.created'] ?? 0);
        T::same(4, $first['pages.created'] ?? 0);

        $counts = fn() => [
            DB::value('SELECT COUNT(*) FROM field_definitions'),
            DB::value('SELECT COUNT(*) FROM field_options'),
            DB::value('SELECT COUNT(*) FROM categories'),
            DB::value('SELECT COUNT(*) FROM pages'),
            DB::value('SELECT COUNT(*) FROM settings'),
        ];
        $before = $counts();
        $second = (new Importer())->import($preset);
        T::same($before, $counts(), 'liczby wierszy identyczne');
        $changed = array_filter($second, fn($k) => !str_ends_with($k, '.unchanged') && !str_ends_with($k, '.kept'), ARRAY_FILTER_USE_KEY);
        T::same([], $changed, 'drugi import nie zgłasza zmian');

        // Dane z dokumentu poprawek: 10 form zabezpieczenia, województwa dla pola region
        T::same(10, (int) DB::value("SELECT COUNT(*) FROM field_options o JOIN field_definitions f ON f.id = o.field_id WHERE f.`key` = 'capital_security'"));
        T::same(16, (int) DB::value("SELECT COUNT(*) FROM field_options o JOIN field_definitions f ON f.id = o.field_id WHERE f.`key` = 'region'"));
        T::same('Inwestycje Finanse Dewelopera', Settings::get('portal.name'));
        T::same(287, Settings::get('limits.short_desc_max'));
        T::same(['allow_past_years' => 5], json_decode((string) DB::value("SELECT validation FROM field_definitions WHERE `key` = 'start_date'"), true));
    },

    'Preset: zmiany etykiet, --sync ukrywa, nic nie znika, ustawienia chronione' => function (): void {
        test_reset_db();
        $preset = preset_file('inwestycje-deweloperskie');
        (new Importer())->import($preset);
        Settings::set('portal', ['name' => 'Zmienione w panelu']);
        $optionId = (int) DB::value("SELECT o.id FROM field_options o JOIN field_definitions f ON f.id = o.field_id WHERE f.`key` = 'investment_form' AND o.`key` = 'bonds'");

        // nowa etykieta, usunięta opcja, usunięte pole
        $preset['fields'][0]['label'] = 'Rodzaj inwestycji';
        foreach ($preset['fields'] as &$f) {
            if ($f['key'] === 'investment_form') {
                $f['options'] = array_values(array_filter($f['options'], fn($o) => $o[0] !== 'bonds'));
            }
        }
        unset($f);
        $preset['fields'] = array_values(array_filter($preset['fields'], fn($f) => $f['key'] !== 'esg'));

        $r = (new Importer())->import($preset);
        T::same(2, $r['fields.updated'] ?? 0, 'nowa etykieta + przesunięta kolejność pola za usuniętym');
        T::same('Rodzaj inwestycji', DB::value("SELECT label FROM field_definitions WHERE `key` = 'investment_type'"));
        T::same(1, (int) DB::value("SELECT is_active FROM field_definitions WHERE `key` = 'esg'"), 'bez --sync pole zostaje aktywne');

        $r = (new Importer(sync: true))->import($preset);
        T::same(1, $r['fields.hidden'] ?? 0);
        T::same(1, $r['options.hidden'] ?? 0);
        T::same(0, (int) DB::value("SELECT is_active FROM field_definitions WHERE `key` = 'esg'"));
        T::same($optionId, (int) DB::value('SELECT id FROM field_options WHERE id = ? AND is_active = 0', [$optionId]), 'opcja ukryta, to samo ID');
        T::same('Zmienione w panelu', Settings::get('portal.name'), 'ustawienia z panelu nie nadpisane');

        (new Importer(overwrite: true))->import($preset);
        Settings::flush();
        T::same('Inwestycje Finanse Dewelopera', Settings::get('portal.name'), '--overwrite przywraca preset');
    },

    'Preset: zmiana typu pola z wartościami jest blokowana; dry-run nie zapisuje' => function (): void {
        test_reset_db();
        $preset = preset_file('inwestycje-deweloperskie');
        (new Importer())->import($preset);

        $userId = DB::insert('users', ['email' => 'a@b.pl', 'password_hash' => 'x', 'first_name' => 'A', 'last_name' => 'B', 'phone_e164' => '+48600100200']);
        $catId = (int) DB::value('SELECT id FROM categories LIMIT 1');
        $listingId = DB::insert('listings', ['user_id' => $userId, 'category_id' => $catId, 'slug' => 't-1', 'title' => 'T', 'short_desc' => 'x']);
        $fieldId = (int) DB::value("SELECT id FROM field_definitions WHERE `key` = 'units_count'");
        DB::insert('listing_values', ['listing_id' => $listingId, 'field_id' => $fieldId, 'v_int' => 36]);

        foreach ($preset['fields'] as &$f) {
            if ($f['key'] === 'units_count') {
                $f['type'] = 'text';
            }
        }
        unset($f);
        T::throws(fn() => (new Importer())->import($preset), PresetException::class, 'zmiana typu');
        T::same('int', DB::value('SELECT type FROM field_definitions WHERE id = ?', [$fieldId]), 'transakcja wycofana');

        $other = preset_file('sprzedaz-firm');
        $r = (new Importer())->import($other, dryRun: true);
        T::same(9, $r['fields.created'] ?? 0, 'dry-run raportuje zmiany (pole region wspólne dla obu presetów)');
        T::same(0, (int) DB::value("SELECT COUNT(*) FROM field_definitions WHERE `key` = 'ebitda'"), 'dry-run nic nie zapisał');
    },

    'Drugi preset na tym samym silniku' => function (): void {
        test_reset_db();
        $r = (new Importer())->import(preset_file('sprzedaz-firm'));
        T::same(10, $r['fields.created'] ?? 0);
        T::same(3, $r['categories.created'] ?? 0);
        T::same('Firmy na Sprzedaż', Settings::get('portal.name'));
    },

    'RateLimiter: limit w oknie, reset po oknie' => function (): void {
        test_reset_db();
        $b = 'login:ip:203.0.113.5';
        T::assert(RateLimiter::attempt($b, 3, 60));
        T::assert(RateLimiter::attempt($b, 3, 60));
        T::assert(RateLimiter::attempt($b, 3, 60));
        T::assert(!RateLimiter::attempt($b, 3, 60), '4. próba ponad limit');
        T::same(4, RateLimiter::count($b, 60));
        T::assert(RateLimiter::retryAfter($b, 60) > 0);
        DB::query('UPDATE rate_limits SET window_start = UTC_TIMESTAMP() - INTERVAL 61 SECOND WHERE bucket = ?', [$b]);
        T::assert(RateLimiter::attempt($b, 3, 60), 'nowe okno');
        T::same(1, RateLimiter::count($b, 60));
        $long = 'mail:' . str_repeat('x', 200);
        T::assert(RateLimiter::attempt($long, 1, 60), 'długi klucz skracany');
    },

    'Kolejka: rezerwacja, ponowienia z opóźnieniem, wyczerpanie prób' => function (): void {
        test_reset_db();
        $id = Queue::push('system.ping', ['a' => 1], 0, 2);
        $job = Queue::reserve();
        T::same($id, $job['id']);
        T::same(['a' => 1], $job['payload']);
        T::same(null, Queue::reserve(), 'zarezerwowane nie jest wydawane drugi raz');

        Queue::fail($id, 'timeout', $job['attempts']);
        T::same(null, Queue::reserve(), 'czeka na backoff');
        DB::query('UPDATE jobs SET available_at = UTC_TIMESTAMP() WHERE id = ?', [$id]);
        $job = Queue::reserve();
        T::same(2, $job['attempts']);
        Queue::fail($id, 'timeout', $job['attempts']);
        DB::query('UPDATE jobs SET available_at = UTC_TIMESTAMP() WHERE id = ?', [$id]);
        T::same(null, Queue::reserve(), 'po max_attempts nie wraca');
        T::same(1, Queue::failedCount());
        T::same(0, Queue::pendingCount());

        $id2 = Queue::push('system.ping');
        DB::query('UPDATE jobs SET reserved_at = UTC_TIMESTAMP() - INTERVAL 700 SECOND, attempts = 1 WHERE id = ?', [$id2]);
        T::same($id2, Queue::reserve()['id'] ?? null, 'porzucone zadanie przejęte po STALE_AFTER');
        Queue::complete($id2);
        T::same(0, Queue::pendingCount());
    },

    'HTTP: strona główna, strona treści, 404, health' => function (): void {
        test_reset_db();
        (new Importer())->import(preset_file('inwestycje-deweloperskie'));
        $router = new App\Core\Router();
        require APP_ROOT . '/app/routes.php';

        $home = $router->dispatch(new App\Core\Request('GET', '/'));
        T::same(200, $home->status);
        T::assert(str_contains($home->body, 'Inwestycje Finanse Dewelopera'));
        T::assert(str_contains($home->body, 'Projekt szuka kapitału'), 'kategorie z presetu');
        T::assert(str_contains($home->body, 'href="/zasady-wspolpracy"'), 'menu ze stron treści');

        $page = $router->dispatch(new App\Core\Request('GET', '/zasady-wspolpracy'));
        T::same(200, $page->status);
        T::assert(str_contains($page->body, '<strong>Poziom 3</strong>'));

        T::same(404, $router->dispatch(new App\Core\Request('GET', '/nie-ma-takiej'))->status);
        T::same(405, $router->dispatch(new App\Core\Request('POST', '/'))->status, 'POST na trasę GET');
        $router->group('', [App\Http\Middleware\StartSession::class, App\Http\Middleware\VerifyCsrf::class], function ($r) {
            $r->post('/test-formularz', fn() => App\Core\Response::text('zapisano'));
        });
        T::same(419, $router->dispatch(new App\Core\Request('POST', '/test-formularz', [], ['_csrf' => 'zly']))->status, 'zły token CSRF');
        T::same(419, $router->dispatch(new App\Core\Request('POST', '/test-formularz'))->status, 'brak tokenu CSRF');
        T::same('zapisano', $router->dispatch(new App\Core\Request('POST', '/test-formularz', [], ['_csrf' => App\Core\Csrf::token()]))->body);

        $health = $router->dispatch(new App\Core\Request('GET', '/health'));
        $body = json_decode($health->body, true);
        T::same(200, $health->status, $health->body);
        T::same('ok', $body['status']);
        T::assert(!isset($body['queue']), 'bez tokenu brak szczegółów');
        $health = $router->dispatch(new App\Core\Request('GET', '/health', ['token' => 'test-token']));
        T::assert(isset(json_decode($health->body, true)['queue']), 'z tokenem są szczegóły');

        DB::query('UPDATE jobs SET done_at = UTC_TIMESTAMP()');
        Queue::push('system.ping');
        DB::query('UPDATE jobs SET available_at = UTC_TIMESTAMP() - INTERVAL 20 MINUTE WHERE done_at IS NULL');
        $health = $router->dispatch(new App\Core\Request('GET', '/health'));
        T::same(503, $health->status, 'stojąca kolejka = cron workera nie działa');

        $css = $router->dispatch(new App\Core\Request('GET', '/theme.css'));
        T::assert(str_contains($css->body, '--accent:#C8102E'));
    },

    'Uniwersalność: słownictwo z presetu w interfejsie, typy kont, inny preset = inny portal' => function (): void {
        test_reset_db();
        $router = new App\Core\Router();
        require APP_ROOT . '/app/routes.php';

        $neutral = $router->dispatch(new App\Core\Request('GET', '/'))->body;
        T::assert(str_contains($neutral, '<h2 id="kategorie">Kategorie</h2>'), 'bez presetu: neutralnie');

        (new Importer())->import(preset_file('inwestycje-deweloperskie'));
        $inv = $router->dispatch(new App\Core\Request('GET', '/'))->body;
        T::assert(str_contains($inv, 'Rodzaje ofert inwestycyjnych'), 'słownictwo inwestycyjne z presetu');
        T::same(['developer', 'investor', 'advisor'], array_column(Settings::get('account_types'), 'key'));

        test_reset_db();
        (new Importer())->import(preset_file('maszyny-i-sprzet'));
        $m = $router->dispatch(new App\Core\Request('GET', '/'))->body;
        T::assert(str_contains($m, 'Kategorie maszyn') && str_contains($m, 'Giełda Maszyn'), 'portal maszyn');
        T::assert(!str_contains($m, 'Koparki'), 'podkategorie nie na stronie głównej');
        T::same('budowlane', DB::value("SELECT p.slug FROM categories c JOIN categories p ON p.id = c.parent_id WHERE c.slug = 'koparki'"));
        T::same(['koparki', 'ladowarki'], array_column(DB::fetchAll(
            "SELECT c.slug FROM field_categories fc JOIN categories c ON c.id = fc.category_id
             JOIN field_definitions f ON f.id = fc.field_id WHERE f.`key` = 'bucket_capacity' ORDER BY c.slug"), 'slug'), 'pole tylko dla wybranych kategorii');
        $css = $router->dispatch(new App\Core\Request('GET', '/theme.css'))->body;
        T::assert(str_contains($css, '--accent:#E07A00'));
    },

    'Wszystkie presety instalują się na czystej bazie' => function (): void {
        foreach (glob(APP_ROOT . '/presets/*.json') as $file) {
            test_reset_db();
            $r = (new Importer())->import(Importer::readFile($file));
            T::assert(($r['fields.created'] ?? 0) > 0, basename($file));
        }
    },
];
