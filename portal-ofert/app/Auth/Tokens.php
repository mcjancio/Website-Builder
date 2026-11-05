<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\DB;

/**
 * Tokeny jednorazowe w tabeli user_tokens (remember-me, reset hasła, potwierdzenie e-mail).
 * Wzór selector/validator: w bazie tylko skrót walidatora; porównanie stałoczasowe.
 */
final class Tokens
{
    /** @return array{selector:string,validator:string} */
    public static function create(int $userId, string $type, int $ttlSeconds, string $userAgent = ''): array
    {
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        DB::insert('user_tokens', [
            'user_id' => $userId,
            'type' => $type,
            'selector' => $selector,
            'token_hash' => hash('sha256', $validator),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $ttlSeconds),
            'user_agent' => mb_substr($userAgent, 0, 255) ?: null,
        ]);
        return ['selector' => $selector, 'validator' => $validator];
    }

    /** Sprawdza token; jednorazowe (reset, e-mail) oznacza jako użyte. Zwraca ID użytkownika albo null. */
    public static function consume(string $selector, string $validator, string $type, bool $markUsed = true): ?int
    {
        if (!preg_match('/^[a-f0-9]{24}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $validator)) {
            return null;
        }
        $row = DB::fetch(
            'SELECT id, user_id, token_hash FROM user_tokens
             WHERE selector = ? AND type = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()',
            [$selector, $type]
        );
        if ($row === null || !hash_equals((string) $row['token_hash'], hash('sha256', $validator))) {
            return null;
        }
        if ($markUsed) {
            DB::query('UPDATE user_tokens SET used_at = UTC_TIMESTAMP() WHERE id = ?', [$row['id']]);
        }
        return (int) $row['user_id'];
    }

    public static function revokeAll(int $userId, string $type): void
    {
        DB::query('DELETE FROM user_tokens WHERE user_id = ? AND type = ?', [$userId, $type]);
    }

    public static function deleteBySelector(string $selector): void
    {
        DB::query('DELETE FROM user_tokens WHERE selector = ?', [$selector]);
    }
}
