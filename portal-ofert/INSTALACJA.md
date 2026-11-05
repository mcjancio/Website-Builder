# Instalacja na cPanelu (etap 1: rdzeń)

Docelowy układ na koncie hostingowym:

```
/home/KONTO/
├── portal/                      ← aplikacja (ten katalog repo, bez public/) — POZA public_html
│   ├── config.php               ← tworzysz ręcznie, chmod 600
│   └── storage/                 ← logi, cache, sesje, pliki (zapisywalne)
└── public_html/oferty/          ← document root subdomeny = zawartość public/
    ├── index.php  .htaccess  assets/
    └── .portal-root             ← ścieżka do /home/KONTO/portal (tworzy wdrożenie)
```

## 1. Przygotowanie w cPanelu

1. **Subdomena** (cPanel → Domeny): np. `oferty.twojadomena.pl`, document root `public_html/oferty`. W `.cpanel.yml` ustaw `WEB` na ten katalog.
2. **PHP** (MultiPHP Manager): 8.2 lub nowszy. Rozszerzenia (Select PHP Version): `pdo_mysql`, `mbstring`, `intl`, `curl`, `fileinfo`, `gd`, `sodium`.
3. **Baza** (Bazy danych MySQL): utwórz bazę i użytkownika, nadaj **wszystkie uprawnienia**. Kodowanie: `utf8mb4` / `utf8mb4_polish_ci`.
4. **SSL** (SSL/TLS Status): uruchom AutoSSL dla subdomeny.

## 2. Wgranie kodu

**Wariant A — Git (zalecany):** cPanel → Git™ Version Control → sklonuj repo → *Pull or Deploy* → **Deploy HEAD Commit**. Wdrożenie wykona `.cpanel.yml`: skopiuje aplikację do `~/portal`, `public/` do `~/public_html/inwestycje`, zapisze `.portal-root` i uruchomi migracje. Ścieżki zmienisz na górze `.cpanel.yml` (`APP`, `WEB`). Plik `.cpanel.yml` musi leżeć w **korzeniu repozytorium** — patrz komentarz w pliku.

**Wariant B — ręcznie (Menedżer plików / SFTP):** wgraj wszystko poza `public/` do `~/portal`, zawartość `public/` (z `.htaccess`) do `~/public_html/oferty`, a w tym katalogu utwórz plik `.portal-root` z jedną linią: `/home/KONTO/portal`.

## 3. Konfiguracja

```bash
cd ~/portal
cp config.example.php config.php
chmod 600 config.php
php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"   # wynik wklej do app.key
```

Uzupełnij w `config.php` co najmniej: `app.url`, `app.key`, sekcję `db`, `mail` (skrzynka w domenie portalu) i kanał kodów weryfikacyjnych:

- **SMS (SMSAPI)** — sekcja `sms`: token API i zatwierdzona nazwa nadawcy. Wystarczy, żeby ruszyć.
- **WhatsApp** — sekcja `whatsapp`, gdy konto Meta będzie gotowe: [`docs/WHATSAPP-KONFIGURACJA.md`](docs/WHATSAPP-KONFIGURACJA.md). Webhook ustaw na `https://<adres-portalu>/webhook/whatsapp`.
- Do testów bez kont: `otp.test_mode => true` — kody trafiają do `storage/logs` (na produkcji `/health` zgłosi błąd, żeby nie zostało włączone przez pomyłkę).

## 4. Instalator (Terminal w cPanelu albo SSH)

```bash
cd ~/portal
php bin/install.php --check                                           # tylko sprawdzenie serwera i bazy
php bin/install.php --preset=presets/<preset>.json                   # instalacja (lista i opis: presets/README.md)
```

Instalator sprawdzi wymagania i połączenie z bazą, wykona migracje, zaimportuje preset i zapyta o dane administratora. Można go uruchamiać wielokrotnie — niczego nie dubluje.

> Jeśli `php` w terminalu to inna wersja niż w MultiPHP, użyj pełnej ścieżki, np. `/opt/cpanel/ea-php83/root/usr/bin/php`.

## 5. Cron i monitoring

- **cPanel → Zadania Cron**, co minutę:
  ```
  * * * * * /usr/local/bin/php /home/KONTO/portal/bin/worker.php >/dev/null 2>&1
  ```
  Sprawdzenie: `php bin/worker.php --ping --verbose`, a po minucie `/health` musi pokazywać `"queue":"ok"`.
- **Monitoring** (UptimeRobot / Better Stack): `https://oferty.twojadomena.pl/health` — 200 = ok, 503 = problem (baza, zapis plików, niewykonane migracje, stojąca kolejka). Z `health.token` w configu: `/health?token=…` pokazuje też liczniki kolejki.

## 6. Pliki i limity PHP

Zdjęcia i dokumenty ofert leżą w `~/portal/storage/uploads` (poza `public_html`) i są wydawane przez PHP po sprawdzeniu uprawnień. Plik `public/.user.ini` podnosi limity PHP dla portalu (upload do 30 MB, 64 MB na żądanie). Jeśli hosting go ignoruje, ustaw te same wartości w **cPanel → MultiPHP INI Editor**. `php bin/install.php --check` ostrzega, gdy limit jest za niski.

Kopie zapasowe muszą obejmować bazę **i** katalog `storage/uploads` (etap 6: `bin/backup.php`).

## 7. SEO i integracje

- `https://<adres>/sitemap.xml` i `/robots.txt` generują się same (tylko treści publiczne) — zgłoś sitemapę w Google Search Console.
- Linki do przefiltrowanej listy działają w kampaniach, np. `/oferty?region=wielkopolskie&capital_needed_od=1000000`. Kombinacje filtrów mają `noindex`, żeby nie mnożyć duplikatów w Google.
- Zapytania o oferty mogą trafiać też do CRM / Make / Zapier: ustaw `webhook.url` i `webhook.secret` w `config.php` (POST JSON z podpisem `X-Portal-Signature`).

## 8. Polecenia na co dzień

| Polecenie | Do czego |
|---|---|
| `php bin/migrate.php --status` | Stan migracji |
| `php bin/migrate.php` | Wykonanie nowych migracji (robi to też wdrożenie z Git) |
| `php bin/import-preset.php presets/X.json --dry-run` | Podgląd zmian w kryteriach przed importem |
| `php bin/import-preset.php presets/X.json` | Import / aktualizacja kryteriów, kategorii, stron |
| `… --sync` | Ukrycie pól/opcji usuniętych z presetu (dane ofert zostają) |
| `… --overwrite` | Nadpisanie stron treści i ustawień zmienionych w panelu |
| `php bin/create-admin.php` | Nowy administrator albo nadanie roli istniejącemu kontu |
| `php bin/worker.php --verbose` | Ręczne przetworzenie kolejki |
| `php bin/moderate.php` | Oferty i zmiany czekające na akceptację (do czasu panelu w etapie 5) |
| `php bin/moderate.php --approve=12` | Akceptacja oferty #12 albo jej zmian — autor dostaje e-mail |
| `php bin/moderate.php --reject=12 --reason="…"` | Odrzucenie z uzasadnieniem widocznym dla autora |
| `php bin/access.php` | Prośby o dostęp do treści poufnych (poziom 3) |
| `php bin/access.php --grant=7 --days=90` | Nadanie dostępu (opcjonalnie z datą wygaśnięcia) — użytkownik dostaje e-mail |
| `php bin/access.php --deny=7` / `--revoke=7` | Odmowa / odebranie dostępu |

## 9. Lokalnie (dla programisty)

```bash
cp config.example.php config.php           # debug => true, hsts => false, dane lokalnej bazy
php bin/install.php --preset=presets/inwestycje-deweloperskie.json
php -d upload_max_filesize=30M -d post_max_size=64M -S 127.0.0.1:8080 -t public public/index.php

# testy (bez bazy / z bazą testową — UWAGA: testy z bazą czyszczą wskazaną bazę)
php tests/run.php
cp tests/config.testing.example.php config.testing.php
PORTAL_TEST_DB=1 php tests/run.php
```
