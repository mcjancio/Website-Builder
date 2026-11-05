<?php

/**
 * Konfiguracja testów. Skopiuj jako config.testing.php i wskaż PUSTĄ bazę testową —
 * testy integracyjne (PORTAL_TEST_DB=1) usuwają z niej wszystkie tabele.
 */

return [
    'app' => [
        'env' => 'testing',
        'debug' => true,
        'url' => 'http://localhost:8080',
        'base_path' => '',
        'locale' => 'pl',
        'log_level' => 'error',
        'key' => base64_encode(str_repeat('k', 32)),
    ],
    'db' => [
        'host' => getenv('PORTAL_DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('PORTAL_DB_PORT') ?: 3306),
        'socket' => getenv('PORTAL_DB_SOCKET') ?: '',
        'name' => getenv('PORTAL_DB_NAME') ?: 'portal_test',
        'user' => getenv('PORTAL_DB_USER') ?: 'portal',
        'pass' => getenv('PORTAL_DB_PASS') ?: 'portal',
        'charset' => 'utf8mb4',
    ],
    'session' => ['name' => 'portal_test', 'lifetime' => 7200],
    'security' => ['trusted_proxies' => []],
    'health' => ['token' => 'test-token'],
    // Integracje z prawdziwymi kanałami, ale sieć podmieniona w testach (Http::fake) — nic nie wychodzi na zewnątrz.
    'mail' => ['driver' => 'log', 'from_email' => 'portal@example.test', 'from_name' => 'Portal testowy'],
    'otp' => ['channels' => null, 'test_mode' => false, 'ttl' => 600, 'max_attempts' => 5, 'resend_after' => 30,
        'limit_per_phone_hour' => 5, 'limit_per_ip_day' => 50],
    'whatsapp' => ['phone_number_id' => '1234567890', 'access_token' => 'test-wa-token', 'template' => 'kod_weryfikacyjny',
        'language' => 'pl', 'graph_version' => 'v23.0', 'app_secret' => 'test-app-secret', 'verify_token' => 'test-verify'],
    'sms' => ['provider' => 'smsapi', 'token' => 'test-sms-token', 'sender' => 'Portal', 'test' => false],
    'getresponse' => ['api_key' => '', 'list_token' => ''],
];
