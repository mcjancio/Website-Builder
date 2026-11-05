# Portal Ofert — koncepcja v1.0 (PHP + MySQL, cPanel)

> Uniwersalny silnik portalu ogłoszeń/ofert B2B z moderacją, poziomami dostępu i konfigurowalnymi kryteriami.
> Pierwsze wdrożenie: **portal ofert inwestycji deweloperskich** (następca `inwestycje.finansedewelopera.pl`, dziś WordPress + WooCommerce).
> Kolejne wdrożenia: ta sama baza kodu, inny „preset” (kategorie, pola, teksty, kolory) — bez programowania.

Data: 2026-11-05 · Autor koncepcji: IMJ sp. z o.o.

---

## 1. Skąd ten projekt — wnioski z dotychczasowego portalu

Obecny portal działa na WordPressie z WooCommerce, wtyczką katalogową, Elementorem i Wordfence. Z korespondencji i dokumentu „Portal_poprawki” (lipiec 2024 – marzec 2026) wynika, że problemy nie były pojedynczymi błędami, tylko skutkiem architektury: obcy kod wtyczek, którego nie kontrolujemy, i nakładanie własnej logiki na cudzy model danych.

| # | Co bolało w wersji WP/WooCommerce | Przyczyna | Jak to rozwiązuje nowa wersja |
|---|---|---|---|
| 1 | Strona „rozsypała się” po automatycznej aktualizacji Elementora — akurat po wysłaniu 300 SMS-ów z linkiem do oferty | Automatyczne aktualizacje wtyczek, zależność od page buildera | Zero wtyczek. Kod w Git, wdrożenie świadomym `git pull` / cPanel Git. Nic nie zmienia się samo |
| 2 | Awarie, „muli”, błędy przy dodawaniu załączników; portal „dostał rykoszetem” od głównej strony | Wspólne zasoby i ataki na WP, ciężki stos (WP + Woo + Elementor) | Osobna, lekka aplikacja (~kilkadziesiąt plików PHP), własna baza, brak `/wp-admin` do atakowania. Docelowo czas odpowiedzi < 200 ms |
| 3 | Mieszanka angielskiego i polskiego (My Dashboard, regulamin EN, komunikaty formularzy EN) | Tłumaczenia wtyczek | Wszystkie teksty w jednym pliku `lang/pl.php`. Nie ma tekstu spoza tego pliku |
| 4 | Fejkowe konta zakładane tylko po to, żeby obejrzeć treść oferty | Rejestracja bez weryfikacji | Rejestracja = e-mail (double opt-in) + telefon (kod SMS) + NIP (suma kontrolna + automatyczne pobranie danych firmy) |
| 5 | Kilka podejść do weryfikacji SMS i NIP, rejestracja bez hasła, mylące komunikaty | Doklejanie funkcji do cudzego formularza rejestracji | Jeden prosty, własny proces: hasło ustawiane od razu, SMS z loaderem, czytelne komunikaty, telefon zawsze w formacie E.164 (`+48…`) |
| 6 | Treść ofert znikała dla zalogowanych albo pokazywała się wszystkim (kilka razy) | Uprawnienia sterowane ustawieniami wtyczki | 3 poziomy dostępu wbudowane w model danych — każde pole i każdy załącznik ma swój poziom |
| 7 | Brak powiadomienia o ofercie do zatwierdzenia | Domyślne powiadomienia wtyczki | Kolejka moderacji + e-mail do admina przy każdej nowej i każdej edytowanej ofercie |
| 8 | Tylko 1 PDF na ofertę, nowy plik nadpisywał poprzedni; brak informacji o uploadzie | Ograniczenie wtyczki | Do 25 załączników, przeciągnij i upuść, pasek postępu, poziom dostępu ustawiany osobno dla każdego pliku |
| 9 | Kryteria ofert „zamrożone” — każda zmiana wymagała programisty („ważne, żeby można było modyfikować kryteria w trakcie”) | Pola zaszyte w szablonie | Kryteria (pola, opcje, filtry) edytowane w panelu admina, bez zmian w kodzie |
| 10 | Tylko PLN; nie dało się wpisać daty rozpoczęcia z przeszłości (projekt już w budowie) | Sztywne pola | Pole „kwota” z walutą (PLN/EUR/USD/GBP), daty bez sztucznych ograniczeń + flaga „projekt w toku” |
| 11 | Ucięte tytuły, rozjazdy na mobile, niewidoczny „burger”, checkbox zgody nie był wymagany | Konflikty CSS motywu/buildera, walidacja tylko w JS | Własny CSS mobile-first, walidacja zawsze także po stronie serwera |
| 12 | Telefon nie trafiał do GetResponse; lista zapisów nie była zsynchronizowana | Niezależne integracje wtyczek | Jeden moduł integracji (GetResponse API v3 + webhooki) z kolejką i ponawianiem wysyłki |
| 13 | Codzienne alerty Wordfence, zabezpieczanie przed nieautoryzowanym wejściem | Duża powierzchnia ataku WP | Mała powierzchnia ataku, CSRF/CSP/rate limit w rdzeniu, 2FA dla admina, dziennik zdarzeń |

**Wniosek:** Paweł nie potrzebuje sklepu (WooCommerce był nadmiarowy). Potrzebuje **katalogu ofert z moderacją, kontrolą dostępu i zbieraniem leadów**. Taki system da się napisać w czystym PHP mniejszym kosztem utrzymania niż WP z wtyczkami.

---

## 2. Cele produktu

1. **Niezawodność** — portal ma działać w dniu kampanii. Brak automatycznych zmian w kodzie, monitoring, kopie zapasowe, ścieżka przywracania < 15 min.
2. **Jakość leadów** — każdy zarejestrowany użytkownik ma zweryfikowany e-mail, telefon i firmę. Właściciel portalu wie, kto oglądał którą ofertę.
3. **Kontrola informacji** — 3 poziomy dostępu (public → zalogowani → po NDA).
4. **Uniwersalność** — jeden silnik dla wielu portali: inwestycje deweloperskie, grunty, sprzedaż firm, maszyny, nieruchomości komercyjne, franczyzy…
5. **Prostota hostingu** — standardowy cPanel: PHP 8.2+, MySQL/MariaDB, cron, SSL (AutoSSL). Bez Node, bez Dockera, bez build stepu.
6. **AI jako przyspieszacz pracy** — AI pomaga wprowadzać oferty (np. z teasera PDF), ale nie podejmuje decyzji za moderatora.

---

## 3. Model dostępu (3 poziomy)

Zgodnie z zasadami współpracy opisanymi w dokumencie „Portal_poprawki”:

| Poziom | Kto | Co widzi | Co może |
|---|---|---|---|
| **L1 — Publiczny** | Każdy (także Google) | Tytuł, zdjęcie główne, krótki opis (limit znaków), parametry oznaczone jako publiczne (typ, region, etap, przedział kapitału) | Przeglądać i filtrować, wysłać zapytanie (formularz z wymaganą zgodą) |
| **L2 — Zarejestrowany** | Zweryfikowany użytkownik (e-mail + SMS + NIP) | Pełny opis, parametry L2, galeria, wideo | Dodawać własne oferty (po moderacji), zapisywać wyszukiwania, obserwować oferty |
| **L3 — Dopuszczony** | Użytkownik z nadanym dostępem do konkretnej oferty (po NDA / umowie współpracy) | Dokumenty L3 (teaser rozszerzony, model finansowy, operaty, projekty) | Pobierać dokumenty (każde pobranie zapisywane w logu) |

Zasady techniczne:
- Każde **pole** kryteriów i każdy **załącznik** ma atrybut `access_level` (1/2/3).
- Niezalogowany widzi w miejscu ukrytej treści czerwony baner: „Pełna treść oferty jest dostępna po zalogowaniu. **Zaloguj się** lub **załóż konto**.” z linkami. Po zalogowaniu wraca do tej samej oferty.
- „Dodaj ofertę” dla niezalogowanego → logowanie/rejestracja z powrotem do formularza.
- L3 nadaje admin/moderator (lub autor oferty — opcja) jednym kliknięciem przy prośbie o dostęp. Opcjonalnie: akceptacja NDA online (checkbox + zapis IP/czasu/wersji dokumentu) — wersja 2.
- Pliki leżą **poza `public_html`** i są wydawane przez `download.php` po sprawdzeniu uprawnień — linku do PDF nie da się podać dalej.

---

## 4. Role i uprawnienia

| Rola | Uprawnienia |
|---|---|
| `admin` | Wszystko: konfiguracja portalu, kryteria, użytkownicy, role, integracje, eksporty |
| `moderator` | Zatwierdzanie/odrzucanie ofert i edycji, nadawanie L3, obsługa zapytań |
| `member` | Własny profil i firma, własne oferty, zapytania, obserwowane, zapisane wyszukiwania |
| `member` + flaga `investor` | Jak `member`, plus profil inwestora (preferencje kapitałowe) używany do dopasowań — wersja 2 |

Uprawnienia są zapisane w kodzie (prosta mapa `rola → akcje`), bez rozbudowanego edytora ról jak w WP („do czego służy profil Współtwórca?” — takich pytań ma nie być).

---

## 5. Główne procesy

### 5.1 Rejestracja (cel: maks. 90 s, zero niejasności)

1. Formularz: imię i nazwisko, e-mail, **hasło (od razu)**, telefon (domyślny prefiks `+48`, maska), **NIP**, zgody (regulamin — wymagana, marketing — opcjonalna).
2. Po wpisaniu NIP: walidacja sumy kontrolnej w przeglądarce i na serwerze + pobranie nazwy i adresu firmy z **API Wykazu podatników VAT MF** (`wl-api.mf.gov.pl`, bez klucza) — z możliwością ręcznej korekty, gdy firma nie jest czynnym podatnikiem VAT.
3. „Wyślij kod SMS” → loader i blokada przycisku na 60 s → 6-cyfrowy kod (ważny 10 min, maks. 5 prób, limit wysyłek na numer/IP/dobę). Bramka: **SMSAPI.pl** (konto już istnieje), adapter wymienny (np. Twilio).
4. Po poprawnym kodzie konto zostaje utworzone, a użytkownik — zalogowany. E-mail z linkiem potwierdzającym (double opt-in). Do czasu potwierdzenia e-maila: dostęp L2 do 24 h, potem blokada do kliknięcia linku.
5. Kontakt trafia do listy GetResponse (z telefonem w polu własnym) przez kolejkę integracji.
6. Admin dostaje powiadomienie o nowym użytkowniku (dzienne podsumowanie lub od razu — do wyboru).

Komunikaty mówią wprost, co się dzieje: „Kod wysłany na +48 600 *** 123”, „Konto utworzone — jesteś zalogowany”, „Sprawdź skrzynkę, aby potwierdzić e-mail”.

### 5.2 Logowanie
- E-mail + hasło; „Zapamiętaj mnie” = bezpieczny token (selector/validator, 30 dni) — naprawia niedziałający checkbox z WP.
- Reset hasła: link jednorazowy (1 h), działa także dla zalogowanego.
- Blokada po 5 nieudanych próbach (narastająco), opcjonalne 2FA (TOTP) dla użytkowników, obowiązkowe dla admina/moderatora.
- Opcja (ustawienie portalu): logowanie kodem SMS przy wejściu w L2 dla nowych urządzeń.

### 5.3 Dodawanie i edycja oferty
1. Kreator w 4 krokach: **Podstawy** (tytuł, kategoria, krótki opis z licznikiem znaków) → **Parametry** (pola z presetu, zależne od kategorii) → **Opis i media** (edytor bez HTML, zdjęcia, wideo YouTube/Vimeo) → **Dokumenty** (do 25 plików, poziom dostępu przy każdym).
2. Szkic zapisuje się automatycznie co 30 s.
3. „Wyślij do akceptacji” → status `pending` → **e-mail do moderatorów** + licznik w panelu.
4. Edycja opublikowanej oferty tworzy **rewizję** — opublikowana wersja zostaje widoczna, dopóki moderator nie zatwierdzi zmian (widok różnic „było/jest”).
5. Moderator: Zatwierdź / Odrzuć z komentarzem / Poproś o poprawki. Autor dostaje e-mail.
6. Statusy: `draft → pending → published → (paused | sold | archived)`, a także `rejected`.

### 5.4 Przeglądanie i filtrowanie
- Lista ofert z filtrami generowanymi z presetu (typ, region, etap, kapitał od–do, ROI, okres, forma, zabezpieczenia…).
- Filtry w parametrach URL (`/oferty?typ=mieszkaniowa&woj=wielkopolskie&kapital_od=1000000`) — linki do kampanii i mailingów pokazują od razu przefiltrowaną listę.
- Sortowanie: najnowsze, kapitał, ROI, „wyróżnione”.
- Widok mapy (Leaflet + OpenStreetMap, z dokładnością do gminy dla L1) — wersja 2.

### 5.5 Zapytanie o ofertę (lead)
- Formularz: imię, e-mail, telefon (walidacja), wiadomość, **wymagana zgoda (sprawdzana na serwerze)**, honeypot + limit wysyłek.
- Dla zalogowanego dane wypełniają się same.
- Zapis w bazie + e-mail do admina i (opcjonalnie) autora oferty + wysyłka do CRM/GetResponse.
- Komunikat po wysłaniu po polsku, w tym samym miejscu strony.

### 5.6 Panel użytkownika („Moje konto”)
Moje oferty (statusy, statystyki wyświetleń i zapytań), Obserwowane, Zapisane wyszukiwania (+ alert e-mail o nowych pasujących ofertach), Moje dostępy L3, Profil i firma, Bezpieczeństwo (hasło, 2FA, sesje), Usunięcie konta (RODO — z anonimizacją).

### 5.7 Panel admina
Pulpit (oferty do moderacji, nowe zapytania, nowi użytkownicy, wykres 30 dni) · Oferty · Moderacja · Użytkownicy · Zapytania (CRM-lite: status, notatka, przypisanie) · Prośby o dostęp L3 · **Kryteria** (edytor presetu) · Strony treści (Zasady współpracy, Regulamin, Polityka prywatności, Kontakt) · Ustawienia (branding, e-maile, integracje, SMS) · Dziennik zdarzeń · Eksport CSV.

---

## 6. Uniwersalność — „presety”

Portal nie wie, że obsługuje inwestycje deweloperskie. Wie tylko, że ma **typy ofert**, a każdy typ ma **definicje pól**. Preset to plik JSON importowany przy instalacji (i edytowalny później w panelu):

```
presets/
├── inwestycje-deweloperskie.json   ← wdrożenie referencyjne (pełny zestaw kryteriów)
└── sprzedaz-firm.json              ← przykład: ten sam silnik, inna branża
```

Preset zawiera:
- `portal` — nazwa, claim, kolory, logo, teksty strony głównej, adresy e-mail.
- `categories` — drzewo kategorii.
- `fields` — definicje pól: `key`, `label`, `type` (`text`, `textarea`, `int`, `decimal`, `money`, `percent`, `date`, `select`, `multiselect`, `bool`, `url`, `region`), `options`, `required`, `access_level`, `filterable`, `filter_ui` (`select`, `checkboxes`, `range`), `show_in_card`, `group`, `help`, `categories` (dla których kategorii pole się pokazuje).
- `limits` — długość krótkiego opisu, liczba zdjęć/plików, rozmiary.

**Przechowywanie wartości pól** (szczegóły w `migrations/001_init.sql`): tabela `listing_values` z kolumnami typowanymi (`v_int`, `v_dec`, `v_text`, `v_date`, `currency`) i indeksami pod filtry + `listing_value_options` dla wielokrotnego wyboru. Filtry działają na indeksach SQL, nie na przeszukiwaniu JSON-ów. Zmiana opcji w panelu nie psuje starych ofert (opcje mają stałe `id`, etykiety można zmieniać, a opcję można ukryć zamiast ją usuwać).

Nowy portal = nowa subdomena w cPanelu + `config.php` + import presetu + logo. Szacunkowo 1–2 dni zamiast nowego projektu.

---

## 7. Architektura techniczna

### 7.1 Stos
- **PHP 8.2+** (cPanel → MultiPHP), bez frameworka; własny mały rdzeń (router, widoki, walidator, DB, sesje, CSRF, mailer, kolejka). Bez Composera w produkcji — zależności (np. PHPMailer) dołączone w `lib/` z przypiętą wersją.
- **MySQL 8 / MariaDB 10.6+**, PDO + prepared statements, `utf8mb4_polish_ci`.
- **HTML renderowany po stronie serwera** + lekki **vanilla JS** (bez jQuery i Reacta) do: maski telefonu, sprawdzania NIP, uploadu z paskiem postępu, autosave, filtrów bez przeładowania (z działającym fallbackiem bez JS).
- **CSS**: własny system tokenów (spójny ze specyfikacją Website Builder 2026 z tego repo — WCAG 2.2 AA, mobile-first, `@layer`), motyw kolorów z presetu.
- **Obrazy**: GD/Imagick → WebP w 3 rozmiarach, metadane EXIF usuwane.
- **E-mail**: SMTP (skrzynka w domenie, SPF/DKIM/DMARC ustawione w cPanel) przez PHPMailer; szablony HTML w `views/emails/`.
- **Cron (cPanel)**: co minutę `bin/worker.php` (kolejka: e-maile, SMS, GetResponse, alerty), raz na dobę: kopia bazy, czyszczenie tokenów, raport.

### 7.2 Struktura katalogów

```
/home/<konto>/
├── portal/                      ← kod aplikacji (POZA public_html)
│   ├── app/
│   │   ├── Core/                ← Router, Request, Response, View, DB, Session, Csrf, Validator, RateLimiter, Mailer, Queue, Logger
│   │   ├── Auth/                ← rejestracja, logowanie, SMS OTP, reset, 2FA, remember-me
│   │   ├── Listings/            ← oferty, rewizje, filtry, wyszukiwanie, media, załączniki
│   │   ├── Moderation/
│   │   ├── Inquiries/
│   │   ├── Access/              ← poziomy L1–L3, prośby o dostęp, NDA
│   │   ├── Admin/
│   │   ├── Integrations/        ← SmsApi, Twilio, GetResponse, MfVatWhitelist, Webhook, Claude (AI)
│   │   └── Presets/             ← import/eksport presetów, edytor pól
│   ├── views/                   ← szablony PHP (layouty, partiale, e-maile)
│   ├── lang/pl.php              ← WSZYSTKIE teksty interfejsu
│   ├── presets/
│   ├── migrations/              ← 001_init.sql, 002_….sql (numerowane)
│   ├── bin/                     ← worker.php, migrate.php, backup.php, create-admin.php
│   ├── storage/                 ← uploads/, cache/, logs/ (niedostępne z WWW)
│   ├── lib/                     ← PHPMailer (przypięta wersja)
│   └── config.php               ← dane dostępowe, klucze (chmod 600, poza Git)
└── public_html/inwestycje/      ← document root subdomeny
    ├── index.php                ← jedyny punkt wejścia (front controller)
    ├── .htaccess                ← rewrite, nagłówki bezpieczeństwa, cache statyków
    └── assets/                  ← css, js, img, fonts
```

### 7.3 Adresy URL

```
/                         strona główna (hero, wyróżnione oferty, jak to działa, CTA rejestracji)
/oferty                   lista + filtry
/oferta/{slug}-{id}       szczegóły oferty
/dodaj-oferte             kreator (wymaga logowania)
/konto/...                panel użytkownika
/logowanie /rejestracja /reset-hasla /potwierdz-email/{token}
/zasady-wspolpracy /regulamin /polityka-prywatnosci /kontakt
/plik/{token}             pobranie załącznika (z kontrolą dostępu)
/admin/...                panel admina (osobna sesja, 2FA)
/sitemap.xml /robots.txt
```

### 7.4 Bezpieczeństwo (w rdzeniu, nie w wtyczce)
- Hasła: `password_hash()` Argon2id; sesje: `HttpOnly`, `Secure`, `SameSite=Lax`, regeneracja ID po logowaniu.
- CSRF token w każdym formularzu POST; nagłówki CSP, HSTS, X-Frame-Options, Referrer-Policy, Permissions-Policy.
- Wszystkie zapytania przez prepared statements; escapowanie w widokach (`e()`).
- Upload: biała lista MIME przez `finfo`, limit rozmiaru, losowe nazwy plików, przechowywanie poza WWW, blokada wykonywania PHP w `storage/`.
- Rate limiting (tabela `rate_limits`): logowanie, SMS, rejestracja, formularze.
- 2FA (TOTP) obowiązkowe dla admina i moderatora; panel `/admin` opcjonalnie dostępny tylko z określonych IP.
- Dziennik zdarzeń (`audit_log`): logowania, zmiany ról, moderacja, nadanie L3, pobrania dokumentów L3.
- RODO: rejestr zgód (treść, wersja, czas, IP), eksport i usunięcie danych użytkownika, retencja logów.

### 7.5 Niezawodność i utrzymanie
- Kod w Git (to repo albo osobne). Wdrożenie: **cPanel → Git™ Version Control → Deploy HEAD** (`.cpanel.yml`) albo `git pull` przez SSH. Wersjonowane migracje bazy.
- Środowisko testowe: subdomena `staging.` z kopią bazy (anonimizowaną). Zmiany najpierw tam.
- Kopie zapasowe: dzienny `mysqldump` + `storage/` (cron) → rotacja 14 dni + kopia poza serwerem (np. Google Drive / S3); test odtworzenia raz na kwartał.
- Monitoring: `/health` (DB, zapis plików, kolejka) + zewnętrzny uptime (UptimeRobot / Better Stack) z alertem SMS/e-mail. Log błędów PHP w `storage/logs/` + dzienny raport e-mail, gdy pojawią się błędy.
- Wydajność: cache listy ofert (plik/APCu, unieważniany przy publikacji), indeksy pod filtry, obrazy WebP + `loading=lazy`, < 100 KB JS. Cel: TTFB < 200 ms, LCP < 2 s na mobile.
- **Przed kampanią** (SMS/mailing): przycisk „tryb kampanii” w panelu — blokuje wdrożenia i podnosi limity cache. Lekcja z 10.03.2025.

---

## 8. Integracje

| Integracja | Cel | Uwagi |
|---|---|---|
| **SMSAPI.pl** | Kody weryfikacyjne, opcjonalnie powiadomienia | Konto już istnieje; nadawca alfanumeryczny (np. `FinDewelopera`), adapter wymienny na Twilio |
| **MF — Wykaz podatników VAT** | Dane firmy po NIP | Bez klucza API; fallback: wpis ręczny + flaga „do weryfikacji” |
| **GetResponse API v3** | Synchronizacja kontaktów (lista portalu, tagi: `inwestor`, `deweloper`, `zapytanie-{id}`), telefon w polu własnym | Kolejka z ponawianiem — awaria GR nie blokuje rejestracji |
| **Webhook ogólny** | Zapytania i rejestracje do dowolnego CRM / Make / Zapier | Podpis HMAC |
| **Zencal / kalendarz** | Przycisk „Umów konsultację” przy ofercie | Link konfigurowalny w presecie |
| **Google Analytics 4 / GTM / Meta Pixel** | Kampanie | Ładowane dopiero po zgodzie (baner cookies) |
| **Claude API** (opcjonalnie) | Asystent AI — patrz §9 | Klucz w `config.php`, limit kosztów miesięcznych |

---

## 9. AI w portalu (moduły opcjonalne)

Wspólnik Pawła opisał problem: dziesiątki projektów w folderach, na które nie ma czasu, bo z każdego trzeba zrobić ofertę i teaser. Tu AI daje największą oszczędność:

1. **Oferta z teasera / PDF** — użytkownik wgrywa teaser lub prospekt → AI wyciąga wartości do pól presetu (typ, lokalizacja, PUM, koszt, kapitał, etap, zabezpieczenia…) → użytkownik widzi wypełniony formularz z zaznaczonymi polami „do sprawdzenia” → zatwierdza. Oszczędza godzinę na ofertę (lipiec 2024: „wprowadziłem jedną ofertę przez ponad godzinę”).
2. **Redakcja opisu** — z notatek powstaje krótki opis (z limitem znaków) i pełny opis w ustalonym stylu; anonimizacja na L1 (bez nazwy dewelopera i dokładnego adresu).
3. **Wstępny scoring dla moderatora** — kompletność danych, niespójności (np. ROI vs koszt), sygnały ryzyka. Tylko jako podpowiedź — decyzję zawsze podejmuje człowiek.
4. **Dopasowania inwestor ↔ oferta** — na podstawie preferencji inwestora i parametrów oferty; cotygodniowy e-mail „3 nowe oferty dla Ciebie” (wersja 2).
5. **Generator teasera PDF** — z danych oferty powstaje jednostronicowy teaser w brandingu portalu (wersja 2).

Technicznie: wywołania Claude API z PHP (cURL), ustrukturyzowany JSON zgodny z definicjami pól presetu, kolejka (nie blokuje interfejsu), log kosztów, możliwość wyłączenia w ustawieniach.

---

## 10. Model danych (skrót)

Pełny schemat: [`migrations/001_init.sql`](migrations/001_init.sql).

```
users ─┬─< user_tokens (remember-me, reset, email-verify)
       ├─< sms_codes
       ├── companies (NIP, nazwa, adres, źródło: MF/ręcznie)
       ├─< listings ─┬─< listing_values ─< listing_value_options
       │             ├─< listing_revisions (oczekujące zmiany, JSON)
       │             ├─< media (zdjęcia)
       │             ├─< attachments (access_level 1–3)
       │             ├─< inquiries
       │             ├─< access_grants (L3 per użytkownik/oferta)
       │             └─< listing_stats (wyświetlenia dzienne)
       ├─< favorites, saved_searches
       └─< consents
categories ─< field_definitions ─< field_options
settings, pages, jobs (kolejka), rate_limits, audit_log
```

---

## 11. Plan wdrożenia

| Etap | Zakres | Szac. czas |
|---|---|---|
| **0. Warsztat** (1–2 h z Pawłem) | Zatwierdzenie presetu (lista pól z `presets/inwestycje-deweloperskie.json`), tekstów zasad L1–L3, brandingu | 1 dzień |
| **1. MVP** | Rdzeń, rejestracja (NIP + SMS + e-mail), logowanie, oferty + kreator, filtry, 3 poziomy dostępu, załączniki, moderacja + powiadomienia, zapytania, panel admina, strony treści, GetResponse, kopie/monitoring | 3–4 tyg. |
| **2. Migracja** | Eksport ofert, użytkowników i załączników z WP/WooCommerce (skrypt `bin/import-wp.php` — mapowanie meta pól na preset), przekierowania 301 ze starych adresów `/inwestycje/{slug}/` | 2–3 dni |
| **3. Testy** | Staging, 2–3 „testerów” Pawła, testy mobile (iOS/Android), test obciążeniowy przed kampanią | 1 tydz. |
| **4. Start** | Przełączenie subdomeny, zamrożenie starego WP (tylko do odczytu → potem wyłączenie) | 1 dzień |
| **5. Wersja 2** | AI (oferta z PDF), profile inwestorów i dopasowania, alerty, NDA online, mapa, statystyki dla autorów | +2–3 tyg. |
| **6. Wersja 3** | Abonamenty i wyróżnienia płatne (Stripe / Tpay / Przelewy24 + faktury przez API), wielojęzyczność (EN) | wg potrzeb |

Kryteria odbioru MVP (mierzalne): rejestracja na telefonie < 90 s bez pomocy; dodanie oferty z 3 PDF-ami < 10 min; 0 tekstów po angielsku; moderator dostaje e-mail < 1 min od wysłania oferty; LCP < 2 s (4G); kopia zapasowa przywrócona na stagingu.

---

## 12. Budowa z AI

Kod MVP powstaje etapami z pomocą Claude według promptu [`PROMPT-BUDOWY.md`](PROMPT-BUDOWY.md): każdy etap to osobna sesja z jasnym zakresem, testami i listą kontrolną. Dzięki temu:
- kod jest spójny (jeden zestaw konwencji),
- każdy etap da się przejrzeć i przetestować przed kolejnym,
- ten sam prompt z innym presetem buduje kolejny portal.

---

## 13. Decyzje do potwierdzenia z klientem

1. Czy autor oferty może sam nadawać dostęp L3 do swoich dokumentów, czy tylko admin?
2. Czy dokumenty L3 mają mieć znak wodny z danymi pobierającego (PDF watermark)?
3. Czy rejestracja wymaga NIP (tylko firmy), czy dopuszczamy osoby prywatne (inwestorzy indywidualni) z flagą „bez NIP”?
4. Logowanie kodem SMS przy każdym logowaniu czy tylko przy rejestracji / nowym urządzeniu?
5. Nazwa i domena: zostaje `inwestycje.finansedewelopera.pl` czy osobna marka (w mailach pojawiało się „ifd.pl”, logo „F&BC”)?
6. Regulamin i zasady współpracy — kto przygotowuje treść prawną?
