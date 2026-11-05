<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Szyfrowanie sekretów w bazie (sekret 2FA, kod OTP na czas ważności) — libsodium secretbox
 * z kluczem app.key. Bez klucza w config.php te funkcje odmawiają działania.
 */
final class Crypto
{
    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return $nonce . sodium_crypto_secretbox($plain, $nonce, self::key());
    }

    public static function decrypt(string $cipher): ?string
    {
        if (strlen($cipher) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $nonce = substr($cipher, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($cipher, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, self::key());
        return $plain === false ? null : $plain;
    }

    /** HMAC z kluczem aplikacji — do porównywanych w bazie skrótów kodów i tokenów. */
    public static function hmac(string $value): string
    {
        return hash_hmac('sha256', $value, self::key());
    }

    private static function key(): string
    {
        $key = base64_decode((string) Config::get('app.key', ''), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('Brak poprawnego app.key w config.php (32 bajty w base64).');
        }
        return $key;
    }
}
