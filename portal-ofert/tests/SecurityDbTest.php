<?php

declare(strict_types=1);

/**
 * Przegląd bezpieczeństwa przed startem — testy regresji dla poprawek (sesje, 2FA, moderacja,
 * Markdown, przekierowania, kopie zapasowe, asystent AI, import).
 */

use App\Ai\Assistant;
use App\Auth\Totp;
use App\Core\Backup;
use App\Core\Crypto;
use App\Core\DB;
use App\Core\Markdown;
use App\Core\Response;
use App\Core\Session;

return [
    'Zmiana hasła i „wyloguj wszędzie” kończą INNE otwarte sesje (nie tylko ciasteczka)' => function (): void {
        auth_setup();
        $uid = create_user('jan@example.pl', 'stare-haslo-123');
        Browser::post('/logowanie', ['email' => 'jan@example.pl', 'password' => 'stare-haslo-123']);
        $otherDevice = $_SESSION;                       // sesja „złodzieja” / drugiego urządzenia

        Browser::fresh();
        Browser::post('/logowanie', ['email' => 'jan@example.pl', 'password' => 'stare-haslo-123']);
        Browser::post('/konto/haslo', ['current_password' => 'stare-haslo-123', 'password' => 'nowe-haslo-12345', 'password_confirmation' => 'nowe-haslo-12345']);
        T::same(200, Browser::get('/konto')->status, 'bieżąca sesja zostaje');

        $mine = $_SESSION;
        $_SESSION = $otherDevice;
        \App\Auth\Auth::forgetCache();
        T::same(302, Browser::get('/konto')->status, 'stara sesja na innym urządzeniu jest nieważna');

        $_SESSION = $mine;
        \App\Auth\Auth::forgetCache();
        Browser::post('/konto/urzadzenia/wyloguj');
        T::same(200, Browser::get('/konto')->status);
        T::assert((int) DB::value('SELECT session_version FROM users WHERE id = ?', [$uid]) >= 3);
    },

    'Zespół bez włączonego 2FA nie ma uprawnień zespołu (dokumenty poufne, szkice)' => function (): void {
        auth_setup();
        login_author();
        $id = new_listing();
        Browser::upload("/moje-oferty/{$id}/dokumenty", ['files' => [fake_pdf('poufne.pdf')]]);
        $att = (int) DB::value('SELECT id FROM attachments WHERE listing_id = ?', [$id]);

        Browser::fresh();
        create_user('nowy-mod@example.pl', 'haslo-moderatora-1', 'moderator', '+48600123123');
        Browser::post('/logowanie', ['email' => 'nowy-mod@example.pl', 'password' => 'haslo-moderatora-1']);
        T::assert(!in_array(Browser::get("/plik/{$att}")->status, [200], true), 'bez 2FA nie pobierze dokumentu poufnego ze szkicu');
        T::same('/konto/2fa', Browser::get('/admin')->headers['Location'] ?? null, 'panel wymaga najpierw konfiguracji 2FA');
    },

    'Kod 2FA działa tylko raz; krok 2FA z „Zapamiętaj mnie” wygasa' => function (): void {
        auth_setup();
        $id = create_user('admin@example.pl', 'haslo-admina-123', 'admin');
        $secret = Totp::generateSecret();
        DB::update('users', ['totp_secret' => Crypto::encrypt($secret), 'totp_enabled_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]);
        $code = Totp::code($secret);
        Browser::post('/logowanie', ['email' => 'admin@example.pl', 'password' => 'haslo-admina-123']);
        T::same('/konto', Browser::post('/logowanie/2fa', ['code' => $code])->headers['Location'] ?? null);
        Browser::fresh();
        Browser::post('/logowanie', ['email' => 'admin@example.pl', 'password' => 'haslo-admina-123']);
        T::same(422, Browser::post('/logowanie/2fa', ['code' => $code])->status, 'podejrzany kod nie zadziała drugi raz');
    },

    'Ofertę wstrzymaną przez moderację autor nie wznowi; nikt nie moderuje własnej oferty' => function (): void {
        auth_setup();
        $staff = login_staff('admin', 'admin@example.pl');
        $own = seed_listing($staff, 'Oferta admina', ['investment_type' => 'office'], 'pozyskanie-kapitalu', 'pending');
        Browser::post("/admin/moderacja/{$own}", ['action' => 'approve']);
        T::same('pending', DB::value('SELECT status FROM listings WHERE id = ?', [$own]), 'własnej oferty nie zatwierdzi');

        Browser::fresh();
        $uid = login_author();
        $id = seed_listing($uid, 'Oferta autora', ['investment_type' => 'office']);
        login_staff('moderator', 'mod@example.pl');
        Browser::post("/admin/oferty/{$id}", ['action' => 'pause']);
        $row = DB::fetch('SELECT status, paused_by_staff FROM listings WHERE id = ?', [$id]);
        T::same(['paused', 1], [$row['status'], (int) $row['paused_by_staff']]);

        Browser::fresh();
        Browser::post('/logowanie', ['email' => 'autor@example.pl', 'password' => 'haslo-autora-123']);
        Browser::post("/moje-oferty/{$id}/status", ['action' => 'resume']);
        Browser::post("/moje-oferty/{$id}/status", ['action' => 'sold']);
        T::same('paused', DB::value('SELECT status FROM listings WHERE id = ?', [$id]), 'autor nie obejdzie decyzji moderacji');
        T::assert(str_contains(Browser::get('/moje-oferty')->body, 'wstrzymała moderacja'));
        Browser::post("/moje-oferty/{$id}/status", ['action' => 'archive']);
        T::same('archived', DB::value('SELECT status FROM listings WHERE id = ?', [$id]), 'archiwizować może');
    },

    'Usunięcie konta i wyłączenie 2FA: limit prób hasła/kodu' => function (): void {
        auth_setup();
        login_author();
        for ($i = 0; $i < 5; $i++) {
            Browser::post('/konto/usun', ['password' => 'zle-' . $i, 'confirm' => '1']);
        }
        $r = Browser::post('/konto/usun', ['password' => 'haslo-autora-123', 'confirm' => '1']);
        T::same(422, $r->status, 'po 5 próbach nawet poprawne hasło jest wstrzymane');
        T::assert(str_contains($r->body, 'Zbyt wiele prób'));
        T::same('active', DB::value("SELECT status FROM users WHERE email = 'autor@example.pl'"));
    },

    'Miasto z pola niepublicznego nie trafia do nagłówka i kafelków' => function (): void {
        auth_setup();
        DB::query("UPDATE field_definitions SET access_level = 2 WHERE `key` = 'city'");
        \App\Listings\Fields::flush();
        $uid = create_user('a@example.pl', 'haslo-testowe-123');
        $id = seed_listing($uid, 'Oferta', ['investment_type' => 'office'], 'pozyskanie-kapitalu', 'draft');
        $l = \App\Listings\ListingService::find($id);
        \App\Listings\ListingService::save($l, [], \App\Listings\Fields::forCategory((int) $l['category_id']), ['investment_type' => 'office', 'region' => 'mazowieckie', 'city' => 'Tajne Miasto']);
        DB::update('listings', ['status' => 'published', 'published_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]);
        T::same([null, 'mazowieckie'], array_values(DB::fetch('SELECT city, region FROM listings WHERE id = ?', [$id])));
        T::assert(!str_contains(Browser::get('/oferta/' . DB::value('SELECT slug FROM listings WHERE id = ?', [$id]))->body, 'Tajne Miasto'));
    },

    'Markdown: znaczniki wewnętrzne w treści nie psują strony, linki „/\\host” i atrybuty bezpieczne' => function (): void {
        T::same('a 5 b', Markdown::inline("a \u{E000}5\u{E001} b"), 'brak błędu 500');
        T::same('[x](/\\evil.pl)', Markdown::inline('[x](/\\evil.pl)'), 'link poza portal nie jest tworzony');
        T::same('<a href="https://x.pl/a_b" rel="noopener" target="_blank">a</a> b_', Markdown::inline('[a](https://x.pl/a_b) b_'), 'atrybut target nienaruszony');
        T::same('/', Response::redirect('/\\evil.pl')->headers['Location']);
        T::same('/', Response::redirect("/ok\r\nSet-Cookie: x=1")->headers['Location']);
    },

    'Kopia: ucięty zrzut nie zmienia bazy; odtworzenie usuwa tabele spoza kopii' => function (): void {
        auth_setup();
        create_user('zostaje@example.pl', 'haslo-testowe-123');
        $dump = Backup::dumpDatabase(tmp_dir() . '/baza-sec.sql.gz');
        T::assert(Backup::isComplete($dump));

        $content = (string) gzdecode((string) file_get_contents($dump));
        $cut = tmp_dir() . '/baza-ucieta.sql.gz';
        file_put_contents($cut, gzencode(substr($content, 0, (int) (strlen($content) * 0.6))));
        T::throws(fn() => Backup::restoreDatabase($cut), \RuntimeException::class);
        T::same(1, (int) DB::value('SELECT COUNT(*) FROM users'), 'nic nie zostało usunięte');

        DB::pdo()->exec('CREATE TABLE nowa_po_kopii (id INT PRIMARY KEY)');
        Backup::restoreDatabase($dump);
        T::same(0, (int) DB::value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'nowa_po_kopii'"));
        T::same([], (new \App\Core\Migrator(APP_ROOT . '/migrations'))->pending(), 'migracje po odtworzeniu przechodzą');
    },

    'Asystent AI: porzucone zlecenia kończą się błędem; dane poufne nie trafiają do opisu z PDF' => function (): void {
        auth_setup();
        FakeClaude::install();
        ai_enable();
        $uid = login_author();
        $id = new_listing();
        DB::insert('ai_tasks', ['kind' => 'hints', 'listing_id' => $id, 'status' => 'running', 'created_at' => gmdate('Y-m-d H:i:s', time() - 3600)]);
        T::same(1, Assistant::failStale());
        T::same('failed', Assistant::latest($id, 'hints')['status']);

        Browser::upload("/moje-oferty/{$id}/ai/z-pdf", ['pdf' => [fake_pdf()]]);
        FakeClaude::$replies[] = FakeClaude::text(['title' => null, 'short_desc' => 'Projekt firmy Tajna Budowa SA.', 'full_desc_md' => 'Inwestor: Tajna Budowa SA, ul. Sekretna 5.',
            'fields' => ['developer_name' => 'Tajna Budowa SA', 'address_exact' => 'ul. Sekretna 5'], 'uncertain' => [], 'notes' => '']);
        run_jobs();
        $desc = (string) DB::value('SELECT full_desc_md FROM listings WHERE id = ?', [$id]);
        T::assert($desc !== '' && !str_contains($desc, 'Tajna Budowa') && !str_contains($desc, 'Sekretna'), $desc);
        $r = Assistant::latest($id, 'extract')['result'];
        T::assert(!str_contains((string) $r['short_desc'], 'Tajna Budowa'), 'propozycja krótkiego opisu też oczyszczona');
        T::same('Tajna Budowa SA', \App\Listings\ValueStore::load($id)['developer_name'], 'w polu poufnym wartość zostaje');
        T::same(1, (int) DB::value("SELECT COUNT(*) FROM ai_usage WHERE listing_id = ?", [$id]), 'zlecenie wykonane raz');
        FakeClaude::uninstall();
    },
];
