<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\Markdown;
use App\Core\Migrator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Validator;
use App\Core\View;

return [
    'NIP: poprawne i błędne sumy kontrolne' => function (): void {
        T::assert(Validator::nipValid('5260250274'), 'poprawny NIP');
        T::assert(Validator::nipValid('526-025-02-74'), 'z myślnikami');
        T::assert(Validator::nipValid('PL 526 025 02 74'), 'z prefiksem PL');
        T::assert(!Validator::nipValid('5260250275'), 'zła cyfra kontrolna');
        T::assert(!Validator::nipValid('123456789'), '9 cyfr');
        T::assert(!Validator::nipValid('0000000000'), 'same zera');
        T::assert(!Validator::nipValid(''), 'pusty');
    },

    'Telefon: normalizacja do E.164' => function (): void {
        T::same('+48600100200', Validator::normalizePhone('600 100 200'));
        T::same('+48600100200', Validator::normalizePhone('+48 600-100-200'));
        T::same('+48600100200', Validator::normalizePhone('0048600100200'));
        T::same('+48600100200', Validator::normalizePhone('48600100200'));
        T::same('+48618001234', Validator::normalizePhone('(61) 800 12 34'), 'stacjonarny');
        T::same('+4915112345678', Validator::normalizePhone('+49 151 12345678'), 'zagraniczny');
        T::same(null, Validator::normalizePhone('600100'), '6 cyfr (przypadek z testów portalu 2026)');
        T::same(null, Validator::normalizePhone('100100200'), 'PL nie zaczyna się od 1');
        T::same(null, Validator::normalizePhone('600a100200'), 'litery');
    },

    'Walidator: reguły, komunikaty po polsku, normalizacja' => function (): void {
        $v = Validator::make(
            ['email' => ' JAN@firma.pl ', 'phone' => '600100200', 'nip' => '526-025-02-74', 'terms' => 'on', 'note' => ''],
            ['email' => 'required|email', 'phone' => 'required|phone_pl', 'nip' => 'required|nip', 'terms' => 'accepted', 'note' => 'maxlength:10']
        );
        T::assert(!$v->fails(), 'brak błędów: ' . json_encode($v->errors(), JSON_UNESCAPED_UNICODE));
        T::same('+48600100200', $v->validated()['phone']);
        T::same('5260250274', $v->validated()['nip']);
        T::same(true, $v->validated()['terms']);
        T::same(null, $v->validated()['note'], 'puste pole opcjonalne = null');

        $v = Validator::make(['email' => 'x', 'terms' => null, 'n' => 'abc'], [
            'email' => 'required|email', 'terms' => 'accepted', 'name' => 'required', 'n' => 'int',
        ], ['name' => 'Imię', 'terms' => 'Akceptuję regulamin']);
        $e = $v->errors();
        T::same('Wpisz poprawny adres e-mail.', $e['email'] ?? null);
        T::same('Zaznacz „Akceptuję regulamin”, aby kontynuować.', $e['terms'] ?? null, 'wymagany checkbox zgody');
        T::same('Pole „Imię” jest wymagane.', $e['name'] ?? null);
        T::assert(isset($e['n']));
        T::throws(fn() => Validator::make(['a' => 'b'], ['a' => 'nieznana']), \InvalidArgumentException::class);
    },

    'Router: parametry, 404, 405, middleware w kolejności' => function (): void {
        $r = new Router();
        $log = [];
        $mw = function (string $name) use (&$log) {
            return function (Request $req, callable $next) use ($name, &$log): Response {
                $log[] = $name;
                return $next($req);
            };
        };
        $r->get('/oferta/{slug}-{id:\d+}', fn(Request $q) => Response::text($q->params['slug'] . '|' . $q->params['id']));
        $r->group('/admin', [$mw('a')], function (Router $g) use ($mw) {
            $g->post('/zapisz', fn() => Response::text('ok'), [$mw('b')]);
        });

        $res = $r->dispatch(new Request('GET', '/oferta/osiedle-zielone-42'));
        T::same('osiedle-zielone|42', $res->body);
        T::same(404, $r->dispatch(new Request('GET', '/oferta/abc-x'))->status, 'id musi być liczbą');

        $res = $r->dispatch(new Request('GET', '/admin/zapisz'));
        T::same(405, $res->status);
        T::same('POST', $res->headers['Allow'] ?? null);

        $r->dispatch(new Request('POST', '/admin/zapisz'));
        T::same(['a', 'b'], $log, 'middleware grupy przed middleware trasy');
        T::same('/a/b', Request::normalizePath('//a///b/'));
    },

    'CSRF: token, porównanie stałoczasowe, rotacja' => function (): void {
        $_SESSION = [];
        $t = Csrf::token();
        T::same(64, strlen($t));
        T::assert(Csrf::validate(new Request('POST', '/', [], ['_csrf' => $t])));
        T::assert(Csrf::validate(new Request('POST', '/', [], [], ['HTTP_X_CSRF_TOKEN' => $t])), 'nagłówek');
        T::assert(!Csrf::validate(new Request('POST', '/', [], ['_csrf' => 'x'])));
        T::assert(!Csrf::validate(new Request('POST', '/', [], ['_csrf' => ['tablica']])), 'tablica zamiast tekstu');
        Csrf::rotate();
        T::assert(!Csrf::validate(new Request('POST', '/', [], ['_csrf' => $t])), 'stary token po rotacji');
    },

    'Response: redirect tylko wewnętrzny, nagłówki' => function (): void {
        T::same('/konto', Response::redirect('/konto')->headers['Location']);
        T::same('/', Response::redirect('https://zly.pl')->headers['Location'], 'open redirect');
        T::same('/', Response::redirect('//zly.pl')->headers['Location'], 'protocol-relative');
        T::assert(!str_contains(Response::SECURITY_HEADERS['Content-Security-Policy'], 'unsafe-inline'));
    },

    'SQL: podział migracji na instrukcje' => function (): void {
        $sql = "-- komentarz; z średnikiem\nCREATE TABLE a (x VARCHAR(5) DEFAULT 'a;b'); # hash; komentarz\n"
            . "/* blok; */ INSERT INTO a VALUES ('it''s;'), (\"q;\\\"\");\nCREATE TABLE `b;c` (y INT)";
        $parts = Migrator::split($sql);
        T::same(3, count($parts), json_encode($parts));
        T::assert(str_contains($parts[0], "'a;b'"));
        T::assert(str_contains($parts[1], "'it''s;'"));
        T::assert(str_contains($parts[2], '`b;c`'));

        $init = Migrator::split((string) file_get_contents(APP_ROOT . '/migrations/001_init.sql'));
        $creates = count(array_filter($init, fn($s) => str_starts_with($s, 'CREATE TABLE')));
        T::same(28, $creates, 'tabele w 001_init (migrations tworzy migrator)');
    },

    'Markdown: bezpieczny HTML' => function (): void {
        $html = Markdown::toHtml("## Zasady\n\n1. **Poziom 1** — _publiczny_\n2. Poziom 2\n\n<script>alert(1)</script>\n\n[link](https://a.pl/x_y_z) [zly](javascript:alert(1)) [rel](//evil.pl)");
        T::assert(str_contains($html, '<h2>Zasady</h2>'));
        T::assert(str_contains($html, '<ol>') && str_contains($html, '<strong>Poziom 1</strong>') && str_contains($html, '<em>publiczny</em>'));
        T::assert(!str_contains($html, '<script>'), 'surowy HTML escapowany');
        T::assert(str_contains($html, 'href="https://a.pl/x_y_z"'), 'podkreślenia w adresie nienaruszone: ' . $html);
        T::assert(!str_contains($html, 'href="javascript'), 'javascript: odrzucony');
        T::assert(!str_contains($html, 'href="//evil'), 'protocol-relative odrzucony');
    },

    'Widoki: renderowanie i escapowanie' => function (): void {
        $html = View::render('errors/404', [], null);
        T::assert(str_contains($html, 'Nie znaleziono strony'));
        T::same('&lt;b&gt;&quot;x&quot;&amp;', e('<b>"x"&'));
        T::throws(fn() => View::render('../config'), \InvalidArgumentException::class, 'path traversal w nazwie szablonu');
    },
];
