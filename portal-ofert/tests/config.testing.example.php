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
];
