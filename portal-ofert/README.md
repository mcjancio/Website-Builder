# Portal Ofert — uniwersalny silnik portalu ofert

Uniwersalny portal ofert w **czystym PHP 8.2 + MySQL + HTML/CSS/vanilla JS** pod standardowy hosting **cPanel**. **Silnik nie zna żadnej branży** — kategorie, kryteria ofert, słownictwo („Oferta” / „Inwestycja” / „Ogłoszenie”), typy kont, kolory i zasady rejestracji pochodzą z presetu JSON. Ten sam kod obsłuży portal inwestycji, giełdę maszyn czy sprzedaż firm.

Punkt wyjścia: następca portalu **inwestycje.finansedewelopera.pl** (dziś WordPress + WooCommerce) — prostszy w utrzymaniu i odporny na „rozsypywanie się” po aktualizacjach wtyczek.

## Stan prac

| Etap | Zakres | Stan |
|---|---|---|
| 1 | Rdzeń (router, widoki, baza, sesje, CSRF, walidacja, limity, kolejka, logi), migracje, import presetów, instalator, `/health`, wdrożenie cPanel | ✅ gotowy, testy przechodzą |
| 2 | Konta: rejestracja NIP + SMS + e-mail, logowanie, 2FA | — |
| 3–7 | Oferty, front z filtrami, panele, produkcja, AI | — |

## Dokumenty

| Plik | Zawartość |
|---|---|
| [`KONCEPCJA.md`](KONCEPCJA.md) | Wnioski z obecnego portalu, cele, 3 poziomy dostępu, role, procesy, architektura, bezpieczeństwo, integracje, AI, plan wdrożenia |
| [`INSTALACJA.md`](INSTALACJA.md) | Instalacja i wdrożenie na cPanelu krok po kroku, polecenia CLI, uruchomienie lokalne i testy |
| [`PROMPT-BUDOWY.md`](PROMPT-BUDOWY.md) | Prompt dla Claude do budowy kolejnych etapów |
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
  Core/      Config, DB, Request, Response, Router, View, Session, Csrf, Validator,
             RateLimiter, Queue, Migrator, Settings, Markdown, Lang, Logger, ErrorHandler
  Http/      Controllers/ (Home, Page, Health, Theme), Middleware/ (StartSession, VerifyCsrf)
  Presets/   Importer (walidacja + idempotentny import presetu)
bin/         install.php, migrate.php, import-preset.php, create-admin.php, worker.php
lang/pl.php  wszystkie teksty interfejsu
views/       layouts/, errors/, home.php, page.php
public/      index.php (jedyny punkt wejścia), .htaccess, assets/
storage/     logs, cache, uploads, sessions, backups (poza Git)
tests/       run.php + testy jednostkowe i integracyjne (prawdziwa baza)
```

**Nowy portal dla innego klienta** = subdomena w cPanelu + `config.php` + nowy plik presetu + logo.
