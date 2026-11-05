<?php

declare(strict_types=1);

/**
 * Tworzy konto administratora (albo nadaje rolę admin istniejącemu kontu).
 *
 *   php bin/create-admin.php
 *   php bin/create-admin.php --email=jan@firma.pl --first=Jan --last=Kowalski --phone=600100200
 *
 * Hasło: pytanie w terminalu (ukryte) albo zmienna PORTAL_ADMIN_PASSWORD (automatyzacja).
 * Konto admina ma od razu potwierdzony e-mail i telefon. 2FA włączysz po zalogowaniu (etap 2).
 */

require __DIR__ . '/_cli.php';

use App\Core\DB;
use App\Core\Validator;

[$opts] = cli_args($argv);

$input = [
    'email' => is_string($opts['email'] ?? null) ? $opts['email'] : ask('E-mail'),
    'first_name' => is_string($opts['first'] ?? null) ? $opts['first'] : ask('Imię'),
    'last_name' => is_string($opts['last'] ?? null) ? $opts['last'] : ask('Nazwisko'),
    'phone' => is_string($opts['phone'] ?? null) ? $opts['phone'] : ask('Telefon', '+48'),
];

$v = Validator::make($input, [
    'email' => 'required|email',
    'first_name' => 'required|maxlength:100',
    'last_name' => 'required|maxlength:100',
    'phone' => 'required|phone_pl',
], ['email' => 'E-mail', 'first_name' => 'Imię', 'last_name' => 'Nazwisko', 'phone' => 'Telefon']);
if ($v->fails()) {
    fail(implode("\n", $v->errors()));
}
$data = $v->validated();
$email = mb_strtolower($data['email']);

$existing = DB::fetch('SELECT id, role FROM users WHERE email = ?', [$email]);
if ($existing !== null) {
    if ($existing['role'] === 'admin') {
        ok("Konto {$email} już jest administratorem.");
        exit(0);
    }
    $answer = isset($opts['yes']) ? 't' : ask("Konto {$email} istnieje (rola: {$existing['role']}). Nadać rolę admin? (t/n)", 'n');
    if (strtolower($answer) !== 't') {
        fail('Przerwano.');
    }
    DB::update('users', ['role' => 'admin'], ['id' => $existing['id']]);
    DB::insert('audit_log', ['user_id' => null, 'action' => 'role.change', 'entity_type' => 'user',
        'entity_id' => $existing['id'], 'details' => json_encode(['to' => 'admin', 'via' => 'cli'])]);
    ok("Nadano rolę admin kontu {$email}.");
    exit(0);
}

if (DB::value('SELECT id FROM users WHERE phone_e164 = ?', [$data['phone']]) !== null) {
    fail("Numer {$data['phone']} jest już przypisany do innego konta.");
}

$password = getenv('PORTAL_ADMIN_PASSWORD') ?: '';
if ($password === '') {
    $password = ask_secret('Hasło (min. 12 znaków)');
    if ($password !== ask_secret('Powtórz hasło')) {
        fail('Hasła się różnią.');
    }
}
if (mb_strlen($password) < 12) {
    fail('Hasło administratora musi mieć co najmniej 12 znaków.');
}

$algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
$id = DB::insert('users', [
    'email' => $email,
    'password_hash' => password_hash($password, $algo),
    'first_name' => $data['first_name'],
    'last_name' => $data['last_name'],
    'phone_e164' => $data['phone'],
    'role' => 'admin',
    'email_verified_at' => gmdate('Y-m-d H:i:s'),
    'phone_verified_at' => gmdate('Y-m-d H:i:s'),
]);
DB::insert('audit_log', ['user_id' => $id, 'action' => 'user.create_admin', 'entity_type' => 'user',
    'entity_id' => $id, 'details' => json_encode(['via' => 'cli'])]);

ok("Utworzono administratora {$email} (ID {$id}).");
