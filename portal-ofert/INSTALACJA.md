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

Uzupełnij w `config.php` co najmniej: `app.url`, `app.key`, sekcję `db`.

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

## 6. Polecenia na co dzień

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

## 7. Lokalnie (dla programisty)

```bash
cp config.example.php config.php           # debug => true, hsts => false, dane lokalnej bazy
php bin/install.php --preset=presets/inwestycje-deweloperskie.json
php -S 127.0.0.1:8080 -t public public/index.php

# testy (bez bazy / z bazą testową — UWAGA: testy z bazą czyszczą wskazaną bazę)
php tests/run.php
cp tests/config.testing.example.php config.testing.php
PORTAL_TEST_DB=1 php tests/run.php
```
