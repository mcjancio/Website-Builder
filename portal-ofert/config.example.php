<?php

/**
 * Konfiguracja portalu.
 *
 * 1. Skopiuj ten plik jako config.php (w katalogu aplikacji, POZA public_html).
 * 2. Uzupełnij dane. Ustaw uprawnienia: chmod 600 config.php
 * 3. config.php NIGDY nie trafia do Git (.gitignore).
 *
 * Wartości dotyczące wyglądu i treści (nazwa, kolory, pola ofert) NIE są tutaj —
 * pochodzą z presetu (bin/import-preset.php) i panelu admina.
 */

return [
    'app' => [
        // production | staging | local
        'env' => 'production',
        // true TYLKO lokalnie: pokazuje szczegóły błędów na stronie 500.
        'debug' => false,
        // Pełny adres portalu, bez ukośnika na końcu (linki w e-mailach, sitemap).
        'url' => 'https://portal.example.pl',
        // Gdy portal działa w podkatalogu domeny (np. /portal) — inaczej zostaw pusty.
        'base_path' => '',
        // Strefa czasowa do wyświetlania dat (w bazie zawsze UTC).
        'timezone' => 'Europe/Warsaw',
        // Plik tekstów: lang/<locale>.php
        'locale' => 'pl',
        // debug | info | warning | error
        'log_level' => 'info',
        // Klucz do szyfrowania sekretów w bazie (np. 2FA). Wygeneruj: php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
        // Zmiana klucza unieważnia zaszyfrowane dane — trzymaj kopię w menedżerze haseł.
        'key' => '',
        // Nagłówek HSTS (wymusza HTTPS w przeglądarce na rok). Wyłącz tylko na czas testów bez SSL.
        'hsts' => true,
    ],

    'db' => [
        // cPanel → Bazy danych MySQL: nazwy mają prefiks konta, np. konto_portal
        'host' => 'localhost',
        'port' => 3306,
        'socket' => '',          // opcjonalnie, np. /var/lib/mysql/mysql.sock
        'name' => 'konto_portal',
        'user' => 'konto_portal',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],

    'session' => [
        'name' => 'portal_sid',
        'lifetime' => 7200,      // sekundy bezczynności
    ],

    'security' => [
        // IP serwerów proxy, którym ufamy w nagłówku X-Forwarded-For (np. gdy domena jest za Cloudflare).
        'trusted_proxies' => [],
    ],

    'health' => [
        // Token do /health?token=… — pokazuje liczniki kolejki. Pusty = tylko status ok/error.
        'token' => '',
    ],

    'backup' => [
        // bin/backup.php (cron raz dziennie): baza + pliki ofert do storage/backups.
        'keep_days' => 14,           // ile dni trzymać kopie na serwerze
        'include_files' => true,     // także zdjęcia i dokumenty (storage/uploads)
        // Kopia POZA serwerem (zalecane): magazyn zgodny z S3 — Backblaze B2, Wasabi, Cloudflare R2, AWS…
        // Klucz z uprawnieniem TYLKO do zapisu w tym jednym buckecie. Pusty endpoint = bez wysyłki.
        's3' => [
            'endpoint' => '',        // np. https://s3.eu-central-003.backblazeb2.com
            'region' => '',          // np. eu-central-003
            'bucket' => '',
            'key' => '',
            'secret' => '',
            'prefix' => 'portal',    // katalog w buckecie
        ],
    ],

    // Import z dotychczasowego WordPressa (etap 6, jednorazowo): bin/import-wp.php, opis w docs/IMPORT-WP.md
    'import_wp' => [
        'host' => 'localhost',
        'port' => 3306,
        'socket' => '',
        'name' => '',                // baza WordPressa (cPanel → Bazy danych MySQL), wystarczy użytkownik tylko do odczytu
        'user' => '',
        'pass' => '',
        'prefix' => 'wp_',
        'uploads' => '',             // katalog wp-content/uploads starej strony, np. /home/KONTO/public_html/wp-content/uploads
        'old_url' => '',             // adres starej strony, np. https://inwestycje.example.pl
    ],

    // ---- Konta i weryfikacja (etap 2) ----

    'mail' => [
        // smtp (produkcja) | log (lokalnie: treść e-maili trafia do storage/logs zamiast do skrzynki)
        'driver' => 'smtp',
        // Skrzynka w domenie portalu (cPanel → Konta e-mail). Włącz SPF i DKIM (cPanel → Email Deliverability).
        'host' => 'mail.example.pl',
        'port' => 465,
        'encryption' => 'ssl',   // ssl (465) | tls (587)
        'user' => 'portal@example.pl',
        'pass' => '',
        'from_email' => 'portal@example.pl',
        'from_name' => 'Portal Ofert',
    ],

    'otp' => [
        // Kolejność kanałów kodu weryfikacyjnego. Pusty/null = z presetu (registration.otp_channels),
        // domyślnie ['whatsapp', 'sms']. Kanały bez danych dostępowych są pomijane automatycznie.
        'channels' => null,
        // true = kody NIE są wysyłane, tylko zapisywane w storage/logs (testy bez kont WhatsApp/SMSAPI).
        // NIGDY na produkcji — instalator i /health ostrzegają.
        'test_mode' => false,
        'ttl' => 600,                 // ważność kodu (s)
        'max_attempts' => 5,          // błędne próby na kod
        'resend_after' => 30,         // po ilu sekundach można poprosić o SMS zamiast WhatsAppa
        'limit_per_phone_hour' => 5,
        'limit_per_ip_day' => 20,
    ],

    'whatsapp' => [
        // WhatsApp Business Platform (Cloud API). Krok po kroku: docs/WHATSAPP-KONFIGURACJA.md
        'phone_number_id' => '',     // „Phone number ID” z WhatsApp Manager / App Dashboard
        'access_token' => '',        // stały token użytkownika systemowego (System User)
        'template' => '',            // nazwa zatwierdzonego szablonu kategorii Authentication
        'language' => 'pl',          // język szablonu
        'graph_version' => 'v23.0',
        'app_secret' => '',          // App Secret aplikacji Meta — podpis webhooka
        'verify_token' => '',        // dowolny losowy ciąg, ten sam wpisany w konfiguracji webhooka
    ],

    'sms' => [
        // SMSAPI.pl — token OAuth z panelu (Ustawienia API → Tokeny API), uprawnienie „SMS”.
        'provider' => 'smsapi',
        'token' => '',
        'sender' => '',              // zarejestrowana i zatwierdzona nazwa nadawcy (pole „from”)
        'test' => false,             // true = SMSAPI przyjmuje żądanie, ale nie wysyła SMS-a
    ],

    'webhook' => [
        // Opcjonalnie: zapytania o oferty wysyłane też do CRM / Make / Zapier (POST JSON).
        // Nagłówek X-Portal-Signature: sha256=HMAC(treść, secret) — sprawdź go po stronie odbiorcy.
        'url' => '',
        'secret' => '',
    ],

    'getresponse' => [
        // Kontakt trafia do listy TYLKO przy zgodzie marketingowej z formularza rejestracji.
        'api_key' => '',
        'list_token' => '',          // ID listy (campaignId)
        'phone_field_id' => '',      // ID pola własnego na telefon (GetResponse → Pola własne)
        'tags' => [],                // typ konta z presetu => ID tagu GR, np. ['investor' => 'AbC1']
    ],

    'ai' => [
        // Asystent AI (etap 7): oferta z PDF, redakcja opisu, podpowiedzi dla moderatora.
        // Włączenie i miesięczny budżet: panel → Ustawienia (albo preset → ai). Bez klucza moduł jest niewidoczny.
        // Klucz: platform.claude.com → API Keys. Najlepiej osobny „workspace” z limitem wydatków.
        'anthropic_api_key' => '',
        'model' => 'claude-sonnet-5-5',
        'effort' => 'medium',        // low | medium | high — dokładność kontra koszt
        'timeout' => 180,            // sekundy na jedno wywołanie (działa w tle, w kolejce)
        // 'prices' => ['claude-sonnet-5-5' => [2.00, 10.00]],  // USD / 1 mln tokenów (wejście, wyjście), gdy cennik się zmieni
    ],
];
