<?php

declare(strict_types=1);

/**
 * Ścieżki kont na prawdziwej bazie, z podmienioną siecią (Http::fake):
 * rejestracja (WhatsApp → SMS zapasowy), webhook, logowanie, „Zapamiętaj mnie”,
 * reset hasła, potwierdzenie e-maila, 2FA. Każdy test = „nowa przeglądarka”.
 */

use App\Auth\Auth;
use App\Auth\OtpService;
use App\Auth\Totp;
use App\Core\Cookies;
use App\Core\Csrf;
use App\Core\DB;
use App\Core\Http;
use App\Core\Mailer;
use App\Core\Queue;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Presets\Importer;

/** Stan „sieci”: odpowiedzi API i licznik wywołań. */
final class FakeNet
{
    public static int $waStatus = 200;
    public static array $mf = [];

    public static function clearCache(): void
    {
        foreach (glob(APP_ROOT . '/storage/cache/nip-*.json') ?: [] as $f) {
            unlink($f);
        }
    }

    public static function lastRequest(string $host): ?array
    {
        foreach (array_reverse(Http::$sent) as $r) {
            if (str_contains($r['url'], $host)) {
                return $r;
            }
        }
        return null;
    }

    public static function install(): void
    {
        self::$waStatus = 200;
        self::$mf = ['name' => 'PRZYKŁADOWA SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ', 'nip' => '5260250274', 'statusVat' => 'Czynny',
            'regon' => '012345678', 'krs' => '0000012345', 'workingAddress' => 'UL. PRZYKŁADOWA 1, 61-001 POZNAŃ'];
        foreach (array_merge(glob(APP_ROOT . '/storage/cache/nip-*.json') ?: [], glob(APP_ROOT . '/storage/cache/nbp-*.json') ?: []) as $f) {
            unlink($f);
        }
        Http::fake(static function (string $method, string $url, array $headers, ?string $body): array {
            if (str_contains($url, 'graph.facebook.com')) {
                return self::$waStatus === 200
                    ? ['status' => 200, 'body' => json_encode(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.' . bin2hex(random_bytes(6))]]])]
                    : ['status' => self::$waStatus, 'body' => json_encode(['error' => ['message' => 'Recipient phone number not in allowed list', 'code' => 131030]])];
            }
            if (str_contains($url, 'api.smsapi.pl')) {
                return ['status' => 200, 'body' => json_encode(['count' => 1, 'list' => [['id' => 'sms-' . bin2hex(random_bytes(4)), 'points' => 0.16]]])];
            }
            if (str_contains($url, 'api.nbp.pl')) {
                $rates = ['eur' => 4.30, 'usd' => 3.95, 'gbp' => 5.10];
                preg_match('#/rates/a/([a-z]{3})/#', $url, $m);
                return isset($rates[$m[1] ?? '']) ? ['status' => 200, 'body' => json_encode(['rates' => [['mid' => $rates[$m[1]], 'effectiveDate' => '2026-11-05']]])] : ['status' => 404, 'body' => '{}'];
            }
            if (str_contains($url, 'wl-api.mf.gov.pl')) {
                return ['status' => 200, 'body' => json_encode(['result' => ['subject' => self::$mf ?: null]])];
            }
            return ['status' => 404, 'body' => '{}'];
        });
    }

    /** Kod z ostatniej wiadomości WhatsApp albo SMS. */
    public static function lastCode(string $channel): ?string
    {
        foreach (array_reverse(Http::$sent) as $r) {
            if ($channel === 'whatsapp' && str_contains($r['url'], 'graph.facebook.com')) {
                return json_decode((string) $r['body'], true)['template']['components'][0]['parameters'][0]['text'] ?? null;
            }
            if ($channel === 'sms' && str_contains($r['url'], 'api.smsapi.pl')) {
                parse_str((string) $r['body'], $q);
                return preg_match('/(\d{6})/', (string) $q['message'], $m) ? $m[1] : null;
            }
        }
        return null;
    }

    public static function count(string $host): int
    {
        return count(array_filter(Http::$sent, fn($r) => str_contains($r['url'], $host)));
    }
}

/** Przeglądarka testowa: sesja w $_SESSION, ciasteczka „remember”, automatyczny token CSRF. */
final class Browser
{
    public static array $cookies = [];
    private static ?Router $router = null;

    public static function fresh(): void
    {
        $_SESSION = [];
        self::$cookies = [];
        Cookies::reset();
        Auth::forgetCache();
    }

    public static function get(string $path, array $query = []): Response
    {
        return self::send(new Request('GET', $path, $query, [], ['REMOTE_ADDR' => '203.0.113.10'], self::$cookies));
    }

    public static function post(string $path, array $data = [], array $server = [], ?string $raw = null): Response
    {
        $data += ['_csrf' => Csrf::token()];
        return self::send(new Request('POST', $path, [], $data, $server + ['REMOTE_ADDR' => '203.0.113.10'], self::$cookies, [], $raw));
    }

    /** Wysyłka plików: $files = ['images' => ['/sciezka/a.jpg', …]] (format jak $_FILES dla name[]). */
    public static function upload(string $path, array $files, array $data = [], bool $json = false): Response
    {
        $f = [];
        foreach ($files as $field => $paths) {
            foreach ($paths as $p) {
                $one = \App\Core\UploadedFile::fake($p);
                foreach ($one as $k => $v) {
                    $f[$field][$k][] = $v;
                }
            }
        }
        $data += ['_csrf' => Csrf::token()];
        $server = ['REMOTE_ADDR' => '203.0.113.10'] + ($json ? ['HTTP_ACCEPT' => 'application/json'] : []);
        return self::send(new Request('POST', $path, [], $data, $server, self::$cookies, $f));
    }

    private static function send(Request $r): Response
    {
        if (self::$router === null) {
            $router = new Router();
            require APP_ROOT . '/app/routes.php';
            self::$router = $router;
        }
        Cookies::reset();
        $res = self::$router->dispatch($r);
        foreach (Cookies::queued() as $name => $c) {
            if ($c['options']['expires'] < time()) {
                unset(self::$cookies[$name]);
            } else {
                self::$cookies[$name] = $c['value'];
            }
        }
        return $res;
    }
}

function auth_setup(string $preset = 'inwestycje-deweloperskie'): void
{
    test_reset_db();
    (new Importer())->import(preset_file($preset));
    FakeNet::install();
    Mailer::$sent = [];
    Browser::fresh();
}

/** Wykonuje zadania z kolejki tak jak worker. */
function run_jobs(): int
{
    $handlers = require APP_ROOT . '/app/jobs.php';
    $n = 0;
    while (($job = Queue::reserve()) !== null) {
        $handlers[$job['type']]($job['payload']);
        Queue::complete($job['id']);
        $n++;
    }
    return $n;
}

function registration_form(array $overrides = []): array
{
    return $overrides + [
        'account_type' => 'investor', 'first_name' => 'Anna', 'last_name' => 'Nowak',
        'email' => 'Anna.Nowak@Example.pl', 'phone' => '600 100 200', 'nip' => '526-025-02-74', 'company_name' => '',
        'password' => 'dlugie-haslo-123', 'password_confirmation' => 'dlugie-haslo-123', 'terms' => '1', 'marketing' => '1',
    ];
}

function create_user(string $email, string $password, string $role = 'member', string $phone = '+48600999888'): int
{
    return DB::insert('users', [
        'email' => $email, 'password_hash' => Auth::hashPassword($password), 'first_name' => 'Jan', 'last_name' => 'Test',
        'phone_e164' => $phone, 'role' => $role, 'email_verified_at' => gmdate('Y-m-d H:i:s'), 'phone_verified_at' => gmdate('Y-m-d H:i:s'),
    ]);
}

function last_mail_url(string $template): ?string
{
    foreach (array_reverse(Mailer::$sent) as $m) {
        if ($m['template'] === $template) {
            return $m['data']['url'] ?? null;
        }
    }
    return null;
}

function path_of(string $url): string
{
    return (string) parse_url($url, PHP_URL_PATH);
}

return [
    'Rejestracja: walidacja, kod WhatsApp, firma z MF, konto, zgody, e-mail' => function (): void {
        auth_setup();

        $r = Browser::post('/rejestracja', registration_form(['nip' => '5260250275', 'terms' => '', 'password_confirmation' => 'inne', 'account_type' => '']));
        T::same(422, $r->status);
        foreach (['NIP jest niepoprawny', 'Akceptuję regulamin', 'Hasła nie są takie same', 'Kim jesteś?'] as $txt) {
            T::assert(str_contains($r->body, $txt), "błąd: {$txt}");
        }
        T::assert(str_contains($r->body, 'value="Anna"'), 'dane zachowane po błędzie');
        T::assert(!str_contains($r->body, 'dlugie-haslo'), 'hasło nie wraca do formularza');
        T::same(0, FakeNet::count('graph.facebook.com'), 'bez kodu przy błędach');

        $r = Browser::post('/rejestracja', registration_form());
        T::same(302, $r->status, $r->body);
        T::same('/rejestracja/weryfikacja', $r->headers['Location']);
        T::same(0, (int) DB::value('SELECT COUNT(*) FROM users'), 'konto powstaje dopiero po kodzie');
        $wa = json_decode((string) FakeNet::lastRequest('graph.facebook.com')['body'], true);
        T::same('48600100200', $wa['to']);
        T::same('kod_weryfikacyjny', $wa['template']['name']);
        T::same('pl', $wa['template']['language']['code']);
        T::same('url', $wa['template']['components'][1]['sub_type'], 'przycisk „Kopiuj kod”');
        $code = FakeNet::lastCode('whatsapp');
        T::assert((bool) preg_match('/^\d{6}$/', (string) $code));

        $page = Browser::get('/rejestracja/weryfikacja');
        T::assert(str_contains($page->body, 'Wysłaliśmy kod na WhatsApp na numer +48 600 *** 200'));
        T::assert(str_contains($page->body, 'Nie mam WhatsAppa'), 'przycisk SMS');

        $wrong = $code === '000000' ? '111111' : '000000';
        T::same(422, Browser::post('/rejestracja/weryfikacja', ['code' => $wrong])->status);
        $r = Browser::post('/rejestracja/weryfikacja', ['code' => substr($code, 0, 3) . ' ' . substr($code, 3)]);
        T::same('/konto', $r->headers['Location'] ?? null, $r->body);

        $u = DB::fetch('SELECT u.*, c.name company, c.city, c.source FROM users u JOIN companies c ON c.id = u.company_id');
        T::same('anna.nowak@example.pl', $u['email']);
        T::same('+48600100200', $u['phone_e164']);
        T::same('investor', $u['account_type']);
        T::assert($u['phone_verified_at'] !== null && $u['email_verified_at'] === null);
        T::same('PRZYKŁADOWA SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ', $u['company']);
        T::same('Poznań', $u['city']);
        T::same('mf_api', $u['source']);
        T::assert(str_starts_with((string) $u['password_hash'], '$argon2id$') || str_starts_with((string) $u['password_hash'], '$2y$'));
        T::same(['marketing' => 1, 'terms' => 1], array_column(DB::fetchAll('SELECT type, granted FROM consents ORDER BY type'), 'granted', 'type'));
        T::same((int) $u['id'], Session::get('user_id'), 'zalogowany po rejestracji');

        run_jobs();
        $url = last_mail_url('verify-email');
        T::assert($url !== null && str_starts_with($url, 'http://localhost:8080/potwierdz-email/'));
        $konto = Browser::get('/konto');
        T::assert(str_contains($konto->body, 'Potwierdź adres e-mail'), 'baner potwierdzenia');
        Browser::get(path_of((string) $url));
        T::assert(DB::value('SELECT email_verified_at FROM users') !== null, 'e-mail potwierdzony linkiem');
        T::same(302, Browser::get(path_of((string) $url))->status);
        T::assert(DB::value("SELECT used_at FROM user_tokens WHERE type = 'email_verify'") !== null, 'link jednorazowy');
        T::same(302, Browser::get('/rejestracja')->status, 'zalogowany nie widzi rejestracji');
    },

    'Rejestracja: zajęty e-mail/telefon, NIP spoza rejestru wymaga nazwy firmy' => function (): void {
        auth_setup();
        create_user('anna.nowak@example.pl', 'haslo-istniejace-1', 'member', '+48600100200');
        $r = Browser::post('/rejestracja', registration_form());
        T::assert(str_contains($r->body, 'Konto z tym adresem już istnieje') && str_contains($r->body, 'Ten numer jest już przypisany'));

        FakeNet::$mf = [];
        FakeNet::clearCache();
        $r = Browser::post('/rejestracja', registration_form(['email' => 'inna@example.pl', 'phone' => '601 200 300']));
        T::same(422, $r->status);
        T::assert(str_contains($r->body, 'Nie znaleźliśmy firmy w rejestrze — wpisz jej nazwę.'));
        $r = Browser::post('/rejestracja', registration_form(['email' => 'inna@example.pl', 'phone' => '601 200 300', 'company_name' => 'Firma Ręczna']));
        T::same(302, $r->status);
        Browser::post('/rejestracja/weryfikacja', ['code' => FakeNet::lastCode('whatsapp')]);
        T::same(['Firma Ręczna', 'manual'], array_values(DB::fetch("SELECT name, source FROM companies WHERE nip = '5260250274'")));
    },

    'WhatsApp odrzuca od razu → ten sam formularz idzie SMS-em' => function (): void {
        auth_setup();
        FakeNet::$waStatus = 400;
        Browser::post('/rejestracja', registration_form());
        T::same(1, FakeNet::count('graph.facebook.com'));
        T::same(1, FakeNet::count('api.smsapi.pl'), 'zapasowo SMS');
        T::same('sms', DB::value('SELECT channel FROM otp_codes'));
        parse_str((string) FakeNet::lastRequest('api.smsapi.pl')['body'], $sms);
        T::same('48600100200', $sms['to']);
        T::same('1', $sms['normalize'], 'bez polskich znaków — tańszy SMS');
        T::same('Portal', $sms['from']);
        T::assert(str_contains(Browser::get('/rejestracja/weryfikacja')->body, 'Wysłaliśmy kod SMS-em'));
        T::assert(!str_contains(Browser::get('/rejestracja/weryfikacja')->body, 'Nie mam WhatsAppa'), 'bez przycisku SMS przy SMS');
        Browser::post('/rejestracja/weryfikacja', ['code' => FakeNet::lastCode('sms')]);
        T::same(1, (int) DB::value('SELECT COUNT(*) FROM users'));
    },

    'Webhook: podpis, weryfikacja adresu, niedoręczenie → SMS z TYM SAMYM kodem' => function (): void {
        auth_setup();
        Browser::post('/rejestracja', registration_form());
        $waCode = FakeNet::lastCode('whatsapp');
        $wamid = (string) DB::value('SELECT provider_id FROM otp_codes');

        T::same('abc123', Browser::get('/webhook/whatsapp', ['hub_mode' => 'subscribe', 'hub_verify_token' => 'test-verify', 'hub_challenge' => 'abc123'])->body);
        T::same(403, Browser::get('/webhook/whatsapp', ['hub_mode' => 'subscribe', 'hub_verify_token' => 'zly', 'hub_challenge' => 'x'])->status);

        $payload = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
            'statuses' => [['id' => $wamid, 'status' => 'failed', 'recipient_id' => '48600100200',
                'errors' => [['code' => 131026, 'title' => 'Message undeliverable']]]],
        ]]]]]]);
        T::same(403, Browser::post('/webhook/whatsapp', [], ['HTTP_X_HUB_SIGNATURE_256' => 'sha256=zly'], $payload)->status, 'zły podpis');
        T::same(0, (int) DB::value('SELECT COUNT(*) FROM jobs WHERE type = "otp.fallback_sms"'));

        $sig = 'sha256=' . hash_hmac('sha256', $payload, 'test-app-secret');
        T::same(200, Browser::post('/webhook/whatsapp', [], ['HTTP_X_HUB_SIGNATURE_256' => $sig], $payload)->status);
        T::same('failed', DB::value('SELECT delivery_status FROM otp_codes'));
        T::same(0, FakeNet::count('api.smsapi.pl'), 'webhook odpowiada od razu, SMS idzie z kolejki');
        run_jobs();
        T::same(1, FakeNet::count('api.smsapi.pl'));
        T::same($waCode, FakeNet::lastCode('sms'), 'SMS niesie ten sam kod');

        $status = json_decode(Browser::get('/rejestracja/status')->body, true);
        T::same(true, $status['fallback_sent']);
        T::assert(str_contains(Browser::get('/rejestracja/weryfikacja')->body, 'WhatsApp nie dotarł, więc wysłaliśmy ten sam kod SMS-em'));

        Browser::post('/webhook/whatsapp', [], ['HTTP_X_HUB_SIGNATURE_256' => $sig], $payload);
        run_jobs();
        T::same(1, FakeNet::count('api.smsapi.pl'), 'powtórzony webhook nie wysyła drugiego SMS-a');
        Browser::post('/rejestracja/weryfikacja', ['code' => $waCode]);
        T::same(1, (int) DB::value('SELECT COUNT(*) FROM users'));
    },

    'SMS na prośbę użytkownika: dopiero po 30 s, raz; status doręczenia się nie cofa' => function (): void {
        auth_setup();
        Browser::post('/rejestracja', registration_form());
        $wamid = (string) DB::value('SELECT provider_id FROM otp_codes');
        Browser::post('/rejestracja/wyslij-sms');
        T::same(0, FakeNet::count('api.smsapi.pl'), 'przed upływem 30 s');
        DB::query('UPDATE otp_codes SET created_at = UTC_TIMESTAMP() - INTERVAL 31 SECOND');
        Browser::post('/rejestracja/wyslij-sms');
        Browser::post('/rejestracja/wyslij-sms');
        T::same(1, FakeNet::count('api.smsapi.pl'), 'tylko jeden SMS');

        OtpService::handleDeliveryStatus($wamid, 'read');
        OtpService::handleDeliveryStatus($wamid, 'delivered');
        T::same('read', DB::value('SELECT delivery_status FROM otp_codes'));
    },

    'Kod: 5 błędnych prób blokuje, nowy kod unieważnia stary, limit wysyłek na numer' => function (): void {
        auth_setup();
        Browser::post('/rejestracja', registration_form());
        $old = FakeNet::lastCode('whatsapp');
        $wrong = $old === '000000' ? '111111' : '000000';
        for ($i = 0; $i < 5; $i++) {
            $r = Browser::post('/rejestracja/weryfikacja', ['code' => $wrong]);
        }
        T::assert(str_contains($r->body, 'Zbyt wiele błędnych prób'));
        T::assert(str_contains(Browser::post('/rejestracja/weryfikacja', ['code' => $old])->body, 'Zbyt wiele błędnych prób'), 'poprawny kod po blokadzie nie działa');

        Browser::post('/rejestracja/wyslij-ponownie');
        $new = FakeNet::lastCode('whatsapp');
        if ($new !== $old) {
            T::same(422, Browser::post('/rejestracja/weryfikacja', ['code' => $old])->status, 'stary kod nieważny');
        }
        for ($i = 0; $i < 3; $i++) {
            Browser::post('/rejestracja/wyslij-ponownie');
        }
        T::same(5, FakeNet::count('graph.facebook.com'));
        $r = Browser::post('/rejestracja/wyslij-ponownie');
        T::same(5, FakeNet::count('graph.facebook.com'), 'szósta wysyłka w godzinę zablokowana');
        T::assert(str_contains(Browser::get('/rejestracja/weryfikacja')->body, 'Wysłaliśmy już kilka kodów'));
    },

    'Inny preset: bez NIP, osoba prywatna, typy kont z presetu' => function (): void {
        auth_setup('maszyny-i-sprzet');
        $form = registration_form(['nip' => '', 'account_type' => 'private', 'email' => 'jan@example.pl']);
        $page = Browser::get('/rejestracja');
        T::assert(str_contains($page->body, 'Osoba prywatna') && !str_contains($page->body, 'Inwestor / fundusz'));
        T::assert(str_contains($page->body, '(opcjonalnie)'), 'NIP opcjonalny');
        Browser::post('/rejestracja', $form);
        $r = Browser::post('/rejestracja/weryfikacja', ['code' => FakeNet::lastCode('whatsapp')]);
        T::same('/konto', $r->headers['Location'] ?? null, $r->body);
        T::same([null, 'private'], array_values(DB::fetch('SELECT company_id, account_type FROM users')));
        T::same(0, FakeNet::count('wl-api.mf.gov.pl'));
    },

    'Logowanie: błędne hasło, blokada po 5 próbach, przekierowanie next, wylogowanie' => function (): void {
        auth_setup();
        create_user('jan@example.pl', 'poprawne-haslo-1');
        $r = Browser::post('/logowanie', ['email' => 'jan@example.pl', 'password' => 'zle']);
        T::same(422, $r->status);
        T::assert(str_contains($r->body, 'Nieprawidłowy e-mail lub hasło.'));
        T::assert(str_contains(Browser::post('/logowanie', ['email' => 'nie@ma.pl', 'password' => 'x'])->body, 'Nieprawidłowy e-mail lub hasło.'), 'ten sam komunikat');

        for ($i = 0; $i < 4; $i++) {
            $r = Browser::post('/logowanie', ['email' => 'jan@example.pl', 'password' => 'zle']);
        }
        $r = Browser::post('/logowanie', ['email' => 'jan@example.pl', 'password' => 'poprawne-haslo-1']);
        T::same(429, $r->status, 'zablokowane także poprawnym hasłem');
        T::assert(str_contains($r->body, 'Spróbuj ponownie za 15 min'), $r->body);
        T::same(429, Browser::post('/logowanie', ['email' => 'jan@example.pl', 'password' => 'poprawne-haslo-1'], ['REMOTE_ADDR' => '203.0.113.10'])->status);

        // Atakujący z innego adresu nie blokuje właściciela; blokada nie zdradza, czy konto istnieje.
        T::same('/konto', Browser::post('/logowanie', ['email' => 'jan@example.pl', 'password' => 'poprawne-haslo-1'], ['REMOTE_ADDR' => '198.51.100.7'])->headers['Location'] ?? null, 'inny adres IP loguje się normalnie');
        Browser::post('/wyloguj');
        for ($i = 0; $i < 5; $i++) {
            Browser::post('/logowanie', ['email' => 'nikt@example.pl', 'password' => 'zle']);
        }
        $ghost = Browser::post('/logowanie', ['email' => 'nikt@example.pl', 'password' => 'zle']);
        T::same(429, $ghost->status, 'nieistniejące konto zachowuje się tak samo');
        T::assert(str_contains($ghost->body, 'Spróbuj ponownie za'));

        DB::query('DELETE FROM rate_limits');
        $r = Browser::post('/logowanie', ['email' => 'JAN@example.pl', 'password' => 'poprawne-haslo-1', 'next' => '/konto']);
        T::same('/konto', $r->headers['Location']);
        T::same('0', (string) DB::value('SELECT failed_logins FROM users'));
        $r = Browser::post('/logowanie', ['email' => 'x', 'password' => 'y']);
        T::same(302, $r->status, 'zalogowany → przekierowanie z /logowanie');

        Browser::post('/wyloguj');
        T::same(null, Session::get('user_id'));
        T::same('/logowanie?next=%2Fkonto', Browser::get('/konto')->headers['Location']);
        $r = Browser::post('/logowanie', ['email' => 'jan@example.pl', 'password' => 'poprawne-haslo-1', 'next' => '//zly.pl']);
        T::same('/konto', $r->headers['Location'], 'next nie wyprowadza poza portal');
    },

    'Zapamiętaj mnie: ciasteczko, rotacja, jednorazowość, unieważnienie resetem hasła' => function (): void {
        auth_setup();
        create_user('jan@example.pl', 'poprawne-haslo-1');
        Browser::post('/logowanie', ['email' => 'jan@example.pl', 'password' => 'poprawne-haslo-1', 'remember' => '1']);
        $cookie = Browser::$cookies[Auth::REMEMBER_COOKIE] ?? null;
        T::assert(is_string($cookie) && str_contains($cookie, ':'));

        $_SESSION = []; // nowa sesja przeglądarki, ciasteczko zostaje
        T::same(200, Browser::get('/konto')->status, 'wejście z ciasteczka');
        $rotated = Browser::$cookies[Auth::REMEMBER_COOKIE];
        T::assert($rotated !== $cookie, 'ciasteczko zrotowane');

        $_SESSION = [];
        Browser::$cookies[Auth::REMEMBER_COOKIE] = $cookie; // stare (np. skradzione)
        T::same(302, Browser::get('/konto')->status, 'stare ciasteczko nie działa');

        $_SESSION = [];
        Browser::$cookies[Auth::REMEMBER_COOKIE] = $rotated;
        Browser::post('/reset-hasla', ['email' => 'jan@example.pl']);
        run_jobs();
        Browser::post(path_of((string) last_mail_url('reset-password')), ['password' => 'nowe-haslo-12345', 'password_confirmation' => 'nowe-haslo-12345']);
        $_SESSION = [];
        Browser::$cookies[Auth::REMEMBER_COOKIE] = $rotated;
        T::same(302, Browser::get('/konto')->status, 'reset hasła wylogowuje zapamiętane urządzenia');
    },

    'Reset hasła: ta sama odpowiedź dla nieznanego e-maila, link 1 h jednorazowy' => function (): void {
        auth_setup();
        create_user('jan@example.pl', 'stare-haslo-123');
        Browser::post('/reset-hasla', ['email' => 'nieznany@example.pl']);
        $unknown = Browser::get('/reset-hasla')->body;
        Browser::post('/reset-hasla', ['email' => 'jan@example.pl']);
        $known = Browser::get('/reset-hasla')->body;
        T::assert(str_contains($unknown, 'Jeśli konto z tym adresem istnieje') && str_contains($known, 'Jeśli konto z tym adresem istnieje'));
        T::same(1, run_jobs(), 'mail tylko do istniejącego konta');

        $path = path_of((string) last_mail_url('reset-password'));
        T::same(200, Browser::get($path)->status);
        T::same(422, Browser::post($path, ['password' => 'krotkie', 'password_confirmation' => 'krotkie'])->status);
        $r = Browser::post($path, ['password' => 'nowe-haslo-12345', 'password_confirmation' => 'nowe-haslo-12345']);
        T::same('/logowanie', $r->headers['Location']);
        T::same(410, Browser::get($path)->status, 'link użyty');
        T::same('/konto', Browser::post('/logowanie', ['email' => 'jan@example.pl', 'password' => 'nowe-haslo-12345'])->headers['Location']);
        T::same(1, (int) DB::value("SELECT COUNT(*) FROM audit_log WHERE action = 'password.reset'"));
    },

    '2FA: admin musi skonfigurować, logowanie kodem z aplikacji i kodem awaryjnym' => function (): void {
        auth_setup();
        create_user('admin@example.pl', 'haslo-admina-123', 'admin');
        Browser::post('/logowanie', ['email' => 'admin@example.pl', 'password' => 'haslo-admina-123']);
        $r = Browser::get('/konto');
        T::same('/konto/2fa', $r->headers['Location'] ?? null, 'wymuszona konfiguracja');
        T::same(200, Browser::get('/konto/2fa')->status);
        $secret = (string) Session::get('totp_setup');
        T::same(422, Browser::post('/konto/2fa', ['code' => '000000'])->status);
        $r = Browser::post('/konto/2fa', ['code' => Totp::code($secret)]);
        T::assert(str_contains($r->body, 'Zapisz kody awaryjne'));
        preg_match_all('/<code>([a-f0-9]{6}-[a-f0-9]{6})<\/code>/', $r->body, $m);
        T::same(8, count($m[1]));
        T::assert(DB::value('SELECT totp_secret FROM users') !== $secret, 'sekret zaszyfrowany w bazie');
        T::same(200, Browser::get('/konto')->status);

        Browser::post('/wyloguj');
        $r = Browser::post('/logowanie', ['email' => 'admin@example.pl', 'password' => 'haslo-admina-123']);
        T::same('/logowanie/2fa', $r->headers['Location']);
        T::same(null, Session::get('user_id'), 'samo hasło nie loguje');
        T::same(422, Browser::post('/logowanie/2fa', ['code' => '000000'])->status);
        T::same('/konto', Browser::post('/logowanie/2fa', ['code' => Totp::code($secret)])->headers['Location']);

        Browser::post('/wyloguj');
        Browser::post('/logowanie', ['email' => 'admin@example.pl', 'password' => 'haslo-admina-123']);
        T::same('/konto', Browser::post('/logowanie/2fa', ['code' => $m[1][0]])->headers['Location'], 'kod awaryjny');
        Browser::post('/wyloguj');
        Browser::post('/logowanie', ['email' => 'admin@example.pl', 'password' => 'haslo-admina-123']);
        T::same(422, Browser::post('/logowanie/2fa', ['code' => $m[1][0]])->status, 'kod awaryjny działa raz');
        T::same(7, count(json_decode((string) DB::value('SELECT recovery_codes FROM users'), true)));

        Browser::fresh();
        Browser::post('/logowanie', ['email' => 'admin@example.pl', 'password' => 'haslo-admina-123']);
        Browser::post('/logowanie/2fa', ['code' => Totp::code($secret)]);
        Browser::post('/konto/2fa/wylacz', ['code' => Totp::code($secret)]);
        T::assert(DB::value('SELECT totp_enabled_at FROM users') !== null, 'admin nie może wyłączyć 2FA');
    },

    'API NIP: dane firmy dla formularza, walidacja, limit' => function (): void {
        auth_setup();
        $r = Browser::get('/api/firma/5260250274');
        $j = json_decode($r->body, true);
        T::same(true, $j['found']);
        T::same('Poznań', $j['company']['city']);
        T::same('UL. PRZYKŁADOWA 1', $j['company']['address']);
        Browser::get('/api/firma/5260250274');
        T::same(1, FakeNet::count('wl-api.mf.gov.pl'), 'cache 24 h');
        T::same(422, Browser::get('/api/firma/5260250275')->status);
    },
];
