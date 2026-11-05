<?php

declare(strict_types=1);

/**
 * Etap 5: panel administracyjny — dostęp ról, moderacja z porównaniem, dostępy, zapytania (CRM + CSV),
 * użytkownicy, edytor kryteriów, strony, ustawienia i słownictwo, tryb kampanii, dziennik.
 * Korzysta z helperów z AuthDbTest.php, ListingsDbTest.php i PublicDbTest.php.
 */

use App\Access\AccessService;
use App\Auth\Totp;
use App\Core\CampaignMode;
use App\Core\Crypto;
use App\Core\DB;
use App\Core\Mailer;
use App\Core\Settings;
use App\Listings\Fields;
use App\Listings\ListingService;

/** Zalogowany admin/moderator z włączonym 2FA (logowanie hasłem + kodem z aplikacji). */
function login_staff(string $role = 'admin', string $email = 'admin@example.pl'): int
{
    $id = create_user($email, 'haslo-zespolu-123', $role, '+48600' . random_int(100000, 999999));
    $secret = Totp::generateSecret();
    DB::update('users', ['totp_secret' => Crypto::encrypt($secret), 'totp_enabled_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]);
    Browser::fresh();
    Browser::post('/logowanie', ['email' => $email, 'password' => 'haslo-zespolu-123']);
    Browser::post('/logowanie/2fa', ['code' => Totp::code($secret)]);
    return $id;
}

return [
    'Panel: tylko zespół (404 dla innych), sekcje ustawień tylko admin (403 dla moderatora)' => function (): void {
        auth_setup();
        T::same(302, Browser::get('/admin')->status, 'gość → logowanie');
        login_author('zwykly@example.pl');
        T::same(404, Browser::get('/admin')->status, 'użytkownik nie wie, że panel istnieje');
        T::same(404, Browser::get('/admin/uzytkownicy')->status);

        login_staff('moderator', 'mod@example.pl');
        T::same(200, Browser::get('/admin')->status);
        foreach (['/admin/moderacja', '/admin/oferty', '/admin/zapytania', '/admin/dostepy'] as $p) {
            T::same(200, Browser::get($p)->status, $p);
        }
        foreach (['/admin/uzytkownicy', '/admin/kryteria', '/admin/strony', '/admin/ustawienia', '/admin/dziennik'] as $p) {
            T::same(403, Browser::get($p)->status, $p);
        }
        T::assert(!str_contains(Browser::get('/admin')->body, '/admin/uzytkownicy'), 'moderator nie widzi linków admina');

        login_staff('admin', 'admin@example.pl');
        foreach (['/admin', '/admin/uzytkownicy', '/admin/kryteria', '/admin/kryteria/nowe', '/admin/strony', '/admin/strony/nowa', '/admin/ustawienia', '/admin/dziennik'] as $p) {
            T::same(200, Browser::get($p)->status, $p);
        }
    },

    'Moderacja w przeglądarce: kolejka, porównanie było/jest, odrzucenie z uzasadnieniem, akceptacja' => function (): void {
        auth_setup();
        login_author();
        $id = new_listing('Hala produkcyjna Łódź');
        Browser::post("/moje-oferty/{$id}/edycja/2", complete_fields());
        Browser::post("/moje-oferty/{$id}/wyslij");

        login_staff('moderator', 'mod@example.pl');
        $q = Browser::get('/admin/moderacja');
        T::assert(str_contains($q->body, 'Hala produkcyjna Łódź') && str_contains($q->body, 'Nowa'));
        $page = Browser::get("/admin/moderacja/{$id}");
        T::same(200, $page->status);
        T::assert(str_contains($page->body, 'Akceptuj i opublikuj'));

        $r = Browser::post("/admin/moderacja/{$id}", ['action' => 'reject', 'note' => 'ok']);
        T::same('pending', DB::value('SELECT status FROM listings WHERE id = ?', [$id]), 'za krótkie uzasadnienie');
        Browser::post("/admin/moderacja/{$id}", ['action' => 'reject', 'note' => 'Dodaj zdjęcia z budowy.']);
        T::same('rejected', DB::value('SELECT status FROM listings WHERE id = ?', [$id]));
        T::same('Dodaj zdjęcia z budowy.', DB::value('SELECT moderation_note FROM listings WHERE id = ?', [$id]));

        DB::update('listings', ['status' => 'pending'], ['id' => $id]);
        Browser::post("/admin/moderacja/{$id}", ['action' => 'approve']);
        T::same('published', DB::value('SELECT status FROM listings WHERE id = ?', [$id]));

        // Edycja opublikowanej → rewizja → porównanie pokazuje zmianę
        ListingService::save(ListingService::find($id), ['title' => 'Hala produkcyjna Łódź — nowa cena']);
        T::same([], ListingService::submit(ListingService::find($id)));
        $diff = ListingService::diff(ListingService::find($id));
        $title = array_values(array_filter($diff, static fn($r) => $r['label'] === __('listing.title')))[0];
        T::same(['Hala produkcyjna Łódź', 'Hala produkcyjna Łódź — nowa cena', true], [$title['before'], $title['after'], $title['changed']]);
        T::assert(count(array_filter($diff, static fn($r) => $r['changed'])) === 1, 'tylko tytuł się zmienił');
        $page = Browser::get("/admin/moderacja/{$id}");
        T::assert(str_contains($page->body, 'diff__row--changed') && str_contains($page->body, 'Zmiany (1)'));
        Browser::post("/admin/moderacja/{$id}", ['action' => 'reject', 'note' => 'Cena w tytule jest niezgodna z regulaminem.']);
        T::same('Hala produkcyjna Łódź', DB::value('SELECT title FROM listings WHERE id = ?', [$id]), 'odrzucona rewizja nie zmienia opublikowanej');
    },

    'Oferty w panelu: wstrzymanie ukrywa ofertę (Gate), wznowienie, wyróżnienie, archiwum' => function (): void {
        auth_setup();
        ['ids' => $ids] = seed_market();
        login_staff();
        $a = $ids['a'];
        $slug = DB::value('SELECT slug FROM listings WHERE id = ?', [$a]);
        T::assert(str_contains(Browser::get('/admin/oferty', ['q' => 'Słoneczne'])->body, 'Osiedle Słoneczne'));

        Browser::post("/admin/oferty/{$a}", ['action' => 'pause']);
        T::same('paused', DB::value('SELECT status FROM listings WHERE id = ?', [$a]));
        Browser::fresh();
        T::same(404, Browser::get('/oferta/' . $slug)->status, 'wstrzymana niewidoczna publicznie');
        login_staff('admin', 'admin2@example.pl');
        Browser::post("/admin/oferty/{$a}", ['action' => 'resume']);
        T::same('published', DB::value('SELECT status FROM listings WHERE id = ?', [$a]));
        Browser::post("/admin/oferty/{$a}", ['action' => 'feature']);
        T::same(1, (int) DB::value('SELECT is_featured FROM listings WHERE id = ?', [$a]));
        Browser::post("/admin/oferty/{$a}", ['action' => 'restore']);
        T::same('published', DB::value('SELECT status FROM listings WHERE id = ?', [$a]), 'przywrócenie tylko z archiwum');
        Browser::post("/admin/oferty/{$a}", ['action' => 'archive']);
        T::same('archived', DB::value('SELECT status FROM listings WHERE id = ?', [$a]));
        T::same(1, (int) DB::value("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'listing.%' AND entity_id = ? AND action = 'listing.feature'", [$a]));
    },

    'Dostępy L3: prośba → przyznanie na czas → cofnięcie; e-mail z decyzją' => function (): void {
        auth_setup();
        ['ids' => $ids] = seed_market();
        $uid = login_author('inwestor@example.pl');
        Browser::post("/oferta/{$ids['a']}/dostep", ['message' => 'Proszę o dane działki.']);
        $grant = (int) DB::value('SELECT id FROM access_grants WHERE user_id = ?', [$uid]);
        T::assert($grant > 0, 'prośba zapisana');

        login_staff('moderator', 'mod@example.pl');
        T::assert(str_contains(Browser::get('/admin/dostepy')->body, 'Proszę o dane działki.'));
        Mailer::$sent = [];
        Browser::post("/admin/dostepy/{$grant}", ['action' => 'grant', 'days' => '30']);
        $row = DB::fetch('SELECT status, expires_at FROM access_grants WHERE id = ?', [$grant]);
        T::same('granted', $row['status']);
        T::assert(abs(strtotime($row['expires_at'] . ' UTC') - (time() + 30 * 86400)) < 120, 'ważny 30 dni');
        run_jobs();
        T::assert(in_array('access-granted', array_column(Mailer::$sent, 'template'), true));
        Browser::post("/admin/dostepy/{$grant}", ['action' => 'revoke']);
        T::same('revoked', DB::value('SELECT status FROM access_grants WHERE id = ?', [$grant]));
    },

    'Zapytania jako CRM: status, opiekun, notatka; eksport CSV bez wstrzyknięcia formuł' => function (): void {
        auth_setup();
        ['ids' => $ids] = seed_market();
        $consent = DB::insert('consents', ['email' => 'k@example.pl', 'type' => 'inquiry', 'granted' => 1, 'ip' => inet_pton('203.0.113.1')]);
        $inq = DB::insert('inquiries', [
            'listing_id' => $ids['a'], 'name' => '=HYPERLINK("http://zlo")', 'email' => 'k@example.pl', 'message' => 'Proszę o kontakt.',
            'consent_id' => $consent, 'ip' => inet_pton('203.0.113.1'),
        ]);
        $mod = login_staff('moderator', 'mod@example.pl');
        T::assert(str_contains(Browser::get('/admin/zapytania', ['status' => 'new'])->body, 'k@example.pl'));
        T::same(200, Browser::get("/admin/zapytania/{$inq}")->status);
        Browser::post("/admin/zapytania/{$inq}", ['status' => 'in_progress', 'assigned_to' => (string) $mod, 'admin_note' => 'Oddzwonić w piątek']);
        $row = DB::fetch('SELECT status, assigned_to, admin_note FROM inquiries WHERE id = ?', [$inq]);
        T::same(['in_progress', $mod, 'Oddzwonić w piątek'], [$row['status'], (int) $row['assigned_to'], $row['admin_note']]);

        $csv = Browser::get('/admin/zapytania.csv');
        T::same(200, $csv->status);
        T::assert(str_starts_with($csv->body, "\u{FEFF}"), 'BOM dla Excela');
        T::assert(str_contains($csv->body, "'=HYPERLINK"), 'formuła zneutralizowana');
        T::assert(str_contains($csv->body, 'Oddzwonić w piątek'));
        T::same(1, (int) DB::value("SELECT COUNT(*) FROM audit_log WHERE action = 'inquiry.export'"));
    },

    'Użytkownicy: wyszukiwanie, blokada wylogowuje i blokuje logowanie, rola, reset 2FA, nie na sobie, CSV' => function (): void {
        auth_setup();
        $uid = create_user('klient@example.pl', 'haslo-klienta-123');
        $admin = login_staff();
        T::assert(str_contains(Browser::get('/admin/uzytkownicy', ['q' => 'klient@'])->body, 'klient@example.pl'));
        T::same(200, Browser::get("/admin/uzytkownicy/{$uid}")->status);
        Browser::post("/admin/uzytkownicy/{$uid}", ['action' => 'block']);
        T::same('blocked', DB::value('SELECT status FROM users WHERE id = ?', [$uid]));
        Browser::post("/admin/uzytkownicy/{$uid}", ['action' => 'role', 'role' => 'moderator']);
        T::same('moderator', DB::value('SELECT role FROM users WHERE id = ?', [$uid]));
        Browser::post("/admin/uzytkownicy/{$uid}", ['action' => 'role', 'role' => 'superuser']);
        T::same('moderator', DB::value('SELECT role FROM users WHERE id = ?', [$uid]), 'nieznana rola ignorowana');
        Browser::post("/admin/uzytkownicy/{$admin}", ['action' => 'role', 'role' => 'member']);
        T::same('admin', DB::value('SELECT role FROM users WHERE id = ?', [$admin]), 'nie na sobie');
        T::same(1, (int) DB::value("SELECT COUNT(*) FROM audit_log WHERE action = 'role.change' AND entity_id = ?", [$uid]));

        $csv = Browser::get('/admin/uzytkownicy.csv');
        T::assert(str_contains($csv->body, 'klient@example.pl') && str_contains($csv->body, 'Zgoda marketingowa'));

        Browser::fresh();
        Browser::post('/logowanie', ['email' => 'klient@example.pl', 'password' => 'haslo-klienta-123']);
        T::same(null, \App\Core\Session::get('user_id'), 'zablokowany nie zaloguje się');
    },

    'Edytor kryteriów: nowe pole, walidacja, blokada typu, ukrycie, opcje, kategorie, eksport presetu' => function (): void {
        auth_setup('maszyny-i-sprzet');
        login_staff();
        $r = Browser::post('/admin/kryteria/nowe', ['label' => 'Rok produkcji', 'key' => 'Rok Produkcji', 'type' => 'int', 'access_level' => '1']);
        T::same(422, $r->status, 'zły klucz');
        $r = Browser::post('/admin/kryteria/nowe', ['label' => 'Stan', 'key' => 'stan_x', 'type' => 'text', 'access_level' => '1', 'filterable' => '1', 'filter_ui' => 'range']);
        T::same(422, $r->status, 'zakres tylko dla liczb');
        $r = Browser::post('/admin/kryteria/nowe', ['label' => 'Klasa', 'key' => 'klasa', 'type' => 'select', 'access_level' => '3', 'filterable' => '1', 'filter_ui' => 'select']);
        T::same(422, $r->status, 'pole poufne nie jest filtrem');
        $r = Browser::post('/admin/kryteria/nowe', ['label' => 'Klasa energetyczna', 'key' => 'klasa', 'type' => 'select', 'access_level' => '1', 'filterable' => '1', 'filter_ui' => 'checkboxes', 'show_in_card' => '1']);
        T::same(302, $r->status);
        $fid = (int) DB::value("SELECT id FROM field_definitions WHERE `key` = 'klasa'");
        T::same('/admin/kryteria/' . $fid, $r->headers['Location']);

        Browser::post("/admin/kryteria/{$fid}/opcje", ['label' => 'Klasa A+']);
        Browser::post("/admin/kryteria/{$fid}/opcje", ['label' => 'Klasa B']);
        T::same(['klasa_a', 'klasa_b'], array_column(DB::fetchAll('SELECT `key` FROM field_options WHERE field_id = ? ORDER BY sort_order', [$fid]), 'key'));
        $optB = (int) DB::value("SELECT id FROM field_options WHERE `key` = 'klasa_b'");
        Browser::post("/admin/kryteria/opcja/{$optB}", ['action' => 'w-gore']);
        T::same(['klasa_b', 'klasa_a'], array_column(DB::fetchAll('SELECT `key` FROM field_options WHERE field_id = ? ORDER BY sort_order', [$fid]), 'key'));
        Fields::flush();
        T::assert(isset(Fields::all()['klasa']['options']['klasa_b']), 'pole od razu w formularzu i filtrach');

        // Pole z wartościami w ofertach: typu nie zmienimy
        $existing = DB::fetch("SELECT f.id, f.type FROM field_definitions f JOIN listing_values v ON v.field_id = f.id LIMIT 1");
        if ($existing === null) {
            $uid = create_user('autor2@example.pl', 'haslo-autora-123');
            $cat = (int) DB::value('SELECT id FROM categories WHERE parent_id IS NULL ORDER BY id LIMIT 1');
            $lid = ListingService::create($uid, $cat, 'Koparka', 'Opis');
            DB::insert('listing_values', ['listing_id' => $lid, 'field_id' => $fid, 'v_option_id' => $optB]);
            $existing = ['id' => $fid, 'type' => 'select'];
        }
        $r = Browser::post('/admin/kryteria/' . $existing['id'], ['label' => 'Zmieniona', 'type' => 'text', 'access_level' => '2']);
        T::same(422, $r->status, 'typ zablokowany');
        T::assert(str_contains($r->body, 'Typu nie można zmienić'));

        Browser::post("/admin/kryteria/pole/{$fid}", ['action' => 'ukryj']);
        Fields::flush();
        T::assert(!isset(Fields::all()['klasa']), 'ukryte pole znika z formularzy');
        T::same(1, (int) DB::value('SELECT COUNT(*) FROM field_definitions WHERE id = ?', [$fid]), 'ale nie jest usuwane');

        Browser::post('/admin/kategorie', ['name' => 'Dźwigi wieżowe']);
        T::same('dzwigi-wiezowe', DB::value("SELECT slug FROM categories WHERE name = 'Dźwigi wieżowe'"));

        $json = Browser::get('/admin/kryteria/eksport.json');
        $preset = json_decode($json->body, true);
        T::same([], \App\Presets\Importer::validate($preset), 'eksport to poprawny preset');
        T::assert(in_array('Dźwigi wieżowe', array_column($preset['categories'], 'name'), true));
    },

    'Strony treści: zapis podbija wersję, zarezerwowane adresy, usuwanie' => function (): void {
        auth_setup();
        login_staff();
        $r = Browser::post('/admin/strony/nowa', ['title' => 'Oferty', 'slug' => 'oferty', 'body_md' => 'x']);
        T::same(422, $r->status, 'adres zajęty przez portal');
        $r = Browser::post('/admin/strony/nowa', ['title' => 'Cennik usług', 'body_md' => "## Pakiety\n\nOd 99 zł.", 'in_menu' => '1']);
        $pid = (int) DB::value("SELECT id FROM pages WHERE slug = 'cennik-uslug'");
        T::same('/admin/strony/' . $pid, $r->headers['Location']);
        T::assert(str_contains(Browser::get('/cennik-uslug')->body, 'Od 99 zł.'));
        Browser::post('/admin/strony/' . $pid, ['title' => 'Cennik', 'slug' => 'cennik-uslug', 'body_md' => 'Nowe ceny']);
        T::same(2, (int) DB::value('SELECT version FROM pages WHERE id = ?', [$pid]));
        Browser::post('/admin/strony/' . $pid, ['action' => 'delete']);
        T::same(404, Browser::get('/cennik-uslug')->status);
    },

    'Ustawienia: nazwa, kolor (walidacja), słownictwo nadpisuje teksty w całym portalu' => function (): void {
        auth_setup();
        login_staff();
        $r = Browser::post('/admin/ustawienia', ['portal' => ['name' => 'Giełda', 'accent' => 'czerwony', 'mode' => 'light']]);
        T::same(422, $r->status);
        T::assert(str_contains($r->body, 'value="Giełda"'), 'formularz pamięta wpisane dane');
        Browser::post('/admin/ustawienia', [
            'portal' => ['name' => 'Giełda Maszyn', 'tagline' => 'Kupuj i sprzedawaj', 'accent' => '#aa3300', 'mode' => 'dark'],
            'terms' => ['term.listing' => 'Ogłoszenie', 'term.listings' => 'Ogłoszenia', 'nie.istnieje' => 'X'],
        ]);
        Settings::flush();
        T::same('Giełda Maszyn', Settings::get('portal')['name']);
        T::same('#aa3300', Settings::get('portal')['theme']['accent']);
        T::same(['term.listing' => 'Ogłoszenie', 'term.listings' => 'Ogłoszenia'], Settings::get('terms'));
        T::same('Ogłoszenia', __('term.listings'));
        T::assert(str_contains(Browser::get('/theme.css')->body, '#aa3300'));
        Settings::set('terms', []);
        Settings::flush();
    },

    'Tryb kampanii: włączenie z panelu, wygasa sam, widoczny w /health i na pulpicie' => function (): void {
        auth_setup();
        login_staff();
        Browser::post('/admin/tryb-kampanii', ['action' => 'enable', 'hours' => '6']);
        $until = CampaignMode::until();
        T::assert($until !== null && abs($until - (time() + 6 * 3600)) < 5);
        T::assert(str_contains(Browser::get('/admin')->body, 'Tryb kampanii aktywny'));
        T::same('on', json_decode(Browser::get('/health')->body, true)['checks']['campaign'] ?? null);
        Browser::post('/admin/tryb-kampanii', ['action' => 'disable']);
        T::same(null, CampaignMode::until());
        file_put_contents(CampaignMode::file(), (string) (time() - 1));
        T::same(null, CampaignMode::until(), 'po czasie wygasa');
        T::assert(!is_file(CampaignMode::file()));
    },

    'Dziennik: filtr po zdarzeniu i e-mailu' => function (): void {
        auth_setup();
        login_staff();
        $r = Browser::get('/admin/dziennik', ['action' => 'login.']);
        T::same(200, $r->status);
        T::assert(str_contains($r->body, 'login.ok'));
        T::assert(!str_contains(Browser::get('/admin/dziennik', ['email' => 'nikt@'])->body, 'login.ok'));
    },
];
