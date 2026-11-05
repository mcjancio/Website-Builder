# Prompt budowy — Portal Ofert (PHP + MySQL, cPanel)

Prompt dla Claude (Claude Code / claude.ai), który buduje portal z [`KONCEPCJA.md`](KONCEPCJA.md) **etapami**. Każdy etap to osobna sesja: wklej **Blok A** (stały) + **jeden** blok etapu. Nie łącz etapów — po każdym jest przegląd i test.

---

## Blok A — kontekst stały (wklejaj zawsze)

```text
Jesteś senior PHP developerem. Budujesz "Portal Ofert" — uniwersalny portal ofert B2B
z moderacją i 3 poziomami dostępu. Źródła prawdy (przeczytaj przed pisaniem kodu):
- portal-ofert/KONCEPCJA.md  — wymagania, procesy, bezpieczeństwo
- portal-ofert/migrations/*.sql — schemat bazy (nie zmieniaj bez migracji)
- portal-ofert/presets/*.json — definicje branż (pola, kategorie, teksty)

TWARDE ZASADY
1. PHP 8.2+, strict_types=1, bez frameworków, bez Composera w produkcji.
   Dozwolona jedyna biblioteka zewnętrzna: PHPMailer (przypięta wersja w lib/).
2. MySQL 8 / MariaDB 10.6 przez PDO, WYŁĄCZNIE prepared statements.
   ERRMODE_EXCEPTION, EMULATE_PREPARES=false.
3. Hosting cPanel: document root = public_html/<subdomena>/ zawiera TYLKO index.php,
   .htaccess i assets/. Cały kod, storage/ i config.php leżą poza public_html.
4. Zero hardkodowanych tekstów UI — każdy tekst przez __('klucz') z lang/pl.php.
   Interfejs w 100% po polsku.
5. Branża NIE jest zaszyta w kodzie. Pola, filtry, kategorie, etykiety, limity
   pochodzą z bazy (zaimportowany preset). Kod nie zna słowa "deweloper".
6. Każdy formularz POST: token CSRF + walidacja serwerowa (JS tylko jako wygoda).
   Wymagane checkboxy zgód sprawdzane na serwerze.
7. Escapowanie w widokach funkcją e(). Brak echo niezaufanych danych bez e().
8. Kontrola dostępu L1/L2/L3 w JEDNYM miejscu (App\Access\Gate) — widoki pytają
   Gate::canSee($user, $listing, $level). Nigdy nie filtruj treści tylko w CSS/JS.
9. Pliki: finfo MIME z białej listy, losowe nazwy, zapis w storage/, wydawanie
   przez /plik/{token} po sprawdzeniu Gate + wpis w downloads.
10. Długie operacje (e-mail, SMS, GetResponse, AI) idą do tabeli jobs; obsługuje je
    bin/worker.php uruchamiany z crona co minutę. Awaria integracji nie może
    blokować użytkownika.
11. Frontend: semantyczny HTML renderowany na serwerze, CSS mobile-first z tokenami
    (kolory z presetu), vanilla JS < 100 KB, wszystko działa bez JS (progressive
    enhancement). WCAG 2.2 AA, cele dotykowe 44×44 px, widoczny focus.
12. Kod czytelny dla jednego developera: małe klasy, PSR-12, docblocki tylko gdy
    coś jest nieoczywiste. Bez warstw abstrakcji "na zapas".
13. Po każdym etapie: lista plików, instrukcja testu ręcznego (kroki + oczekiwany
    wynik), lista otwartych kwestii. Nie twierdź, że coś działa, jeśli tego nie
    uruchomiłeś (php -l, testy).
```

---

## Etap 1 — Rdzeń i instalacja ✅ (zrealizowany — kod w app/, bin/, public/; opis w INSTALACJA.md)

```text
ETAP 1: rdzeń aplikacji.
Zbuduj: public/index.php (front controller), .htaccess (rewrite + nagłówki
bezpieczeństwa: CSP, HSTS, X-Frame-Options, Referrer-Policy, Permissions-Policy,
blokada dostępu do plików ukrytych), app/Core: Router (GET/POST, parametry {id},
grupy z middleware), Request, Response, View (layouty + partiale + e()),
DB (PDO singleton + transakcje), Session (bezpieczne cookies, regeneracja ID),
Csrf, Validator (reguły: required, email, maxlength, int, decimal, date, in,
phone_pl, nip), RateLimiter (tabela rate_limits), Logger (storage/logs, rotacja
dzienna), Queue (jobs: push/reserve/complete/fail z backoffem 1/5/15/60 min),
Config (config.php), lang/pl.php + helper __().
bin/migrate.php (wykonuje migrations/*.sql, zapisuje w tabeli migrations),
bin/import-preset.php <plik.json> (idempotentny import kategorii, pól, opcji,
stron, ustawień), bin/create-admin.php, bin/worker.php (szkielet),
/health (JSON: db, storage zapisywalny, liczba zaległych jobs).
config.example.php z opisem każdego klucza. .cpanel.yml do wdrożenia przez
cPanel Git Version Control.
Test: instalacja na czystej bazie, import presetu inwestycje-deweloperskie.json
dwa razy (bez duplikatów), /health zwraca ok.
```

## Etap 2 — Konta: rejestracja NIP + kod WhatsApp/SMS + e-mail, logowanie ✅ (zrealizowany — kanały: WhatsApp → zapasowo SMS, patrz docs/WHATSAPP-KONFIGURACJA.md)

```text
ETAP 2: uwierzytelnianie wg KONCEPCJA.md §5.1–5.2.
- Integrations\MfVatWhitelist: GET https://wl-api.mf.gov.pl/api/search/nip/{nip}?date=RRRR-MM-DD,
  timeout 5 s, cache 24 h, mapowanie name/workingAddress/statusVat; przy błędzie
  API → tryb ręczny (company.source='manual').
- Walidacja NIP: suma kontrolna (wagi 6,5,7,2,3,4,5,6,7) w PHP i JS.
- Telefon: normalizacja do E.164 (+48 domyślnie), PL = 9 cyfr; maska w JS.
- Integrations\Sms\SmsApi (REST, token z config) + interfejs SmsProvider
  (drugi adapter: Twilio). Kod 6 cyfr, hash w sms_codes, 10 min, 5 prób,
  limity: 3 SMS/numer/h, 10/IP/dobę. Przycisk z loaderem i odliczaniem 60 s.
- Rejestracja: imię, nazwisko, e-mail, hasło (min. 10 znaków, wskaźnik siły),
  telefon, typ konta (lista z settings.account_types, jeśli preset ją ma),
  NIP + autouzupełnienie firmy — WYMAGANY TYLKO gdy registration.require_nip,
  osoby prywatne gdy registration.allow_private_person, zgody → kod SMS
  → konto + logowanie → e-mail weryfikacyjny (24 h dostępu przed potwierdzeniem).
  Wszystkie etykiety przez __() — słownictwo (np. „oferujący”) z presetu (terms).
  Test na dwóch presetach: inwestycje-deweloperskie (NIP wymagany)
  i maszyny-i-sprzet (bez NIP, osoba prywatna).
- Logowanie, "Zapamiętaj mnie" (selector/validator, 30 dni), reset hasła (1 h),
  blokada po 5 nieudanych próbach, 2FA TOTP (obowiązkowe dla admin/moderator).
- Po rejestracji job getresponse.upsert (telefon w polu własnym, tag).
- Każdy komunikat po polsku, mówi co się stało i co dalej.
Test: pełna ścieżka na telefonie (360 px) < 90 s; zły NIP, zły kod SMS ×5,
wygaśnięcie kodu, reset hasła zalogowanego użytkownika.
```

## Etap 3 — Oferty: kreator, wartości pól, media, załączniki ✅ (zrealizowany — moderacja tymczasowo z CLI: bin/moderate.php)

```text
ETAP 3: oferty wg KONCEPCJA.md §5.3 i §6.
- Listings\FieldRenderer: generuje kontrolki formularza z field_definitions
  (typy: text, textarea, int, decimal, money[+waluta], percent, date
  [allow_past_years], select, multiselect, bool, url, url_list, region).
- Listings\ValueStore: zapis do listing_values / listing_value_options
  (money → także v_dec_pln wg kursu NBP z cache dziennego).
- Kreator 4 kroki (Podstawy → Parametry → Opis i media → Dokumenty), autosave
  szkicu co 30 s, licznik znaków krótkiego opisu (limit z presetu).
- Zdjęcia: upload z paskiem postępu, GD/Imagick → WebP 400/960/1600, usunięcie
  EXIF, wybór okładki, kolejność drag&drop.
- Załączniki: do 25 (limit z presetu), przy każdym etykieta + poziom dostępu
  (domyślnie 3), wyraźna lista dodanych plików, usuwanie.
- Opis w Markdown (bez surowego HTML), podgląd.
- Statusy draft → pending; edycja opublikowanej = listing_revisions (pending).
Test: oferta z 3 PDF i 5 zdjęciami < 10 min; kwota w EUR; data startu 3 lata
wstecz + "projekt już trwa".
```

## Etap 4 — Lista, filtry, szczegóły, poziomy dostępu, zapytania ✅ (zrealizowany — dostęp L3 tymczasowo z CLI: bin/access.php)

```text
ETAP 4: front publiczny wg KONCEPCJA.md §3, §5.4, §5.5.
- /oferty: filtry generowane z pól filterable (filter_ui), parametry w URL,
  zapytania SQL na indeksach listing_values (EXISTS / JOIN per filtr),
  paginacja, sortowanie, cache listy unieważniany przy publikacji.
- /oferta/{slug}-{id}: sekcje wg grup pól; Access\Gate decyduje o każdym polu,
  zdjęciu i załączniku; zamiast ukrytej treści czerwony baner z linkami
  "Zaloguj się" / "Załóż konto" (powrót na tę ofertę po zalogowaniu).
- Prośba o dostęp L3 (access_grants) z uzasadnieniem.
- Formularz zapytania: zgoda wymagana serwerowo, honeypot, rate limit, dane
  zalogowanego wstawione automatycznie, zapis inquiries + consents, jobs:
  mail do admina i autora, getresponse, webhook. Komunikat sukcesu po polsku.
- Statystyki: listing_stats (dzienne), listing_member_views.
- SEO: meta, Open Graph (zdjęcie okładki), JSON-LD, sitemap.xml tylko z L1.
Test: ta sama oferta oglądana jako gość / zalogowany / z L3 — porównaj widoczne
pola i pliki; próba pobrania pliku L3 bez uprawnień = 403 + wpis w audit_log.
```

## Etap 5 — Panel użytkownika i panel admina ✅ (zrealizowany — moderacja i dostępy w przeglądarce; bin/moderate.php i bin/access.php zostają jako zapas)

```text
ETAP 5: /konto i /admin wg KONCEPCJA.md §5.6–5.7.
Konto: moje oferty (status, wyświetlenia, zapytania), obserwowane, zapisane
wyszukiwania (+ alert daily/weekly przez jobs), moje dostępy L3, profil i firma,
bezpieczeństwo (hasło, 2FA, aktywne sesje), usunięcie konta z anonimizacją.
Admin: pulpit (do moderacji, nowe zapytania, nowi użytkownicy, wykres 30 dni),
moderacja (diff rewizji było/jest, zatwierdź / odrzuć z komentarzem / poproś
o poprawki → e-mail do autora), oferty, użytkownicy (blokada, rola), zapytania
(CRM-lite), prośby L3 (nadaj/odmów/odbierz, data wygaśnięcia), EDYTOR KRYTERIÓW
(pola, opcje, kolejność, poziom dostępu, filtrowalność; ukrywanie zamiast
usuwania; eksport do presetu JSON), strony treści (Markdown + wersjonowanie),
ustawienia (branding, e-maile, SMS, GetResponse, webhook), audit_log, eksport CSV,
przełącznik "tryb kampanii".
E-mail do moderatorów < 1 min od wysłania oferty do akceptacji.
```

## Etap 6 — Utrzymanie, migracja z WordPressa, odbiór ✅ (zrealizowany — docs/IMPORT-WP.md, docs/ODBIOR.md)

```text
ETAP 6: produkcja.
- bin/backup.php: mysqldump + archiwum storage/, rotacja 14 dni, opcjonalny
  upload poza serwer; instrukcja crona w cPanel.
- Dzienny raport e-mail z błędami z logów (tylko gdy są).
- bin/import-wp.php: import ofert, użytkowników (wymuszenie resetu hasła),
  zdjęć i załączników z dotychczasowej instalacji WordPress (wp_posts/wp_postmeta
  + pliki uploads) z plikiem mapowania meta_key → field.key; raport rekordów
  niezmapowanych; mapa przekierowań 301 starych adresów do .htaccess.
- Checklista odbioru z KONCEPCJA.md §11 — każdy punkt zweryfikowany i opisany.
```

## Etap 7 (wersja 2) — Asystent AI

```text
ETAP 7: moduł AI wg KONCEPCJA.md §9 (wyłączony domyślnie, ustawienie ai.enabled).
- Integrations\Claude: Messages API przez cURL, klucz z config.php, model
  konfigurowalny (domyślnie aktualny Claude Sonnet), timeout, log do ai_usage,
  miesięczny budżet — po przekroczeniu funkcje się wyłączają.
- "Oferta z PDF": upload teasera → job ai.extract → odpowiedź w JSON zgodnym
  ze schematem wygenerowanym z field_definitions (tylko istniejące klucze i opcje)
  → formularz kreatora wypełniony, pola z AI oznaczone "do sprawdzenia",
  listings.ai_extracted=1. Nic nie publikuje się bez człowieka.
- "Popraw opis": krótki opis w limicie znaków + pełny opis; anonimizacja pól
  wskazanych w presecie (ai.anonymize_level1) w treściach L1.
- Podpowiedzi dla moderatora: kompletność, niespójności liczb, ryzyka.
```
