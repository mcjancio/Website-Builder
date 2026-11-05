<?php

declare(strict_types=1);

/**
 * Etap 3: kreator ofert, wartości pól w typowanych kolumnach, zdjęcia, dokumenty,
 * Gate (poziomy dostępu), moderacja nowych ofert i rewizji opublikowanych.
 * Korzysta z Browser / FakeNet / auth_setup z AuthDbTest.php.
 */

use App\Access\Gate;
use App\Auth\Auth;
use App\Core\DB;
use App\Core\Mailer;
use App\Core\Queue;
use App\Listings\Fields;
use App\Listings\ListingService;
use App\Listings\ValueStore;

function tmp_dir(): string
{
    $d = sys_get_temp_dir() . '/portal-test-' . getmypid();
    if (!is_dir($d)) {
        mkdir($d, 0700, true);
    }
    return $d;
}

function make_image(string $name, int $w, int $h, string $type = 'jpeg'): string
{
    $img = imagecreatetruecolor($w, $h);
    imagefilledrectangle($img, 0, 0, $w, $h, imagecolorallocate($img, 30, 120, 200));
    imagefilledellipse($img, intdiv($w, 2), intdiv($h, 2), intdiv($w, 2), intdiv($h, 2), imagecolorallocate($img, 240, 200, 40));
    $path = tmp_dir() . '/' . $name;
    $type === 'png' ? imagepng($img, $path) : imagejpeg($img, $path, 85);
    imagedestroy($img);
    return $path;
}

function make_file(string $name, string $content): string
{
    $path = tmp_dir() . '/' . $name;
    file_put_contents($path, $content);
    return $path;
}

function fake_pdf(string $name = 'teaser.pdf'): string
{
    return make_file($name, "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [] /Count 0 >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
}

/** Zalogowany autor z potwierdzonym e-mailem. */
function login_author(string $email = 'autor@example.pl'): int
{
    $id = create_user($email, 'haslo-autora-123', 'member', '+48600' . random_int(100000, 999999));
    Browser::post('/logowanie', ['email' => $email, 'password' => 'haslo-autora-123']);
    return $id;
}

function new_listing(string $title = 'Osiedle Zielone — Poznań, etap II'): int
{
    $cat = (int) DB::value("SELECT id FROM categories WHERE slug = 'pozyskanie-kapitalu'");
    $r = Browser::post('/dodaj-oferte', ['category_id' => $cat, 'title' => $title, 'short_desc' => 'Projekt mieszkaniowy z PnB, 36 lokali.']);
    preg_match('#/moje-oferty/(\d+)/edycja/2#', $r->headers['Location'] ?? '', $m);
    return (int) ($m[1] ?? 0);
}

function complete_fields(): array
{
    return ['f' => [
        'investment_type' => 'residential_multi', 'region' => 'wielkopolskie', 'city' => 'Poznań', 'project_stage' => 'permit',
        'project_cost' => ['amount' => '29 500 000', 'currency' => 'PLN'], 'capital_needed' => ['amount' => '1 250 000,50', 'currency' => 'EUR'],
        'expected_roi' => '18,5', 'investment_period' => 'medium', 'start_date' => (gmdate('Y') - 3) . '-04-01', 'in_progress' => '1',
        'investment_form' => ['equity', 'jv'], 'capital_security' => ['mortgage', 'kpc777'], 'usable_area' => '4 250,5', 'units_count' => '36',
        'video_urls' => "https://www.youtube.com/watch?v=abc\nhttps://vimeo.com/123",
    ]];
}

function publish(int $id): void
{
    Browser::post("/moje-oferty/{$id}/edycja/2", complete_fields());
    Browser::post("/moje-oferty/{$id}/wyslij");
    ListingService::approve($id, null);
}

return [
    'Kreator: szkic, walidacja, pola z presetu w typowanych kolumnach (kwoty w PLN wg NBP)' => function (): void {
        auth_setup();
        login_author();
        $page = Browser::get('/dodaj-oferte');
        T::same(200, $page->status);
        T::assert(str_contains($page->body, 'Projekt szuka kapitału') && str_contains($page->body, 'Dodaj inwestycję'), 'kategorie i słownictwo z presetu');

        $r = Browser::post('/dodaj-oferte', ['title' => '', 'short_desc' => str_repeat('x', 300)]);
        T::same(422, $r->status);
        T::assert(str_contains($r->body, 'Uzupełnij pole „Tytuł”') && str_contains($r->body, 'najwyżej 287 znaków'), 'limit krótkiego opisu z presetu');

        $id = new_listing();
        T::assert($id > 0);
        T::same('osiedle-zielone-poznan-etap-ii-' . $id, DB::value('SELECT slug FROM listings WHERE id = ?', [$id]));
        T::same('draft', DB::value('SELECT status FROM listings WHERE id = ?', [$id]));

        $form = complete_fields();
        $form['f']['units_count'] = 'trzydzieści';
        $r = Browser::post("/moje-oferty/{$id}/edycja/2", $form);
        T::same(422, $r->status);
        T::assert(str_contains($r->body, 'W polu „Liczba lokali” wpisz liczbę całkowitą.'));
        T::same('wielkopolskie', DB::value('SELECT region FROM listings WHERE id = ?', [$id]), 'poprawne pola zapisane mimo błędu w innym');

        $r = Browser::post("/moje-oferty/{$id}/edycja/2", complete_fields());
        T::same("/moje-oferty/{$id}/edycja/3", $r->headers['Location']);
        $cap = DB::fetch("SELECT v.* FROM listing_values v JOIN field_definitions f ON f.id = v.field_id WHERE v.listing_id = ? AND f.`key` = 'capital_needed'", [$id]);
        T::same(['1250000.5000', 'EUR', '5375002.15'], [$cap['v_dec'], $cap['currency'], $cap['v_dec_pln']], 'EUR × 4,30 z NBP');
        T::same(36, (int) DB::value("SELECT v_int FROM listing_values v JOIN field_definitions f ON f.id = v.field_id WHERE v.listing_id = ? AND f.`key` = 'units_count'", [$id]));
        T::same(2, (int) DB::value("SELECT COUNT(*) FROM listing_value_options o JOIN field_definitions f ON f.id = o.field_id WHERE o.listing_id = ? AND f.`key` = 'capital_security'", [$id]));
        T::same('Poznań', DB::value('SELECT city FROM listings WHERE id = ?', [$id]));

        $v = ValueStore::load($id);
        T::same(['amount' => '1250000.50', 'currency' => 'EUR'], $v['capital_needed']);
        T::same('18.5', $v['expected_roi']);
        T::same('4250.5', $v['usable_area']);
        T::same(['equity', 'jv'], $v['investment_form']);
        T::same(true, $v['in_progress']);
        T::same(['https://www.youtube.com/watch?v=abc', 'https://vimeo.com/123'], $v['video_urls']);
        T::same((gmdate('Y') - 3) . '-04-01', $v['start_date'], 'data z przeszłości (projekt w toku)');
        T::assert(str_contains(Browser::get("/moje-oferty/{$id}/edycja/2")->body, 'value="1' . "\u{00A0}" . '250' . "\u{00A0}" . '000,50"'), 'kwota sformatowana po polsku');

        $r = Browser::post("/moje-oferty/{$id}/edycja/3", ['full_desc_md' => "## Lokalizacja\n\nBlisko centrum.", 'confidential_md' => 'Działka 12/3'], ['HTTP_X_AUTOSAVE' => '1']);
        $j = json_decode($r->body, true);
        T::same(true, $j['saved'], 'autozapis');
        T::assert(str_starts_with((string) $j['message'], 'Zapisano automatycznie o '));
        T::same('Działka 12/3', DB::value('SELECT confidential_md FROM listings WHERE id = ?', [$id]));
    },

    'Zdjęcia: WebP w 3 rozmiarach, okładka, kolejność, złe pliki odrzucone, dostęp' => function (): void {
        auth_setup();
        login_author();
        $id = new_listing();
        $r = Browser::upload("/moje-oferty/{$id}/zdjecia", ['images' => [make_image('a.jpg', 2400, 1600), make_image('b.png', 800, 600, 'png'), make_file('virus.jpg', '<?php echo 1;')]], [], true);
        $j = json_decode($r->body, true);
        T::same(2, $j['added']);
        T::assert(str_contains($j['errors'][0] ?? '', '„virus.jpg” nie jest zdjęciem'), 'tekst udający JPG odrzucony');

        $media = DB::fetchAll('SELECT * FROM media WHERE listing_id = ? ORDER BY sort_order', [$id]);
        T::same(2, count($media));
        foreach ([400, 960, 1600] as $s) {
            $p = APP_ROOT . '/storage/uploads/' . $media[0]['path_base'] . '_' . $s . '.webp';
            T::assert(is_file($p), "plik {$s}");
            T::same($s, getimagesize($p)[0], "szerokość {$s}");
        }
        T::same(800, getimagesize(APP_ROOT . '/storage/uploads/' . $media[1]['path_base'] . '_1600.webp')[0], 'bez powiększania małych zdjęć');
        T::same([1, 0], array_map('intval', array_column($media, 'is_cover')), 'pierwsze = okładka');

        Browser::post("/moje-oferty/{$id}/zdjecia/{$media[1]['id']}", ['action' => 'okladka']);
        Browser::post("/moje-oferty/{$id}/zdjecia/{$media[1]['id']}", ['action' => 'w-gore']);
        T::same([(int) $media[1]['id'], (int) $media[0]['id']], array_map('intval', array_column(DB::fetchAll('SELECT id FROM media WHERE listing_id = ? ORDER BY sort_order', [$id]), 'id')));
        T::same((int) $media[1]['id'], (int) DB::value('SELECT id FROM media WHERE listing_id = ? AND is_cover = 1', [$id]));

        $img = Browser::get('/media/' . $media[0]['id'] . '/960');
        T::same(200, $img->status);
        T::same('image/webp', $img->headers['Content-Type']);
        T::same('private, max-age=600', $img->headers['Cache-Control'], 'szkic — bez publicznego cache');
        Browser::post('/wyloguj');
        T::same(404, Browser::get('/media/' . $media[0]['id'] . '/960')->status, 'zdjęcie szkicu niewidoczne dla obcych');

        $files = glob(APP_ROOT . '/storage/uploads/' . $media[0]['path_base'] . '_*.webp');
        login_author('autor2@example.pl');
        T::same(404, Browser::get("/moje-oferty/{$id}/edycja/3")->status, 'cudzej oferty nie edytujesz');
        Browser::post('/wyloguj');
        Browser::post('/logowanie', ['email' => 'autor@example.pl', 'password' => 'haslo-autora-123']);
        Browser::post("/moje-oferty/{$id}/zdjecia/{$media[1]['id']}", ['action' => 'usun']);
        T::same((int) $media[0]['id'], (int) DB::value('SELECT id FROM media WHERE listing_id = ? AND is_cover = 1', [$id]), 'po usunięciu okładki — następne zdjęcie');
        Browser::post("/moje-oferty/{$id}/usun");
        T::same(0, (int) DB::value('SELECT COUNT(*) FROM listings WHERE id = ?', [$id]));
        T::same(0, count(array_filter($files, 'is_file')), 'pliki usunięte razem ze szkicem');
    },

    'Dokumenty: typy z presetu, poziom dostępu, pobieranie przez Gate z logiem' => function (): void {
        auth_setup();
        login_author();
        $id = new_listing();
        $r = Browser::upload("/moje-oferty/{$id}/dokumenty", ['files' => [fake_pdf(), make_file('setup.exe', "MZ\x90\x00binary")]], ['label' => 'Teaser', 'access_level' => '3'], true);
        $j = json_decode($r->body, true);
        T::same(1, $j['added']);
        T::assert(str_contains($j['errors'][0] ?? '', 'niedozwolony format'), 'exe odrzucony');
        Browser::upload("/moje-oferty/{$id}/dokumenty", ['files' => [fake_pdf('prezentacja.pdf')]], ['access_level' => '1']);
        $atts = DB::fetchAll('SELECT * FROM attachments WHERE listing_id = ? ORDER BY sort_order', [$id]);
        T::same(['Teaser', 'prezentacja'], array_column($atts, 'label'));
        T::same(['application/pdf', 'application/pdf'], array_column($atts, 'mime'));
        T::assert(!str_starts_with($atts[0]['stored_path'], 'public'), 'poza public_html');

        Browser::post("/moje-oferty/{$id}/edycja/4", ['att' => [$atts[0]['id'] => ['label' => 'Teaser inwestycyjny', 'access_level' => '3'], 999999 => ['label' => 'obcy', 'access_level' => '1']], 'go' => 'stay']);
        T::same('Teaser inwestycyjny', DB::value('SELECT label FROM attachments WHERE id = ?', [$atts[0]['id']]));

        publish($id);
        Browser::post('/wyloguj');
        T::same(401, Browser::get('/plik/' . $atts[0]['id'])->status, 'L3 — niezalogowany');
        $pub = Browser::get('/plik/' . $atts[1]['id']);
        T::same(200, $pub->status, 'L1 — każdy');
        T::assert(str_starts_with($pub->body, '%PDF'));
        T::assert(str_contains($pub->headers['Content-Disposition'], "filename*=UTF-8''prezentacja.pdf"));

        $other = login_author('inwestor@example.pl');
        T::same(403, Browser::get('/plik/' . $atts[0]['id'])->status, 'L3 — zalogowany bez dostępu');
        DB::insert('access_grants', ['listing_id' => $id, 'user_id' => $other, 'status' => 'granted']);
        Gate::flush();
        T::same(200, Browser::get('/plik/' . $atts[0]['id'])->status, 'L3 — po nadaniu dostępu');
        T::same(1, (int) DB::value('SELECT COUNT(*) FROM downloads WHERE attachment_id = ? AND user_id = ?', [$atts[0]['id'], $other]));
        T::same(1, (int) DB::value("SELECT COUNT(*) FROM audit_log WHERE action = 'file.download'"), 'pobranie L3 w dzienniku');
        T::same(2, (int) DB::value("SELECT COUNT(*) FROM audit_log WHERE action = 'file.denied'"), 'odmowy (gość i zalogowany bez dostępu) w dzienniku');
    },

    'Gate: poziomy L1/L2/L3, autor i moderacja, wygasły dostęp, szkic niewidoczny' => function (): void {
        auth_setup();
        $owner = DB::fetch('SELECT * FROM users WHERE id = ?', [create_user('o@example.pl', 'x-haslo-12345', 'member', '+48600000001')]);
        $member = DB::fetch('SELECT * FROM users WHERE id = ?', [create_user('m@example.pl', 'x-haslo-12345', 'member', '+48600000002')]);
        $mod = DB::fetch('SELECT * FROM users WHERE id = ?', [create_user('mod@example.pl', 'x-haslo-12345', 'moderator', '+48600000003')]);
        $unverified = DB::fetch('SELECT * FROM users WHERE id = ?', [create_user('u@example.pl', 'x-haslo-12345', 'member', '+48600000004')]);
        DB::query('UPDATE users SET email_verified_at = NULL, created_at = UTC_TIMESTAMP() - INTERVAL 3 DAY WHERE id = ?', [$unverified['id']]);
        $unverified = DB::fetch('SELECT * FROM users WHERE id = ?', [$unverified['id']]);
        $cat = (int) DB::value('SELECT id FROM categories LIMIT 1');
        $lid = ListingService::create((int) $owner['id'], $cat, 'Test', 'Opis');
        $l = ListingService::find($lid);

        T::assert(!Gate::canView(null, $l) && !Gate::canView($member, $l), 'szkic tylko dla autora');
        T::assert(Gate::canView($owner, $l) && Gate::canView($mod, $l));
        DB::update('listings', ['status' => 'published'], ['id' => $lid]);
        $l = ListingService::find($lid);
        T::same([1, 2, 1, 3, 3], [Gate::level(null, $l), Gate::level($member, $l), Gate::level($unverified, $l), Gate::level($owner, $l), Gate::level($mod, $l)],
            'gość L1, użytkownik L2, bez potwierdzonego e-maila po okresie łaski L1, autor i moderator L3');
        DB::insert('access_grants', ['listing_id' => $lid, 'user_id' => $member['id'], 'status' => 'granted', 'expires_at' => gmdate('Y-m-d H:i:s', time() - 60)]);
        Gate::flush();
        T::same(2, Gate::level($member, $l), 'wygasły dostęp');
        DB::query('UPDATE access_grants SET expires_at = NULL');
        Gate::flush();
        T::same(3, Gate::level($member, $l));
        DB::query("UPDATE access_grants SET status = 'revoked'");
        Gate::flush();
        T::same(2, Gate::level($member, $l), 'odebrany dostęp');
    },

    'Moderacja nowej oferty: lista braków, e-mail do moderatora, poprawki cofają do szkicu, akceptacja' => function (): void {
        auth_setup();
        create_user('moderator@example.pl', 'haslo-mod-12345', 'moderator', '+48600000099');
        login_author();
        $id = new_listing();

        $r = Browser::post("/moje-oferty/{$id}/wyslij");
        T::same("/moje-oferty/{$id}/edycja/4", $r->headers['Location']);
        $step4 = Browser::get("/moje-oferty/{$id}/edycja/4")->body;
        T::assert(str_contains($step4, 'Przed wysłaniem uzupełnij') && str_contains($step4, 'Typ inwestycji') && str_contains($step4, 'Potrzebny kapitał'));
        T::assert(str_contains($step4, "edycja/2#f-capital_needed"), 'link do brakującego pola');
        T::same('draft', DB::value('SELECT status FROM listings WHERE id = ?', [$id]));

        Browser::post("/moje-oferty/{$id}/edycja/2", complete_fields());
        Browser::post("/moje-oferty/{$id}/wyslij");
        T::same('pending', DB::value('SELECT status FROM listings WHERE id = ?', [$id]));
        run_jobs();
        $mail = array_values(array_filter(Mailer::$sent, fn($m) => $m['template'] === 'moderation-pending'));
        T::same('moderator@example.pl', $mail[0]['to'] ?? null);
        T::assert(str_contains($mail[0]['subject'] ?? '', 'Do akceptacji: Osiedle Zielone'));

        $cat = (int) DB::value('SELECT category_id FROM listings WHERE id = ?', [$id]);
        $r = Browser::post("/moje-oferty/{$id}/edycja/1", ['category_id' => $cat, 'title' => 'Osiedle Zielone II', 'short_desc' => 'Nowy opis']);
        T::same(302, $r->status);
        T::same('draft', DB::value('SELECT status FROM listings WHERE id = ?', [$id]), 'edycja w trakcie moderacji cofa do szkicu');
        T::assert(str_contains(Browser::get("/moje-oferty/{$id}/edycja/2")->body, 'wróciła do szkicu'));
        T::throws(fn() => ListingService::approve($id, null), \RuntimeException::class, 'szkicu nie da się zaakceptować');

        Browser::post("/moje-oferty/{$id}/wyslij");
        T::same('new', ListingService::approve($id, null));
        $l = ListingService::find($id);
        T::same('published', $l['status']);
        T::assert($l['published_at'] !== null);
        run_jobs();
        T::assert(count(array_filter(Mailer::$sent, fn($m) => $m['template'] === 'listing-approved' && $m['to'] === 'autor@example.pl')) === 1);
        T::assert(str_contains(Browser::get('/moje-oferty')->body, 'Opublikowana'));
    },

    'Edycja opublikowanej: rewizja, publiczna wersja bez zmian, pliki czekają, odrzucenie i akceptacja' => function (): void {
        auth_setup();
        login_author();
        $id = new_listing();
        Browser::upload("/moje-oferty/{$id}/zdjecia", ['images' => [make_image('live.jpg', 1200, 800)]]);
        publish($id);
        $liveImg = (int) DB::value('SELECT id FROM media WHERE listing_id = ?', [$id]);
        $slug = DB::value('SELECT slug FROM listings WHERE id = ?', [$id]);

        Browser::post("/moje-oferty/{$id}/edycja/1", ['title' => 'Osiedle Zielone — NOWY TYTUŁ', 'short_desc' => 'Zmieniony opis']);
        $f = complete_fields();
        $f['f']['units_count'] = '40';
        Browser::post("/moje-oferty/{$id}/edycja/2", $f);
        Browser::upload("/moje-oferty/{$id}/zdjecia", ['images' => [make_image('nowe.jpg', 1000, 700)]]);
        Browser::post("/moje-oferty/{$id}/zdjecia/{$liveImg}", ['action' => 'usun']);

        $l = ListingService::find($id);
        T::same('Osiedle Zielone — Poznań, etap II', $l['title'], 'publiczna wersja bez zmian');
        T::same(36, ValueStore::load($id)['units_count']);
        T::same('draft', DB::value('SELECT status FROM listing_revisions WHERE listing_id = ?', [$id]));
        T::assert(str_contains(Browser::get("/moje-oferty/{$id}/edycja/1")->body, 'NOWY TYTUŁ'), 'formularz pokazuje wersję roboczą');
        $newImg = (int) DB::value("SELECT id FROM media WHERE listing_id = ? AND state = 'pending_add'", [$id]);
        T::same('pending_remove', DB::value('SELECT state FROM media WHERE id = ?', [$liveImg]));

        Browser::post('/wyloguj');
        T::same(404, Browser::get("/media/{$newImg}/400")->status, 'nowe zdjęcie niewidoczne publicznie');
        T::same(200, Browser::get("/media/{$liveImg}/400")->status, 'usuwane zdjęcie widoczne do akceptacji');
        T::same('public, max-age=86400', Browser::get("/media/{$liveImg}/400")->headers['Cache-Control']);
        Browser::post('/logowanie', ['email' => 'autor@example.pl', 'password' => 'haslo-autora-123']);

        Browser::post("/moje-oferty/{$id}/wyslij");
        T::same('pending', DB::value('SELECT status FROM listing_revisions WHERE listing_id = ?', [$id]));
        T::same([['id' => $id, 'kind' => 'revision']], array_map(fn($q) => ['id' => (int) $q['id'], 'kind' => $q['kind']], ListingService::pendingQueue()));

        $newFiles = glob(APP_ROOT . '/storage/uploads/' . DB::value('SELECT path_base FROM media WHERE id = ?', [$newImg]) . '_*.webp');
        ListingService::reject($id, null, 'Zdjęcie słabej jakości.');
        T::same('published', DB::value('SELECT status FROM listings WHERE id = ?', [$id]), 'odrzucenie zmian nie zdejmuje oferty');
        T::same(0, (int) DB::value('SELECT COUNT(*) FROM media WHERE id = ?', [$newImg]));
        T::same(0, count(array_filter($newFiles, 'is_file')), 'pliki odrzuconego zdjęcia usunięte');
        T::same('live', DB::value('SELECT state FROM media WHERE id = ?', [$liveImg]), 'usunięcie cofnięte');
        T::assert(str_contains(Browser::get('/moje-oferty')->body, 'Zmiany odrzucone: Zdjęcie słabej jakości.'));

        Browser::post("/moje-oferty/{$id}/edycja/1", ['title' => 'Osiedle Zielone — NOWY TYTUŁ', 'short_desc' => 'Zmieniony opis']);
        Browser::post("/moje-oferty/{$id}/edycja/2", $f);
        Browser::post("/moje-oferty/{$id}/wyslij");
        T::same('revision', ListingService::approve($id, null));
        $l = ListingService::find($id);
        T::same('Osiedle Zielone — NOWY TYTUŁ', $l['title']);
        T::assert($l['slug'] !== $slug && str_ends_with($l['slug'], '-' . $id), 'nowy slug');
        T::same(40, ValueStore::load($id)['units_count']);
        T::same('approved', DB::value('SELECT status FROM listing_revisions WHERE listing_id = ? ORDER BY id DESC LIMIT 1', [$id]));
        T::same(null, ListingService::openRevision($id));
        T::same(0, (int) DB::value('SELECT COUNT(*) FROM listings WHERE id = ? AND status <> ?', [$id, 'published']));
        T::same(false, ListingService::deleteDraft($l), 'opublikowanej nie usuwa się jak szkicu');
    },

    'Inny preset: pola zależne od kategorii (z dziedziczeniem z kategorii nadrzędnej)' => function (): void {
        auth_setup('maszyny-i-sprzet');
        $koparki = (int) DB::value("SELECT id FROM categories WHERE slug = 'koparki'");
        $rolnicze = (int) DB::value("SELECT id FROM categories WHERE slug = 'rolnicze'");
        $k = array_keys(Fields::forCategory($koparki));
        $r = array_keys(Fields::forCategory($rolnicze));
        T::assert(in_array('bucket_capacity', $k, true) && in_array('operating_weight', $k, true), 'koparka: łyżka + masa (z „budowlane”)');
        T::assert(!in_array('power_hp', $k, true));
        T::assert(in_array('power_hp', $r, true) && !in_array('bucket_capacity', $r, true));
        T::assert(in_array('price', $k, true) && in_array('price', $r, true), 'pola wspólne wszędzie');

        login_author();
        $res = Browser::post('/dodaj-oferte', ['category_id' => $koparki, 'title' => 'Koparka CAT 320', 'short_desc' => 'Stan bardzo dobry']);
        preg_match('#/moje-oferty/(\d+)/#', $res->headers['Location'], $m);
        $page = Browser::get("/moje-oferty/{$m[1]}/edycja/2")->body;
        T::assert(str_contains($page, 'Pojemność łyżki') && !str_contains($page, 'Moc (KM)'));
        T::assert(str_contains(Browser::get('/moje-oferty')->body, 'Wystaw maszynę'), 'słownictwo presetu na liście ofert');
    },
];
