# Portal Ofert — uniwersalny silnik portalu ofert

Uniwersalny portal ofert w **czystym PHP 8.2 + MySQL + HTML/CSS/vanilla JS** pod standardowy hosting **cPanel**. **Silnik nie zna żadnej branży** — kategorie, kryteria ofert, słownictwo („Oferta” / „Inwestycja” / „Ogłoszenie”), typy kont, kolory i zasady rejestracji pochodzą z presetu JSON. Ten sam kod obsłuży portal inwestycji, giełdę maszyn czy sprzedaż firm.

Punkt wyjścia: następca portalu **inwestycje.finansedewelopera.pl** (dziś WordPress + WooCommerce) — prostszy w utrzymaniu i odporny na „rozsypywanie się” po aktualizacjach wtyczek.

## Stan prac

| Etap | Zakres | Stan |
|---|---|---|
| 1 | Rdzeń (router, widoki, baza, sesje, CSRF, walidacja, limity, kolejka, logi), migracje, import presetów, instalator, `/health`, wdrożenie cPanel | ✅ gotowy, testy przechodzą |
| 2 | Konta: rejestracja z NIP (dane z MF), kod **WhatsApp → zapasowo SMS** (webhook Meta), potwierdzenie e-mail, logowanie z blokadą, „Zapamiętaj mnie”, reset hasła, 2FA (obowiązkowe dla admina) | ✅ gotowy, testy przechodzą |
| 3 | Oferty: kreator w 4 krokach z autozapisem, pola z presetu w typowanych kolumnach (kwoty przeliczane na PLN wg NBP), zdjęcia → WebP, do 25 dokumentów z poziomem dostępu, `Gate` (L1/L2/L3), wysyłka do moderacji, edycja opublikowanych przez rewizje, moderacja z CLI (`bin/moderate.php`) | ✅ gotowy, testy przechodzą |
| 4 | Publiczna lista ofert z filtrami generowanymi z presetu (czytelne adresy do kampanii, kwoty w PLN, sortowanie), strona oferty z ukrywaniem treści wg L1/L2/L3, osadzone filmy, zapytania (zgoda, pułapka na boty, e-mail do admina i autora, webhook do CRM), prośby o dostęp L3 (`bin/access.php`), statystyki wyświetleń, sitemap/robots/Open Graph | ✅ gotowy, testy przechodzą |
| 5 | Panel admina (`/admin`): pulpit z wykresem 30 dni, moderacja z porównaniem „było/jest”, oferty (wstrzymaj, zakończ, wyróżnij, archiwum), zapytania jako prosty CRM + CSV, dostępy L3 z datą wygaśnięcia, użytkownicy (blokada, rola, reset 2FA, CSV), **edytor kryteriów** (pola, opcje, kategorie, eksport do presetu), strony treści z wersjami, ustawienia i słownictwo, dziennik zdarzeń, tryb kampanii. „Moje konto”: profil, hasło i urządzenia, usunięcie konta (RODO), obserwowane, zapisane wyszukiwania z alertami e-mail, moje dostępy, statystyki ofert | ✅ gotowy, testy przechodzą |
| 6 | Kopie zapasowe (baza + pliki, rotacja, wysyłka do magazynu S3, odtworzenie), dzienny raport problemów e-mailem, import z WordPressa (konta, oferty, pola, zdjęcia, dokumenty, przekierowania 301, raport, zaproszenia), lista odbioru (`docs/ODBIOR.md`) | ✅ gotowy, testy przechodzą |
| 7 | Asystent AI (wersja 2) | — |

## Dokumenty

| Plik | Zawartość |
|---|---|
| [`KONCEPCJA.md`](KONCEPCJA.md) | Wnioski z obecnego portalu, cele, 3 poziomy dostępu, role, procesy, architektura, bezpieczeństwo, integracje, AI, plan wdrożenia |
| [`INSTALACJA.md`](INSTALACJA.md) | Instalacja i wdrożenie na cPanelu krok po kroku, polecenia CLI, uruchomienie lokalne i testy |
| [`PROMPT-BUDOWY.md`](PROMPT-BUDOWY.md) | Prompt dla Claude do budowy kolejnych etapów |
| [`docs/WHATSAPP-KONFIGURACJA.md`](docs/WHATSAPP-KONFIGURACJA.md) | Dla właściciela portalu: założenie konta WhatsApp Business API krok po kroku |
| [`docs/IMPORT-WP.md`](docs/IMPORT-WP.md) | Przeniesienie kont, ofert i plików ze starej strony WordPress, przekierowania 301 |
| [`docs/ODBIOR.md`](docs/ODBIOR.md) | Lista odbioru: kryteria z koncepcji, jak sprawdzone, co zostaje do zrobienia z klientem |
| [`migrations/001_init.sql`](migrations/001_init.sql) | Schemat bazy MySQL/MariaDB — pola ofert definiowane danymi, nie kodem |
| [`presets/README.md`](presets/README.md) | **Jak dostosować portal do dowolnych ofert** — opis wszystkich sekcji presetu |
| [`presets/szablon-nowego-portalu.json`](presets/szablon-nowego-portalu.json) | Punkt startowy nowego portalu |
| [`presets/inwestycje-deweloperskie.json`](presets/inwestycje-deweloperskie.json) | Inwestycje deweloperskie (31 kryteriów, 3 poziomy dostępu) |
| [`presets/sprzedaz-firm.json`](presets/sprzedaz-firm.json) | Firmy na sprzedaż / sukcesja |
| [`presets/maszyny-i-sprzet.json`](presets/maszyny-i-sprzet.json) | Używane maszyny — bez NIP, podkategorie, pola zależne od kategorii |

## Struktura kodu

```
app/
  bootstrap.php  helpers.php  routes.php  jobs.php
  Core/      Config, DB, Request, Response, Router, View, Session, Csrf, Validator, Cookies,
             RateLimiter, Queue, Migrator, Settings, Markdown, Lang, Logger, ErrorHandler,
             Http (klient API z trybem testowym), Crypto (sodium), Mailer (PHPMailer)
  Auth/      Auth (sesja + „Zapamiętaj mnie”), OtpService (WhatsApp → SMS), Totp (2FA), Tokens, Audit
  Access/    Gate — JEDYNE miejsce decyzji o dostępie L1/L2/L3
  Listings/  Fields, FieldInput (walidacja pól presetu), ValueStore, ListingService (cykl życia, rewizje, moderacja), MediaService,
             ListingQuery (wyszukiwanie i filtry), Display (formatowanie, osadzanie filmów), InquiryService, Stats
  Access/    + AccessService (prośby o dostęp L3)
  Integrations/  Otp/ (WhatsAppChannel, SmsApiChannel, LogChannel), MfVatWhitelist, GetResponse
  Http/      Controllers/ (Home, Page, Health, Theme, Account, Webhook, CompanyLookup,
             Auth/Register, Auth/Login, Auth/Password, Auth/EmailVerification)
             Middleware/ (StartSession, VerifyCsrf, LoadUser, RequireAuth, RequireGuest, RequireVerifiedEmail)
  Presets/   Importer (walidacja + idempotentny import presetu)
lib/         PHPMailer 6.9.3 (jedyna biblioteka zewnętrzna, przypięta wersja)
docs/        instrukcje dla właściciela portalu
bin/         install.php, migrate.php, import-preset.php, create-admin.php, worker.php, moderate.php, access.php
lang/pl.php  wszystkie teksty interfejsu
views/       layouts/, errors/, home.php, page.php
public/      index.php (jedyny punkt wejścia), .htaccess, assets/
storage/     logs, cache, uploads, sessions, backups (poza Git)
tests/       run.php + testy jednostkowe i integracyjne (prawdziwa baza)
```

**Nowy portal dla innego klienta** = subdomena w cPanelu + `config.php` + nowy plik presetu + logo.
