<?php

declare(strict_types=1);

/**
 * Etap 7: asystent AI — API Claude jest symulowane (Http::fake), nic nie kosztuje.
 * Sprawdzamy: wyłączenie domyślne, schemat z presetu, oferta z PDF, oznaczenia „do sprawdzenia”,
 * redakcja opisu z anonimizacją, podpowiedzi dla moderatora, odmowa modelu, koszty i budżet.
 */

use App\Ai\Assistant;
use App\Core\Config;
use App\Core\DB;
use App\Core\Http;
use App\Core\Settings;
use App\Integrations\Claude;
use App\Listings\Fields;
use App\Listings\ValueStore;

final class FakeClaude
{
    /** @var array<int,array> odpowiedzi do zwrócenia po kolei (body API) */
    public static array $replies = [];
    public static array $requests = [];

    public static function install(): void
    {
        self::$replies = [];
        self::$requests = [];
        $prop = new \ReflectionProperty(Config::class, 'items');
        Config::set(array_replace_recursive($prop->getValue(), ['ai' => ['anthropic_api_key' => 'sk-test', 'model' => 'claude-sonnet-5-5']]));
        Http::fake(static function (string $method, string $url, array $headers, ?string $body): array {
            if (!str_contains($url, 'api.anthropic.com')) {
                return ['status' => 404, 'body' => '{}'];
            }
            self::$requests[] = ['headers' => $headers, 'body' => json_decode((string) $body, true)];
            $reply = array_shift(self::$replies) ?? self::text(['x' => 1]);
            return ['status' => (int) ($reply['_status'] ?? 200), 'body' => json_encode($reply)];
        });
    }

    public static function text(array $data, int $in = 12000, int $out = 900, array $extra = []): array
    {
        return $extra + ['model' => 'claude-sonnet-5-5', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE)]],
            'usage' => ['input_tokens' => $in, 'output_tokens' => $out]];
    }

    public static function uninstall(): void
    {
        $prop = new \ReflectionProperty(Config::class, 'items');
        $items = $prop->getValue();
        $items['ai']['anthropic_api_key'] = '';
        Config::set($items);
    }
}

function ai_enable(array $extra = []): void
{
    Settings::set('ai', $extra + ['enabled' => true, 'extract_from_pdf' => true, 'rewrite_description' => true, 'moderation_hints' => true,
        'anonymize_level1' => ['developer_name', 'address_exact'], 'monthly_budget_usd' => 30]);
}

return [
    'AI: domyślnie wyłączone — brak przycisków, żądania odrzucane, bez klucza niewidoczne' => function (): void {
        auth_setup();
        FakeClaude::uninstall();
        login_author();
        $id = new_listing();
        T::assert(!str_contains(Browser::get("/moje-oferty/{$id}/edycja/2")->body, 'ai/z-pdf'), 'preset ma enabled=false');
        Browser::upload("/moje-oferty/{$id}/ai/z-pdf", ['pdf' => [fake_pdf()]]);
        T::same(0, (int) DB::value('SELECT COUNT(*) FROM ai_tasks'));
        ai_enable();
        T::assert(!Claude::available(), 'włączone w ustawieniach, ale bez klucza API');
        T::assert(!str_contains(Browser::get("/moje-oferty/{$id}/edycja/2")->body, 'ai/z-pdf'));
    },

    'AI: schemat odpowiedzi z kryteriów presetu — tylko istniejące klucze i opcje' => function (): void {
        auth_setup();
        $cat = (int) DB::value("SELECT id FROM categories WHERE slug = 'pozyskanie-kapitalu'");
        $fields = Fields::forCategory($cat);
        $schema = Assistant::fieldsSchema($fields);
        T::same(array_keys($fields), $schema['required']);
        T::same(false, $schema['additionalProperties']);
        $it = $schema['properties']['investment_type']['anyOf'][0];
        T::same(array_keys($fields['investment_type']['options']), $it['enum']);
        T::same(['type' => 'null'], $schema['properties']['investment_type']['anyOf'][1], 'każde pole może być puste');
        T::same('array', $schema['properties']['investment_form']['anyOf'][0]['type']);
        T::same(['amount', 'currency'], $schema['properties']['capital_needed']['anyOf'][0]['required']);
        T::same('boolean', $schema['properties']['esg']['anyOf'][0]['type']);
        T::same('date', $schema['properties']['start_date']['anyOf'][0]['format']);
    },

    'AI: oferta z PDF — żądanie do API, wypełnienie tylko pustych pól przez walidację, znaczniki „do sprawdzenia”, koszt' => function (): void {
        auth_setup();
        FakeClaude::install();
        ai_enable();
        $uid = login_author();
        $id = new_listing('Hala w Łodzi');
        Browser::post("/moje-oferty/{$id}/edycja/2", ['f' => ['city' => 'Łódź'], 'go' => 'stay']);
        T::assert(str_contains(Browser::get("/moje-oferty/{$id}/edycja/2")->body, 'Wypełnij z PDF'));

        $r = Browser::upload("/moje-oferty/{$id}/ai/z-pdf", ['pdf' => [fake_pdf('teaser-hala.pdf')]]);
        T::same("/moje-oferty/{$id}/edycja/2#asystent", $r->headers['Location']);
        $task = DB::fetch('SELECT * FROM ai_tasks WHERE listing_id = ?', [$id]);
        T::same(['extract', 'queued'], [$task['kind'], $task['status']]);
        T::same(3, (int) DB::value('SELECT access_level FROM attachments WHERE id = ?', [$task['attachment_id']]), 'źródło zostaje jako dokument poufny');
        T::assert(str_contains(Browser::get("/moje-oferty/{$id}/edycja/2")->body, 'data-ai-poll'), 'w trakcie — strona odpytuje stan');

        FakeClaude::$replies[] = FakeClaude::text([
            'title' => 'Hala magazynowa 5000 m²', 'short_desc' => 'Hala klasy A pod Łodzią.', 'full_desc_md' => "## Projekt\n\nHala magazynowa z biurami.",
            'fields' => ['investment_type' => 'warehouse', 'region' => 'lodzkie', 'city' => 'Zgierz', 'capital_needed' => ['amount' => 1250000.5, 'currency' => 'EUR'],
                'expected_roi' => 18.5, 'units_count' => 36, 'esg' => true, 'start_date' => '2024-04-01', 'investment_form' => ['equity', 'nieistnieje'],
                'project_stage' => null, 'nie_ma_takiego_pola' => 'x'],
            'uncertain' => ['expected_roi'], 'notes' => 'Brak informacji o zabezpieczeniach.',
        ], 20000, 1500);
        run_jobs();

        $req = FakeClaude::$requests[0];
        T::same(['sk-test', '2026-10-21', 'server-side-fallback-2026-07-01'], [$req['headers']['x-api-key'], $req['headers']['anthropic-version'], $req['headers']['anthropic-beta']]);
        T::same('claude-sonnet-5-5', $req['body']['model']);
        T::same('default', $req['body']['fallbacks']);
        T::same('json_schema', $req['body']['output_config']['format']['type']);
        T::same('document', $req['body']['messages'][0]['content'][0]['type']);
        T::same('application/pdf', $req['body']['messages'][0]['content'][0]['source']['media_type']);
        T::assert(str_starts_with(base64_decode($req['body']['messages'][0]['content'][0]['source']['data']), '%PDF'), 'treść PDF w base64');
        T::assert(!isset($req['body']['thinking']) && !isset($req['body']['temperature']), 'bez parametrów odrzucanych przez model');

        $v = ValueStore::load($id);
        T::same('Łódź', $v['city'], 'pole wpisane przez autora nie jest nadpisywane');
        T::same(['warehouse', 'lodzkie', 36, true, '2024-04-01'], [$v['investment_type'], $v['region'], (int) $v['units_count'], (bool) $v['esg'], $v['start_date']]);
        T::same(['1250000.50', 'EUR'], [$v['capital_needed']['amount'], $v['capital_needed']['currency']]);
        T::same(18.5, (float) $v['expected_roi']);
        T::assert(!isset($v['investment_form']), 'nieznana opcja odrzucona przez walidację formularza');
        $l = DB::fetch('SELECT * FROM listings WHERE id = ?', [$id]);
        T::same('Hala w Łodzi', $l['title'], 'tytuł autora zostaje');
        T::same("## Projekt\n\nHala magazynowa z biurami.", $l['full_desc_md'], 'pusty opis uzupełniony');
        T::same(1, (int) $l['ai_extracted']);
        $review = json_decode((string) $l['ai_review'], true);
        T::assert(in_array('investment_type', $review, true) && in_array('full_desc_md', $review, true) && !in_array('city', $review, true));
        T::same('applied', DB::value('SELECT status FROM ai_tasks WHERE id = ?', [$task['id']]));

        $usage = DB::fetch('SELECT * FROM ai_usage');
        T::same(['extract', 'ok', 20000, 1500, $id, $uid], [$usage['feature'], $usage['status'], (int) $usage['input_tokens'], (int) $usage['output_tokens'], (int) $usage['listing_id'], (int) $usage['user_id']]);
        T::same(0.055, (float) $usage['cost_usd'], '20k × $2/M + 1,5k × $10/M');

        $page = Browser::get("/moje-oferty/{$id}/edycja/2")->body;
        T::assert(str_contains($page, 'AI — sprawdź') && str_contains($page, 'field--ai') && str_contains($page, 'Brak informacji o zabezpieczeniach.'));
        T::same(['status' => 'applied', 'done' => true], json_decode(Browser::get("/moje-oferty/{$id}/ai/{$task['id']}")->body, true));

        // Autor zapisuje krok 2 — znaczniki pól znikają, opis czeka na krok 3
        Browser::post("/moje-oferty/{$id}/edycja/2", ['f' => ['city' => 'Łódź', 'investment_type' => 'warehouse', 'region' => 'lodzkie'], 'go' => 'stay']);
        T::same(['full_desc_md'], json_decode((string) DB::value('SELECT ai_review FROM listings WHERE id = ?', [$id]), true));
        Browser::post("/moje-oferty/{$id}/edycja/3", ['full_desc_md' => 'Sprawdzony opis.', 'confidential_md' => '']);
        T::same(null, DB::value('SELECT ai_review FROM listings WHERE id = ?', [$id]));

        Browser::fresh();
        login_author('obcy@example.pl');
        T::same(404, Browser::get("/moje-oferty/{$id}/ai/{$task['id']}")->status, 'stan zlecenia tylko dla autora i zespołu');
    },

    'AI: odmowa modelu, błąd API, przekroczony budżet — zlecenie nieudane z czytelnym komunikatem' => function (): void {
        auth_setup();
        FakeClaude::install();
        ai_enable();
        login_author();
        $id = new_listing();
        FakeClaude::$replies[] = ['model' => 'claude-sonnet-5-5', 'stop_reason' => 'refusal', 'stop_details' => ['category' => 'cyber'], 'content' => [], 'usage' => ['input_tokens' => 100, 'output_tokens' => 0]];
        Browser::upload("/moje-oferty/{$id}/ai/z-pdf", ['pdf' => [fake_pdf()]]);
        run_jobs();
        $t = Assistant::latest($id, 'extract');
        T::same(['failed', __('ai.err_refused')], [$t['status'], $t['error']]);
        T::same('refused', DB::value('SELECT status FROM ai_usage ORDER BY id DESC LIMIT 1'));
        T::assert(str_contains(Browser::get("/moje-oferty/{$id}/edycja/2")->body, 'Asystent odmówił'));

        FakeClaude::$replies[] = ['_status' => 529, 'type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']];
        $att = (int) DB::value('SELECT id FROM attachments WHERE listing_id = ? LIMIT 1', [$id]);
        Browser::post("/moje-oferty/{$id}/ai/z-pdf", ['attachment_id' => (string) $att]);
        run_jobs();
        T::same(__('ai.err_busy'), Assistant::latest($id, 'extract')['error'], 'ponowne użycie dokumentu z oferty');

        DB::insert('ai_usage', ['feature' => 'extract', 'model' => 'claude-sonnet-5-5', 'cost_usd' => 30, 'status' => 'ok']);
        T::assert(Claude::budgetExceeded() && !Assistant::enabled('extract'));
        T::assert(!str_contains(Browser::get("/moje-oferty/{$id}/edycja/2")->body, 'ai/z-pdf'), 'po przekroczeniu budżetu funkcja znika');
        $before = (int) DB::value('SELECT COUNT(*) FROM ai_tasks');
        Browser::post("/moje-oferty/{$id}/ai/z-pdf", ['attachment_id' => (string) $att]);
        T::same($before, (int) DB::value('SELECT COUNT(*) FROM ai_tasks'));
        ai_enable(['monthly_budget_usd' => 0]);
        T::assert(Assistant::enabled('extract'), '0 = bez limitu');
    },

    'AI: koszt przy przejściu na model zapasowy liczony z każdej próby' => function (): void {
        auth_setup();
        FakeClaude::install();
        ai_enable();
        $uid = create_user('a@example.pl', 'haslo-testowe-123');
        $id = seed_listing($uid, 'Oferta', ['investment_type' => 'office']);
        FakeClaude::$replies[] = FakeClaude::text(['completeness' => 140, 'summary' => 'OK', 'issues' => []], 0, 0, [
            'model' => 'claude-opus-4-8',
            'usage' => ['input_tokens' => 3000, 'output_tokens' => 400, 'iterations' => [
                ['type' => 'message', 'model' => 'claude-sonnet-5-5', 'input_tokens' => 3000, 'output_tokens' => 0],
                ['type' => 'fallback_message', 'model' => 'claude-opus-4-8', 'input_tokens' => 3000, 'output_tokens' => 400],
            ]],
        ]);
        Assistant::request('hints', $id, null);
        run_jobs();
        $u = DB::fetch('SELECT model, input_tokens, output_tokens, cost_usd FROM ai_usage');
        T::same(['claude-opus-4-8', 6000, 400], [$u['model'], (int) $u['input_tokens'], (int) $u['output_tokens']]);
        T::same(round(3000 * 2 / 1e6 + 3000 * 5 / 1e6 + 400 * 25 / 1e6, 5), (float) $u['cost_usd']);
        T::same(100, Assistant::latest($id, 'hints')['result']['completeness'], 'wynik przycięty do 0–100');
    },

    'AI: redakcja opisu — limit znaków, dane ukrywane publicznie usunięte, autor decyduje, co wstawić' => function (): void {
        auth_setup();
        FakeClaude::install();
        ai_enable();
        Settings::set('limits', array_replace((array) Settings::get('limits', []), ['short_desc_max' => 80]));
        login_author();
        $id = new_listing('Osiedle Zielone');
        Browser::post("/moje-oferty/{$id}/edycja/2", ['f' => ['developer_name' => 'Budimex Tajny', 'city' => 'Poznań'], 'go' => 'stay']);
        T::same(302, Browser::post("/moje-oferty/{$id}/ai/opis", ['notes' => 'krótko'])->status);
        T::same(0, (int) DB::value('SELECT COUNT(*) FROM ai_tasks'), 'za krótkie notatki');

        Browser::post("/moje-oferty/{$id}/ai/opis", ['notes' => 'Osiedle 36 mieszkań, Poznań Jeżyce, deweloper Budimex Tajny, PnB prawomocne, start 2025.']);
        FakeClaude::$replies[] = FakeClaude::text([
            'short_desc' => 'Osiedle 36 mieszkań w Poznaniu od dewelopera Budimex Tajny z prawomocnym pozwoleniem na budowę — start 2025.',
            'full_desc_md' => "## Projekt\n\nDeweloper budimex tajny realizuje osiedle 36 mieszkań.",
        ]);
        run_jobs();
        $prompt = FakeClaude::$requests[0]['body']['messages'][0]['content'][0]['text'];
        T::assert(str_contains($prompt, 'Miejscowość: Poznań') && !str_contains($prompt, 'Nazwa dewelopera'), 'pole ukrywane nie jest podawane jako parametr');
        $t = Assistant::latest($id, 'rewrite');
        T::assert(!str_contains(mb_strtolower($t['result']['short_desc'] . $t['result']['full_desc_md']), 'budimex'), 'nazwa usunięta, także inną wielkością liter');
        T::assert(mb_strlen($t['result']['short_desc']) <= 80, 'limit znaków');
        T::assert(str_contains(Browser::get("/moje-oferty/{$id}/edycja/3")->body, 'Propozycja asystenta'));

        Browser::post("/moje-oferty/{$id}/ai/{$t['id']}/zastosuj", ['part' => 'full']);
        $l = DB::fetch('SELECT short_desc, full_desc_md FROM listings WHERE id = ?', [$id]);
        T::assert(str_starts_with((string) $l['full_desc_md'], '## Projekt'));
        T::assert(!str_contains((string) $l['short_desc'], 'Budimex'), 'krótki opis bez zmian, gdy wybrano tylko pełny');
        T::same('applied', Assistant::task((int) $t['id'])['status']);
    },

    'AI: podpowiedzi dla moderatora zlecane przy wysłaniu oferty i widoczne w moderacji' => function (): void {
        auth_setup();
        FakeClaude::install();
        ai_enable();
        login_author();
        $id = new_listing();
        Browser::post("/moje-oferty/{$id}/edycja/2", complete_fields());
        Browser::post("/moje-oferty/{$id}/wyslij");
        T::same('pending', DB::value('SELECT status FROM listings WHERE id = ?', [$id]));
        T::same('queued', Assistant::latest($id, 'hints')['status']);
        FakeClaude::$replies[] = FakeClaude::text(['completeness' => 72, 'summary' => 'Dane w większości kompletne.', 'issues' => [
            ['type' => 'inconsistency', 'message' => 'ROI 18,5% przy okresie 2–5 lat wymaga uzasadnienia.'],
            ['type' => 'risk', 'message' => 'Brak opisu zabezpieczenia kapitału w treści.'],
        ]]);
        run_jobs();
        T::assert(str_contains(FakeClaude::$requests[0]['body']['system'], 'moderatorowi'));

        login_staff('moderator', 'mod@example.pl');
        $page = Browser::get("/admin/moderacja/{$id}")->body;
        T::assert(str_contains($page, 'Kompletność: 72/100') && str_contains($page, 'ROI 18,5%') && str_contains($page, 'ai-issue--risk'));
        T::same('pending', DB::value('SELECT status FROM listings WHERE id = ?', [$id]), 'AI niczego nie zatwierdza');
        Browser::post("/admin/moderacja/{$id}/ai");
        T::same('queued', Assistant::latest($id, 'hints')['status'], 'ponowne zlecenie z panelu');
        T::same(403, Browser::get('/admin/ustawienia')->status, 'ustawienia AI tylko dla admina');
        login_staff('admin', 'admin@example.pl');
        T::assert(str_contains(Browser::get('/admin/ustawienia')->body, 'Asystent AI'));
        Browser::post('/admin/ustawienia', ['portal' => ['name' => 'Portal', 'accent' => '#2457d6', 'mode' => 'light'], 'ai' => ['enabled' => '1', 'monthly_budget_usd' => '12,5']]);
        Settings::flush();
        T::same([true, false, 12.5], [Settings::get('ai.enabled'), Settings::get('ai.extract_from_pdf'), (float) Settings::get('ai.monthly_budget_usd')]);
        FakeClaude::uninstall();
    },
];
