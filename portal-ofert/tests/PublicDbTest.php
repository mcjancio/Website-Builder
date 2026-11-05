<?php

declare(strict_types=1);

/**
 * Etap 4: lista ofert z filtrami z presetu, strona oferty z poziomami dostępu,
 * zapytania, prośby o dostęp L3, statystyki, SEO.
 * Korzysta z helperów z AuthDbTest.php i ListingsDbTest.php.
 */

use App\Access\AccessService;
use App\Core\DB;
use App\Core\Mailer;
use App\Listings\Fields;
use App\Listings\ListingQuery;
use App\Listings\ListingService;
use App\Listings\ValueStore;

/** Opublikowana oferta z wartościami pól (bez przeglądarki — szybciej). */
function seed_listing(int $userId, string $title, array $values, string $catSlug = 'pozyskanie-kapitalu', string $status = 'published', array $core = []): int
{
    $cat = (int) DB::value('SELECT id FROM categories WHERE slug = ?', [$catSlug]);
    $id = ListingService::create($userId, $cat, $title, $core['short_desc'] ?? 'Krótki opis: ' . $title);
    $fields = Fields::forCategory($cat);
    ValueStore::save($id, $fields, $values);
    DB::update('listings', [
        'status' => $status,
        'published_at' => $status === 'published' ? gmdate('Y-m-d H:i:s', time() - 3600 + $id) : null,
        'region' => $values['region'] ?? null,
        'city' => $values['city'] ?? null,
        'full_desc_md' => $core['full_desc_md'] ?? null,
        'confidential_md' => $core['confidential_md'] ?? null,
    ], ['id' => $id]);
    return $id;
}

function seed_market(): array
{
    $owner = create_user('wlasciciel@example.pl', 'haslo-wlasciciela-1', 'member', '+48600111222');
    $ids = [];
    $ids['a'] = seed_listing($owner, 'Osiedle Słoneczne Poznań', [
        'investment_type' => 'residential_multi', 'region' => 'wielkopolskie', 'city' => 'Poznań', 'project_stage' => 'permit',
        'capital_needed' => ['amount' => '2000000.00', 'currency' => 'PLN'], 'expected_roi' => '18', 'investment_period' => 'medium',
        'investment_form' => ['equity', 'jv'], 'capital_security' => ['mortgage'], 'units_count' => 36, 'esg' => true,
        'video_urls' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://example.com/film'],
    ], 'pozyskanie-kapitalu', 'published', [
        'full_desc_md' => "## Lokalizacja\n\nBlisko parku.", 'confidential_md' => 'Działka nr 123/4, obręb Jeżyce',
    ]);
    $ids['b'] = seed_listing($owner, 'Biurowiec Kraków', [
        'investment_type' => 'office', 'region' => 'malopolskie', 'city' => 'Kraków', 'project_stage' => 'construction',
        'capital_needed' => ['amount' => '1000000.00', 'currency' => 'EUR'], 'expected_roi' => '12', 'investment_period' => 'long',
        'investment_form' => ['bonds'], 'capital_security' => ['blank_note'],
    ]);
    $ids['c'] = seed_listing($owner, 'Magazyn Wrocław', [
        'investment_type' => 'warehouse', 'region' => 'dolnoslaskie', 'city' => 'Wrocław', 'project_stage' => 'idea',
        'capital_needed' => ['amount' => '9000000.00', 'currency' => 'PLN'], 'expected_roi' => '25', 'investment_period' => 'short',
        'investment_form' => ['loan'], 'capital_security' => ['mortgage', 'aval'],
    ], 'joint-venture');
    $ids['draft'] = seed_listing($owner, 'Szkic niewidoczny', ['investment_type' => 'office'], 'pozyskanie-kapitalu', 'draft');
    return ['owner' => $owner, 'ids' => $ids];
}

function titles(array $result): array
{
    return array_column($result['items'], 'title');
}

return [
    'Filtry: opcje (lub), multiselect, region, zakres kwot w PLN (EUR wg NBP), ROI, toggle, kategoria, tekst, sort' => function (): void {
        auth_setup();
        seed_market();
        $all = ListingQuery::search([], 1);
        T::same(3, $all['total'], 'tylko opublikowane');
        T::same(['Magazyn Wrocław', 'Biurowiec Kraków', 'Osiedle Słoneczne Poznań'], titles($all), 'najnowsze pierwsze');

        T::same(['Osiedle Słoneczne Poznań'], titles(ListingQuery::search(['investment_type' => ['residential_multi']], 1)));
        T::same(2, ListingQuery::search(['investment_type' => ['residential_multi', 'office']], 1)['total'], 'kilka opcji = dowolna z nich');
        T::same(['Osiedle Słoneczne Poznań'], titles(ListingQuery::search(['region' => 'wielkopolskie'], 1)));
        T::same(2, ListingQuery::search(['investment_form' => 'equity,bonds'], 1)['total'], 'multiselect, także lista po przecinku (link z kampanii)');

        // 1 000 000 EUR × 4,30 = 4 300 000 PLN — mieści się w 3–5 mln PLN
        T::same(['Biurowiec Kraków'], titles(ListingQuery::search(['capital_needed_od' => '3 000 000', 'capital_needed_do' => '5000000'], 1)));
        T::same(['Osiedle Słoneczne Poznań', 'Biurowiec Kraków', 'Magazyn Wrocław'], titles(ListingQuery::search(['sort' => 'capital_needed_rosnaco'], 1)), 'sort po kapitale w PLN');
        T::same(['Magazyn Wrocław', 'Osiedle Słoneczne Poznań'], titles(ListingQuery::search(['expected_roi_od' => '15', 'sort' => 'expected_roi_malejaco'], 1)));
        T::same(['Osiedle Słoneczne Poznań'], titles(ListingQuery::search(['esg' => '1'], 1)), 'toggle');
        T::same(['Magazyn Wrocław'], titles(ListingQuery::search(['kategoria' => 'joint-venture'], 1)));
        T::same(['Biurowiec Kraków'], titles(ListingQuery::search(['q' => 'kraków'], 1)), 'pełnotekstowo, bez wielkości liter');
        T::same(['Osiedle Słoneczne Poznań'], titles(ListingQuery::search(['q' => 'słon'], 1)), 'prefiks słowa');

        $r = ListingQuery::search(['region' => 'marsjanskie', 'capital_needed_od' => 'dużo', 'sort' => 'DROP TABLE', 'strona' => '999', 'nieznany' => 'x', 'investment_type' => ['<script>']], 1);
        T::same(3, $r['total'], 'śmieciowe parametry ignorowane');
        T::same(1, $r['page'], 'strona poza zakresem → ostatnia istniejąca');
    },

    'Filtry po polach dla zalogowanych niedostępne dla gościa (nie zdradzają ukrytych wartości)' => function (): void {
        auth_setup();
        seed_market();
        // capital_security ma poziom 2 w presecie
        T::assert(!isset(ListingQuery::filterFields(1)['capital_security']));
        T::assert(isset(ListingQuery::filterFields(2)['capital_security']));
        T::same(3, ListingQuery::search(['capital_security' => ['aval']], 1)['total'], 'gość: parametr L2 ignorowany');
        T::same(['Magazyn Wrocław'], titles(ListingQuery::search(['capital_security' => ['aval']], 2)), 'zalogowany: działa');

        $guest = Browser::get('/oferty', ['capital_security' => ['aval']]);
        T::same(200, $guest->status);
        T::assert(!str_contains($guest->body, 'Formy zabezpieczenia kapitału'), 'gość nie widzi filtra L2');
        T::assert(str_contains($guest->body, 'Więcej filtrów po zalogowaniu'));
        login_author('czlonek@example.pl');
        $member = Browser::get('/oferty', ['capital_security' => ['aval']]);
        T::assert(str_contains($member->body, 'Formy zabezpieczenia kapitału') && str_contains($member->body, 'Wyniki: 1'));
    },

    'Lista HTML: kafelki z polami z presetu wg poziomu, paginacja, noindex dla kombinacji filtrów' => function (): void {
        auth_setup();
        $s = seed_market();
        for ($i = 1; $i <= 11; $i++) {
            seed_listing($s['owner'], "Oferta seryjna {$i}", ['investment_type' => 'land', 'region' => 'mazowieckie', 'capital_needed' => ['amount' => (string) ($i * 100000), 'currency' => 'PLN']]);
        }
        $p1 = Browser::get('/oferty');
        T::assert(str_contains($p1->body, 'Wyniki: 14') && str_contains($p1->body, 'Strona 1 z 2'));
        T::same(12, substr_count($p1->body, '<article class="card'));
        T::assert(str_contains($p1->body, 'rel="next"') && str_contains($p1->body, 'strona=2'));
        T::assert(str_contains($p1->body, '<link rel="canonical" href="http://localhost:8080/oferty">'));
        T::assert(!str_contains($p1->body, 'noindex'));
        T::same(2, substr_count(Browser::get('/oferty', ['strona' => '2'])->body, '<article class="card'));

        $one = Browser::get('/oferty', ['region' => 'wielkopolskie'])->body;
        T::assert(str_contains($one, 'Potrzebny kapitał') && str_contains($one, "2\u{00A0}000\u{00A0}000\u{00A0}zł"), 'pole L1 na kafelku, kwota po polsku');
        T::assert(str_contains($one, 'noindex'), 'kombinacja filtrów — bez indeksowania');
        T::assert(str_contains($one, 'href="/oferta/osiedle-sloneczne-poznan-'));
        T::assert(!str_contains(Browser::get('/oferty')->body, 'Szkic niewidoczny'));

        $home = Browser::get('/');
        T::assert(str_contains($home->body, 'Przeglądaj oferty (14)') && substr_count($home->body, '<article class="card') === 6);
    },

    'Strona oferty: gość L1, zalogowany L2, dostęp L3, kanoniczny adres, szkic 404, wideo' => function (): void {
        auth_setup();
        $s = seed_market();
        $id = $s['ids']['a'];
        $slug = DB::value('SELECT slug FROM listings WHERE id = ?', [$id]);

        $guest = Browser::get('/oferta/' . $slug);
        T::same(200, $guest->status);
        T::assert(str_contains($guest->body, 'Osiedle Słoneczne Poznań') && str_contains($guest->body, 'Typ inwestycji'));
        T::assert(!str_contains($guest->body, 'Formy zabezpieczenia') && !str_contains($guest->body, 'Blisko parku') && !str_contains($guest->body, '123/4'), 'L2/L3 ukryte');
        T::assert(str_contains($guest->body, 'Pełna treść oferty jest dostępna po zalogowaniu.'), 'komunikat z presetu');
        T::assert(str_contains($guest->body, 'href="/logowanie?next=%2Foferta%2F' . $slug . '"'), 'logowanie wraca na ofertę');
        T::assert(!str_contains($guest->body, 'youtube-nocookie'), 'film L2 ukryty dla gościa');
        T::assert(str_contains($guest->body, '"@type":"BreadcrumbList"'));

        T::same('/oferta/' . $slug, Browser::get('/oferta/stary-tytul-' . $id)->headers['Location'] ?? null, '301 na adres kanoniczny');
        T::same(404, Browser::get('/oferta/' . DB::value('SELECT slug FROM listings WHERE id = ?', [$s['ids']['draft']]))->status, 'szkic niewidoczny');

        $member = login_author('inwestor@example.pl');
        $m = Browser::get('/oferta/' . $slug);
        T::assert(str_contains($m->body, 'Formy zabezpieczenia kapitału') && str_contains($m->body, 'Hipoteka na nieruchomości'), 'L2 dla zalogowanego');
        T::assert(str_contains($m->body, '<h2>Lokalizacja</h2>'), 'pełny opis');
        T::assert(!str_contains($m->body, '123/4'), 'L3 nadal ukryte');
        T::assert(str_contains($m->body, 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'), 'YouTube osadzony bez ciasteczek');
        T::assert(str_contains($m->body, 'href="https://example.com/film"'), 'inny adres jako link');
        T::assert(str_contains($m->body, 'Poproś o dostęp'));

        $grant = DB::insert('access_grants', ['listing_id' => $id, 'user_id' => $member, 'status' => 'granted']);
        \App\Access\Gate::flush();
        $g = Browser::get('/oferta/' . $slug);
        T::assert(str_contains($g->body, 'Działka nr 123/4'), 'L3 po nadaniu dostępu');
        T::assert(!str_contains($g->body, 'Poproś o dostęp'));
    },

    'Zapytanie: zgoda sprawdzana na serwerze, pułapka na boty, zapis, e-mail do admina i autora, limit' => function (): void {
        auth_setup();
        create_user('admin@example.pl', 'haslo-admina-123', 'admin', '+48600000777');
        $s = seed_market();
        $id = $s['ids']['b'];
        $path = "/oferta/{$id}/zapytanie";
        $form = ['name' => 'Jan Inwestor', 'email' => 'Jan@Example.pl', 'phone' => '601 202 303', 'message' => 'Proszę o kontakt w sprawie biurowca.', 'consent' => '1'];

        $r = Browser::post($path, array_merge($form, ['consent' => '']));
        T::same(422, $r->status);
        T::assert(str_contains($r->body, 'Zaznacz „Zgoda na przetwarzanie danych”'), 'zgoda wymagana na serwerze');
        T::assert(str_contains($r->body, 'value="Jan Inwestor"'), 'dane zachowane');

        Browser::post($path, array_merge($form, ['website' => 'http://spam']));
        T::same(0, (int) DB::value('SELECT COUNT(*) FROM inquiries'), 'bot nic nie zapisuje');

        $r = Browser::post($path, $form);
        T::assert(str_ends_with($r->headers['Location'], '#zapytanie'));
        T::assert(str_contains(Browser::get('/oferta/' . DB::value('SELECT slug FROM listings WHERE id = ?', [$id]))->body, 'Dziękujemy! Zapytanie zostało wysłane'));
        $inq = DB::fetch('SELECT i.*, c.type, c.granted FROM inquiries i JOIN consents c ON c.id = i.consent_id');
        T::same(['jan@example.pl', '+48601202303', 'inquiry', 1, $id], [$inq['email'], $inq['phone_e164'], $inq['type'], (int) $inq['granted'], (int) $inq['listing_id']]);
        T::same(1, (int) DB::value('SELECT inquiries FROM listing_stats WHERE listing_id = ?', [$id]));
        run_jobs();
        $to = array_column(array_filter(Mailer::$sent, fn($m) => $m['template'] === 'inquiry-new'), 'to');
        sort($to);
        T::same(['admin@example.pl', 'wlasciciel@example.pl'], $to);

        for ($i = 0; $i < 4; $i++) {
            Browser::post($path, $form);
        }
        T::same(429, Browser::post($path, $form)->status, 'szóste zapytanie w godzinę z jednego IP');
    },

    'Prośba o dostęp L3: wymaga logowania, bez duplikatów, decyzja z e-mailem' => function (): void {
        auth_setup();
        create_user('mod@example.pl', 'haslo-moda-12345', 'moderator', '+48600000778');
        $s = seed_market();
        $id = $s['ids']['a'];
        T::same('/logowanie?next=%2Fkonto', Browser::post("/oferta/{$id}/dostep", ['message' => 'x'])->headers['Location'] ?? null, 'gość → logowanie');

        $member = login_author('inwestor@example.pl');
        Browser::post("/oferta/{$id}/dostep", ['message' => 'Fundusz, NDA podpiszemy od ręki.']);
        Browser::post("/oferta/{$id}/dostep", ['message' => 'drugi raz']);
        T::same(1, (int) DB::value('SELECT COUNT(*) FROM access_grants'));
        T::same('Fundusz, NDA podpiszemy od ręki.', DB::value('SELECT message FROM access_grants'));
        $slug = DB::value('SELECT slug FROM listings WHERE id = ?', [$id]);
        T::assert(str_contains(Browser::get('/oferta/' . $slug)->body, 'Prośba o dostęp została wysłana'));
        run_jobs();
        T::same(['mod@example.pl'], array_column(array_filter(Mailer::$sent, fn($m) => $m['template'] === 'access-request'), 'to'));

        $pending = AccessService::pending();
        T::same(1, count($pending));
        AccessService::decide((int) $pending[0]['id'], true, null, gmdate('Y-m-d H:i:s', time() + 90 * 86400));
        run_jobs();
        T::same(1, count(array_filter(Mailer::$sent, fn($m) => $m['template'] === 'access-granted' && $m['to'] === 'inwestor@example.pl')));
        T::assert(str_contains(Browser::get('/oferta/' . $slug)->body, '123/4'));
        T::same('granted', AccessService::statusFor($id, $member));
    },

    'Statystyki: gość, zalogowany (kto oglądał), bez botów i autora' => function (): void {
        auth_setup();
        $s = seed_market();
        $id = $s['ids']['c'];
        $slug = DB::value('SELECT slug FROM listings WHERE id = ?', [$id]);
        $ua = ['HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone)'];
        // Browser nie przekazuje User-Agent — tu potrzebny, więc wysyłamy żądania bezpośrednio.
        $router = new App\Core\Router();
        require APP_ROOT . '/app/routes.php';
        $hit = function (array $server) use ($router, $slug) {
            return $router->dispatch(new App\Core\Request('GET', '/oferta/' . $slug, [], [], $server + ['REMOTE_ADDR' => '203.0.113.20'], Browser::$cookies));
        };
        $hit($ua);
        $hit($ua);
        $hit(['HTTP_USER_AGENT' => 'Googlebot/2.1 (+http://www.google.com/bot.html)']);
        $hit([]);
        T::same([2, 0], array_map('intval', array_values(DB::fetch('SELECT views_public, views_member FROM listing_stats WHERE listing_id = ?', [$id]))), 'boty i brak UA nie liczone');

        $member = login_author('inwestor@example.pl');
        $hit($ua);
        $hit($ua);
        T::same(2, (int) DB::value('SELECT views_member FROM listing_stats WHERE listing_id = ?', [$id]));
        T::same(2, (int) DB::value('SELECT views FROM listing_member_views WHERE listing_id = ? AND user_id = ?', [$id, $member]));

        Browser::post('/wyloguj');
        Browser::post('/logowanie', ['email' => 'wlasciciel@example.pl', 'password' => 'haslo-wlasciciela-1']);
        $hit($ua);
        T::same(2, (int) DB::value('SELECT views_member FROM listing_stats WHERE listing_id = ?', [$id]), 'autor nie nabija statystyk');
    },

    'SEO: sitemap tylko z treścią publiczną, robots.txt, og:image okładki' => function (): void {
        auth_setup();
        $s = seed_market();
        $xml = Browser::get('/sitemap.xml');
        T::same('application/xml; charset=utf-8', $xml->headers['Content-Type']);
        T::assert(str_contains($xml->body, '/oferta/osiedle-sloneczne-poznan-') && !str_contains($xml->body, 'szkic-niewidoczny'));
        T::assert(str_contains($xml->body, '/oferty?kategoria=joint-venture') && str_contains($xml->body, '/zasady-wspolpracy'));
        $robots = Browser::get('/robots.txt')->body;
        T::assert(str_contains($robots, 'Disallow: /konto') && str_contains($robots, 'Sitemap: http://localhost:8080/sitemap.xml'));

        $mid = DB::insert('media', ['listing_id' => $s['ids']['a'], 'path_base' => 'img/xx/yy/test', 'access_level' => 1, 'is_cover' => 1, 'width' => 1600, 'height' => 1000]);
        $page = Browser::get('/oferta/' . DB::value('SELECT slug FROM listings WHERE id = ?', [$s['ids']['a']]))->body;
        T::assert(str_contains($page, '<meta property="og:image" content="http://localhost:8080/media/' . $mid . '/1600">'));
        T::assert(str_contains($page, '<link rel="canonical"'));
    },
];
