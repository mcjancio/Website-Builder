<?php

declare(strict_types=1);

/**
 * Etap 5: „Moje konto” — profil, hasło i urządzenia, usunięcie konta (RODO), obserwowane,
 * zapisane wyszukiwania z alertami e-mail, moje dostępy, statystyki i statusy własnych ofert.
 * Korzysta z helperów z AuthDbTest.php, ListingsDbTest.php i PublicDbTest.php.
 */

use App\Core\DB;
use App\Core\Mailer;
use App\Core\Session;
use App\Listings\SavedSearches;

return [
    'Moje konto: wszystkie strony działają i mają nawigację' => function (): void {
        auth_setup();
        login_author();
        foreach (['/konto', '/konto/profil', '/konto/bezpieczenstwo', '/konto/obserwowane', '/konto/wyszukiwania', '/konto/dostepy', '/moje-oferty', '/konto/usun'] as $p) {
            $r = Browser::get($p);
            T::same(200, $r->status, $p);
            T::assert(str_contains($r->body, 'class="subnav"'), "nawigacja: {$p}");
        }
        T::assert(!str_contains(Browser::get('/konto')->body, '/admin'), 'bez linku do panelu dla użytkownika');
    },

    'Profil: zmiana imienia i typu konta, walidacja typu z presetu' => function (): void {
        auth_setup();
        $uid = login_author();
        T::same(422, Browser::post('/konto/profil', ['first_name' => 'Ola', 'last_name' => 'Kowal', 'account_type' => 'hacker'])->status);
        $r = Browser::post('/konto/profil', ['first_name' => 'Ola', 'last_name' => 'Kowal', 'account_type' => 'developer']);
        T::same('/konto/profil', $r->headers['Location']);
        $u = DB::fetch('SELECT first_name, last_name, account_type FROM users WHERE id = ?', [$uid]);
        T::same(['Ola', 'Kowal', 'developer'], [$u['first_name'], $u['last_name'], $u['account_type']]);
    },

    'Hasło: wymaga obecnego, zmienia, wylogowuje zapamiętane urządzenia' => function (): void {
        auth_setup();
        $uid = create_user('jan@example.pl', 'stare-haslo-123');
        Browser::post('/logowanie', ['email' => 'jan@example.pl', 'password' => 'stare-haslo-123', 'remember' => '1']);
        T::same(1, (int) DB::value("SELECT COUNT(*) FROM user_tokens WHERE user_id = ? AND type = 'remember'", [$uid]));
        T::assert(str_contains(Browser::get('/konto/bezpieczenstwo')->body, 'od '), 'lista urządzeń');

        $r = Browser::post('/konto/haslo', ['current_password' => 'zle', 'password' => 'nowe-haslo-12345', 'password_confirmation' => 'nowe-haslo-12345']);
        T::same(422, $r->status);
        T::same(422, Browser::post('/konto/haslo', ['current_password' => 'stare-haslo-123', 'password' => 'krotkie', 'password_confirmation' => 'krotkie'])->status);
        $r = Browser::post('/konto/haslo', ['current_password' => 'stare-haslo-123', 'password' => 'nowe-haslo-12345', 'password_confirmation' => 'nowe-haslo-12345']);
        T::same('/konto/bezpieczenstwo', $r->headers['Location']);
        T::same(0, (int) DB::value("SELECT COUNT(*) FROM user_tokens WHERE user_id = ? AND type = 'remember'", [$uid]), 'inne urządzenia wylogowane');
        T::same(200, Browser::get('/konto')->status, 'bieżąca sesja zostaje');

        Browser::fresh();
        Browser::post('/logowanie', ['email' => 'jan@example.pl', 'password' => 'nowe-haslo-12345']);
        T::same($uid, (int) Session::get('user_id'));
    },

    'Usunięcie konta: hasło + potwierdzenie, anonimizacja, szkice usunięte, opublikowane w archiwum' => function (): void {
        auth_setup();
        $uid = login_author('do-usuniecia@example.pl');
        $draft = seed_listing($uid, 'Mój szkic', ['investment_type' => 'office'], 'pozyskanie-kapitalu', 'draft');
        $live = seed_listing($uid, 'Moja opublikowana', ['investment_type' => 'office']);

        T::same(422, Browser::post('/konto/usun', ['password' => 'haslo-autora-123'])->status, 'bez potwierdzenia');
        T::same(422, Browser::post('/konto/usun', ['password' => 'zle', 'confirm' => '1'])->status, 'złe hasło');
        $r = Browser::post('/konto/usun', ['password' => 'haslo-autora-123', 'confirm' => '1']);
        T::same('/', $r->headers['Location']);
        T::same(null, Session::get('user_id'), 'wylogowany');

        $u = DB::fetch('SELECT email, first_name, phone_e164, status FROM users WHERE id = ?', [$uid]);
        T::same('deleted', $u['status']);
        T::assert(!str_contains($u['email'], 'do-usuniecia') && str_ends_with($u['email'], '.invalid'), 'e-mail zanonimizowany');
        T::assert($u['first_name'] !== 'Jan', 'imię usunięte');
        T::same(0, (int) DB::value('SELECT COUNT(*) FROM listings WHERE id = ?', [$draft]));
        T::same('archived', DB::value('SELECT status FROM listings WHERE id = ?', [$live]));

        Browser::post('/logowanie', ['email' => 'do-usuniecia@example.pl', 'password' => 'haslo-autora-123']);
        T::same(null, Session::get('user_id'), 'nie da się zalogować');
    },

    'Ostatni administrator nie może usunąć konta' => function (): void {
        auth_setup();
        login_staff();
        $r = Browser::post('/konto/usun', ['password' => 'haslo-zespolu-123', 'confirm' => '1']);
        T::same(422, $r->status);
        T::same('active', DB::value("SELECT status FROM users WHERE email = 'admin@example.pl'"));
    },

    'Obserwowane: przełącznik (JSON i formularz), lista, tylko widoczne oferty' => function (): void {
        auth_setup();
        ['ids' => $ids] = seed_market();
        login_author('kupiec@example.pl');
        $slug = DB::value('SELECT slug FROM listings WHERE id = ?', [$ids['a']]);
        T::assert(str_contains(Browser::get('/oferta/' . $slug)->body, 'aria-pressed="false"'));

        $r = Browser::post("/oferta/{$ids['a']}/obserwuj", [], ['HTTP_ACCEPT' => 'application/json']);
        T::same(['favorite' => true], json_decode($r->body, true));
        T::assert(str_contains(Browser::get('/oferta/' . $slug)->body, 'aria-pressed="true"'));
        $r = Browser::post("/oferta/{$ids['b']}/obserwuj", ['back' => '/konto/obserwowane']);
        T::same('/konto/obserwowane', $r->headers['Location']);
        $list = Browser::get('/konto/obserwowane')->body;
        T::assert(str_contains($list, 'Osiedle Słoneczne') && str_contains($list, 'Biurowiec Kraków'));

        DB::update('listings', ['status' => 'paused'], ['id' => $ids['b']]);
        T::assert(!str_contains(Browser::get('/konto/obserwowane')->body, 'Biurowiec Kraków'), 'wstrzymana znika z listy');
        T::same(404, Browser::post("/oferta/{$ids['draft']}/obserwuj")->status, 'szkicu nie można obserwować');
        Browser::post("/oferta/{$ids['a']}/obserwuj", ['back' => 'https://zly.example/']);
        T::same(1, (int) DB::value('SELECT COUNT(*) FROM favorites'), 'drugi klik usuwa');
    },

    'Zapisane wyszukiwania: zapis z listy, opis kryteriów, alert tylko o NOWYCH ofertach, limit' => function (): void {
        auth_setup();
        ['owner' => $owner] = seed_market();
        $uid = login_author('szukam@example.pl');
        $page = Browser::get('/oferty', ['region' => ['wielkopolskie']]);
        T::assert(str_contains($page->body, 'name="q[region][]"'), 'formularz zapisu przenosi filtry');

        $r = Browser::post('/konto/wyszukiwania', ['q' => ['region' => ['wielkopolskie'], 'strona' => '3', 'zly klucz' => 'x'], 'name' => 'Wielkopolska', 'frequency' => 'daily']);
        T::same('/konto/wyszukiwania', $r->headers['Location']);
        $s = DB::fetch('SELECT * FROM saved_searches WHERE user_id = ?', [$uid]);
        T::same(['region' => ['wielkopolskie']], json_decode($s['query'], true), 'bez strony i złych kluczy');
        T::assert(str_contains(SavedSearches::summary($s), 'wielkopolskie'));
        T::assert(str_contains(Browser::get('/konto/wyszukiwania')->body, 'Wielkopolska'));

        T::same(0, SavedSearches::sendDue(), 'za wcześnie');
        DB::query('UPDATE listings SET published_at = UTC_TIMESTAMP() - INTERVAL 10 DAY');
        DB::query('UPDATE saved_searches SET last_sent_at = UTC_TIMESTAMP() - INTERVAL 2 DAY');
        T::same(0, SavedSearches::sendDue(), 'brak nowych ofert = brak e-maila');

        DB::query('UPDATE saved_searches SET last_sent_at = UTC_TIMESTAMP() - INTERVAL 2 DAY');
        seed_listing($owner, 'Nowe osiedle Gniezno', ['investment_type' => 'residential_multi', 'region' => 'wielkopolskie', 'city' => 'Gniezno']);
        DB::query("UPDATE listings SET published_at = UTC_TIMESTAMP() WHERE title = 'Nowe osiedle Gniezno'");
        seed_listing($owner, 'Nowe biuro Kraków', ['investment_type' => 'office', 'region' => 'malopolskie']);
        DB::query("UPDATE listings SET published_at = UTC_TIMESTAMP() WHERE title = 'Nowe biuro Kraków'");
        Mailer::$sent = [];
        T::same(1, SavedSearches::sendDue());
        run_jobs();
        $mail = array_values(array_filter(Mailer::$sent, static fn($m) => $m['template'] === 'saved-search-alert'))[0] ?? null;
        T::assert($mail !== null, 'e-mail wysłany');
        T::same(['Nowe osiedle Gniezno'], array_column($mail['data']['items'], 'title'), 'tylko nowe i pasujące');
        T::same(0, SavedSearches::sendDue(), 'nie wysyła drugi raz');

        $id = (int) $s['id'];
        Browser::post("/konto/wyszukiwania/{$id}", ['frequency' => 'off']);
        T::same('off', DB::value('SELECT frequency FROM saved_searches WHERE id = ?', [$id]));
        Browser::post("/konto/wyszukiwania/{$id}", ['action' => 'delete']);
        T::same(0, (int) DB::value('SELECT COUNT(*) FROM saved_searches WHERE user_id = ?', [$uid]));

        for ($i = 0; $i < SavedSearches::MAX_PER_USER; $i++) {
            SavedSearches::save($uid, ['q' => "x{$i}"], "S{$i}");
        }
        T::same(null, SavedSearches::save($uid, ['q' => 'za dużo'], 'X'), 'limit');
    },

    'Moje dostępy: status prośby widoczny dla użytkownika' => function (): void {
        auth_setup();
        ['ids' => $ids] = seed_market();
        login_author('ciekawy@example.pl');
        Browser::post("/oferta/{$ids['a']}/dostep", ['message' => 'Proszę.']);
        $r = Browser::get('/konto/dostepy');
        T::assert(str_contains($r->body, 'Osiedle Słoneczne') && str_contains($r->body, 'Czeka na decyzję'));
    },

    'Moje oferty: statystyki z zapytaniami, wstrzymanie i wznowienie przez autora, cudzych nie' => function (): void {
        auth_setup();
        $uid = login_author();
        $id = seed_listing($uid, 'Moja hala', ['investment_type' => 'warehouse']);
        DB::query('INSERT INTO listing_stats (listing_id, day, views_public, views_member, inquiries) VALUES (?, UTC_DATE(), 7, 3, 1)', [$id]);
        $consent = DB::insert('consents', ['email' => 'k@example.pl', 'type' => 'inquiry', 'granted' => 1, 'ip' => inet_pton('203.0.113.1')]);
        DB::insert('inquiries', ['listing_id' => $id, 'name' => 'Kupujący Kowalski', 'email' => 'k@example.pl', 'message' => 'Czy cena do negocjacji?', 'consent_id' => $consent, 'ip' => inet_pton('203.0.113.1')]);

        $mine = Browser::get('/moje-oferty')->body;
        T::assert(str_contains($mine, '10 wyświetleń w 30 dni · zapytania: 1'));
        $stats = Browser::get("/moje-oferty/{$id}/statystyki");
        T::same(200, $stats->status);
        T::assert(str_contains($stats->body, 'Czy cena do negocjacji?') && str_contains($stats->body, 'chart__bar--100'));
        T::assert(!str_contains($stats->body, 'style="'), 'bez stylów inline (CSP)');

        Browser::post("/moje-oferty/{$id}/status", ['action' => 'pause']);
        T::same('paused', DB::value('SELECT status FROM listings WHERE id = ?', [$id]));
        Browser::post("/moje-oferty/{$id}/status", ['action' => 'resume']);
        T::same('published', DB::value('SELECT status FROM listings WHERE id = ?', [$id]));
        Browser::post("/moje-oferty/{$id}/status", ['action' => 'restore']);
        T::same('published', DB::value('SELECT status FROM listings WHERE id = ?', [$id]), 'autor nie przywraca z archiwum');

        Browser::fresh();
        login_author('obcy@example.pl');
        T::same(404, Browser::get("/moje-oferty/{$id}/statystyki")->status);
        T::same(404, Browser::post("/moje-oferty/{$id}/status", ['action' => 'archive'])->status);
        T::same('published', DB::value('SELECT status FROM listings WHERE id = ?', [$id]));
    },
];
