# Odbiór portalu — lista kontrolna

Stan na 2026-11-05 (etap 6). Każdy punkt ma opis, **jak go sprawdzono**. Rzeczy, których nie da się sprawdzić bez klienta, prawdziwych telefonów i docelowego hostingu, są oznaczone ⏳ i opisane w części B.

Oznaczenia: ✅ sprawdzone · ⏳ do sprawdzenia z klientem lub na docelowym serwerze.

Testy automatyczne: `PORTAL_DB_SOCKET=… PORTAL_TEST_DB=1 php tests/run.php` → **97 testów, 0 błędów** (rdzeń, konta, oferty, lista, panel, konto, kopie, import, asystent AI, regresje z przeglądu bezpieczeństwa).

## A. Kryteria odbioru MVP (KONCEPCJA §11)

| # | Kryterium | Stan | Jak sprawdzono / co zostaje |
|---|---|---|---|
| 1 | Rejestracja na telefonie < 90 s bez pomocy | ✅ / ⏳ | Jeden ekran: typ konta, imię, nazwisko, e-mail, telefon, NIP, hasło (×2) i zgody; nazwa firmy uzupełnia się sama z NIP, kod przychodzi na WhatsApp z przyciskiem „kopiuj”, a SMS jest zapasowo. Przejście w Chromium (emulacja telefonu) działa bez błędów (etap 2). **Do zrobienia:** 2–3 osoby od klienta rejestrują się na własnych telefonach (iOS i Android) z prawdziwym WhatsAppem; mierzymy czas |
| 2 | Dodanie oferty z 3 PDF-ami < 10 min | ✅ / ⏳ | Kreator w 4 krokach z autozapisem, przeciąganie wielu plików z paskiem postępu, poziom dostępu dla każdego pliku (testy `ListingsDbTest`). **Do zrobienia:** pomiar z deweloperem na prawdziwej ofercie |
| 3 | 0 tekstów po angielsku | ✅ | Test `LangTest` → „zero tekstów po angielsku”: widoki nie mają tekstu poza `__()`, a teksty w `lang/pl.php` nie zawierają angielskich słów. Dodatkowo test sprawdza, że każdy użyty klucz istnieje. Wyjątek: ukryte pole-pułapka na boty |
| 4 | Moderator dostaje e-mail < 1 min od wysłania oferty | ✅ / ⏳ | Wysłanie oferty od razu kolejkuje e-mail do każdego moderatora i admina (testy `ListingsDbTest`). Worker z crona co minutę wysyła go w ciągu ≤ 60 s. **Do zrobienia:** cron i SMTP na serwerze; test: `php bin/worker.php --ping --verbose` oraz jedna oferta testowa |
| 5 | LCP < 2 s na 4G | ✅ / ⏳ | Pomiar w Chromium (Pixel 7, „Fast 4G”: 150 ms RTT, 9 Mb/s, procesor 4× wolniejszy), serwer lokalny: strona główna **0,88 s**, lista ofert **0,58 s**, oferta **0,56 s**, rejestracja **0,49 s**. Waga stron 49–67 KB, JS 13 KB. **Do zrobienia:** powtórzyć na hostingu (dojdzie czas odpowiedzi serwera i prawdziwe zdjęcia), np. PageSpeed Insights |
| 6 | Kopia zapasowa przywrócona na stagingu | ✅ / ⏳ | Test `MaintenanceDbTest` → „kopia zapasowa”: zrzut, usunięcie danych i tabeli, odtworzenie; dane (także binarne i polskie znaki) wracają. `bin/backup.php` i `bin/restore.php` uruchomione na bazie testowej e2e. **Do zrobienia:** pierwsze odtworzenie produkcyjnej kopii na stagingu (procedura w części C) |

## B. Wnioski ze starego portalu (KONCEPCJA §1) — czy problem nie wróci

| # | Problem w WordPressie | Rozwiązanie | Sprawdzenie |
|---|---|---|---|
| 1 | Strona „rozsypała się” po automatycznej aktualizacji wtyczki, akurat po wysyłce SMS | Brak wtyczek i automatycznych aktualizacji. Wdrożenie tylko przez Git. **Tryb kampanii** blokuje wdrożenia | ✅ `AdminDbTest` (tryb kampanii), blokada w `.cpanel.yml` |
| 2 | Awarie i „mulenie” | Lekka aplikacja, indeksy pod filtry, strony < 70 KB | ✅ pomiar z punktu A5; `/health` do monitoringu |
| 3 | Mieszanka angielskiego i polskiego | Wszystkie teksty w `lang/pl.php` | ✅ A3 |
| 4 | Fejkowe konta | E-mail (link) + telefon (kod WhatsApp/SMS) + NIP z sumą kontrolną i danymi z MF | ✅ `AuthDbTest` |
| 5 | Mylące komunikaty przy rejestracji | Jeden prosty proces, komunikaty przy polach, ten sam kod przez WhatsApp i SMS | ✅ `AuthDbTest`, przejście w Chromium (etap 2) |
| 6 | Treść znikała albo była widoczna dla wszystkich | Poziom dostępu każdego pola i pliku sprawdzany w jednym miejscu (`Gate`) | ✅ `PublicDbTest` (gość / zalogowany / dostęp poufny), wstrzymane oferty ukryte (`AdminDbTest`) |
| 7 | Brak powiadomienia o ofercie do zatwierdzenia | E-mail do zespołu przy każdej nowej ofercie i zmianie, kolejka w panelu | ✅ A4, `AdminDbTest` |
| 8 | Jeden PDF na ofertę | Do 25 dokumentów, każdy z własnym poziomem dostępu | ✅ `ListingsDbTest` |
| 9 | Kryteria „zamrożone” | Edytor kryteriów w panelu, eksport do presetu | ✅ `AdminDbTest` (edytor kryteriów) |
| 10 | Tylko PLN, brak dat z przeszłości | Kwoty w PLN/EUR/USD/GBP przeliczane wg NBP, daty bez ograniczeń + flaga „w toku” | ✅ `ListingsDbTest`, `PublicDbTest` |
| 11 | Ucięte tytuły, rozjazdy na telefonie, zgoda niewymagana | Własny CSS mobile-first, walidacja zawsze na serwerze | ✅ Chromium 390 px bez poziomego przewijania (etapy 3–5), testy zgód |
| 12 | Telefon nie trafiał do GetResponse | Jeden moduł integracji z kolejką i ponawianiem | ✅ `IntegrationDbTest` (telefon w polu własnym, tag typu konta, ponawianie przy błędzie serwera); wysyłka tylko przy zgodzie marketingowej |
| 13 | Ataki, alerty Wordfence | Brak `/wp-admin`, CSRF/CSP/limity w rdzeniu, 2FA dla zespołu, dziennik zdarzeń | ✅ `AuthDbTest`, `AdminDbTest`; nagłówki CSP, `X-Frame-Options`, `Permissions-Policy` w każdej odpowiedzi |

## C. Przed startem — na docelowym serwerze

- [ ] `config.php`: `app.env = production`, `debug = false`, wygenerowany `app.key` (kopia w menedżerze haseł), `otp.test_mode = false`.
- [ ] SSL działa, HSTS włączony (`app.hsts`).
- [ ] Cron: `bin/worker.php` co minutę i `bin/backup.php` raz dziennie (INSTALACJA §5).
- [ ] Kopia poza serwerem: `backup.s3` + reguła cyklu życia w buckecie; po pierwszym `php bin/backup.php` pliki widoczne w buckecie.
- [ ] Poczta: SPF i DKIM (cPanel → Email Deliverability); e-mail testowy nie trafia do spamu (np. mail-tester.com ≥ 9/10).
- [ ] WhatsApp: zatwierdzony szablon Authentication, webhook z `app_secret` (docs/WHATSAPP-KONFIGURACJA.md); SMSAPI: zatwierdzona nazwa nadawcy. Test na prawdziwym numerze: WhatsApp, potem SMS po wyłączeniu internetu w telefonie.
- [ ] Monitoring `/health` (UptimeRobot / Better Stack) z alertem SMS/e-mail.
- [ ] Konta zespołu z 2FA (`php bin/create-admin.php`), kody awaryjne zapisane.
- [ ] Preset zaimportowany; treści stron (regulamin, polityka prywatności, zasady współpracy) wpisane w panelu — **treść prawną dostarcza klient**.
- [ ] GetResponse: lista, pole telefonu, tagi typów kont; test rejestracji ze zgodą marketingową.
- [ ] Import ze starej strony: próba (`--dry-run`), raport przejrzany z klientem, import, przekierowania 301 wklejone, kilka starych adresów sprawdzonych (docs/IMPORT-WP.md).
- [ ] **Test odtworzenia** na stagingu: skopiuj ostatnie `baza-…sql.gz` i `pliki-….tar.gz`, potem `php bin/restore.php … --yes`, zaloguj się i otwórz kilka ofert z dokumentami. Zapisz datę testu; kolejny za 3 miesiące.
- [ ] Testerzy klienta (2–3 osoby): rejestracja i dodanie oferty na telefonie — punkty A1 i A2.
- [ ] Pomiar na hostingu: PageSpeed Insights dla strony głównej, listy i oferty (A5).
- [ ] (Opcjonalnie) Asystent AI: klucz API, włączenie i budżet w panelu, wpis o Anthropic w polityce prywatności; próba na prawdziwym teaserze PDF i ocena, ile pól wypełnił poprawnie (INSTALACJA §7).

## D. Decyzje do potwierdzenia z klientem (KONCEPCJA §13)

Silnik obsługuje każdą z opcji; potrzebna jest tylko decyzja:

1. Kto nadaje dostęp do treści poufnych — tylko zespół (obecnie) czy także autor oferty (kolumna `l3_self_grant` jest przygotowana)?
2. Znak wodny z danymi pobierającego na dokumentach poufnych — v2.
3. NIP obowiązkowy czy dopuszczamy osoby prywatne — ustawienie w presecie (`registration`).
4. Kod przy każdym logowaniu czy tylko przy rejestracji — obecnie przy rejestracji, a dla zespołu 2FA przy każdym logowaniu.
5. Domena i marka — ustawienia w panelu (nazwa, kolor) i `app.url`.
6. Treść regulaminu i polityki prywatności — po stronie klienta (wpisywane w panelu → Strony; każda zmiana tworzy nową wersję).

## E. Przegląd bezpieczeństwa przed startem (2026-11-05)

Trzy niezależne przeglądy kodu objęły: logowanie i uprawnienia; widoki, wejście danych i pliki; kopie, import, asystenta AI i wdrożenie. **Nie znaleziono podatności krytycznej**, a XSS, SQL injection i przejęcia cudzych ofert (IDOR) nie wykryto. Poprawki (migracja `006_security_hardening`, testy `SecurityDbTest`):

- **Sesje:** zmiana i reset hasła, „wyloguj wszędzie”, blokada konta, zmiana roli i reset 2FA kończą wszystkie otwarte sesje, a nie tylko „Zapamiętaj mnie”.
- **Uprawnienia zespołu i 2FA:**
  - admin lub moderator ma uprawnienia zespołu dopiero po włączeniu 2FA;
  - kod 2FA działa tylko raz;
  - krok 2FA wygasa po 10 minutach.
- **Logowanie i ponowne uwierzytelnienie:**
  - blokada po 5 błędnych hasłach dotyczy pary konto + adres IP, więc nikt nie zablokuje cudzego konta ze swojego adresu;
  - odpowiedź jest taka sama dla kont istniejących i nieistniejących;
  - usunięcie konta i wyłączenie 2FA mają limit prób.
- **Moderacja:**
  - ofertę wstrzymaną przez moderację autor nie może sam wznowić;
  - nikt nie moderuje własnej oferty.
- **Dane niepubliczne:**
  - miasto i województwo z pola niepublicznego nie trafiają do nagłówka ani kafelków;
  - zdjęcia publiczne są cache'owane 5 minut zamiast doby.
- **Odporność na błędy i ataki:**
  - Markdown jest odporny na znaczniki wewnętrzne (wcześniej błąd 500) i linki `/\host`;
  - przekierowania odrzucają `/\host` oraz znaki nowej linii;
  - limit wielkości zdjęcia dopasowany do pamięci PHP;
  - stronicowanie w panelu jest ograniczone, więc za duży numer strony nie daje błędu.
- **Kopie zapasowe:**
  - zrzut z migawki transakcji;
  - każdy zapis jest sprawdzany, a ucięta kopia nie zastępuje dobrych;
  - odtworzenie najpierw weryfikuje cały plik (ucięty zrzut nie zmienia bazy) i usuwa tabele spoza kopii, więc migracje po odtworzeniu przechodzą;
  - jedna instrukcja SQL ma najwyżej ~1 MB;
  - archiwum plików jest odporne na pliki zmieniane w trakcie.
- **Wdrożenie:** przed migracjami jest robiona kopia bazy, a błąd migracji przerywa wdrożenie zamiast udawać sukces. Ochrona przed pustą zmienną `$APP` przy `rsync --delete`.
- **Asystent AI:**
  - zlecenie jest przejmowane atomowo;
  - porzucone zlecenia są kończone po 15 minutach;
  - limit pliku PDF to 20 MB;
  - dane z `ai.anonymize_level1` są usuwane także z pełnego opisu i z propozycji krótkiego opisu;
  - model dostaje instrukcję, by ignorować polecenia z treści dokumentu.
- **Import z WordPressa:** każda oferta jest importowana w osobnej transakcji, a błąd jednej trafia do raportu bez tworzenia duplikatów.
- **Raport dzienny:** liczy tylko nieudane zadania z ostatniej doby.

Pozostaje do sprawdzenia na serwerze:
- **PDF w Chrome:** czy plik otwiera się w przeglądarce. Nagłówki zostały poprawione (bez `sandbox` dla PDF).
- **cPanel przy aktywnym trybie kampanii:** czy `exit 1` faktycznie przerywa „Deploy”.
- **Kolejność wdrożenia:** migracje uruchamiają się po skopiowaniu kodu, więc przez kilka sekund nowy kod działa na starej bazie. Przy dużych zmianach schematu warto włączyć tryb kampanii i wdrażać poza godzinami ruchu.
- **Pierwsza konfiguracja 2FA:** nowo mianowany moderator konfiguruje 2FA samym hasłem. Mianuj moderatorów tylko z kont, których właściciela znasz.
