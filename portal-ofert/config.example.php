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
        'url' => 'https://inwestycje.example.pl',
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

    // ---- Uzupełniane w kolejnych etapach ----

    'mail' => [
        // Etap 2: skrzynka w domenie portalu (cPanel → Konta e-mail), SPF/DKIM włączone.
        'host' => 'mail.example.pl',
        'port' => 465,
        'encryption' => 'ssl',   // ssl (465) | tls (587)
        'user' => 'inwestycje@example.pl',
        'pass' => '',
        'from_email' => 'inwestycje@example.pl',
        'from_name' => 'Portal Ofert',
    ],

    'sms' => [
        // Etap 2: smsapi | twilio
        'provider' => 'smsapi',
        'token' => '',           // SMSAPI: token OAuth z panelu (Ustawienia API)
        'sender' => '',          // zarejestrowana nazwa nadawcy
        'test_mode' => false,    // true = SMS nie są wysyłane, kod trafia do logu
    ],

    'getresponse' => [
        'api_key' => '',
        'list_token' => '',
    ],

    'ai' => [
        // Etap 7 (opcjonalnie)
        'anthropic_api_key' => '',
        'model' => 'claude-sonnet-5-5',
    ],
];
