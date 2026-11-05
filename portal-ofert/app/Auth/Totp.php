<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * TOTP (RFC 6238) — kody z aplikacji Google Authenticator, Microsoft Authenticator, 1Password itd.
 * 6 cyfr, okres 30 s, SHA-1 (standard obsługiwany przez wszystkie aplikacje).
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function code(string $secretBase32, ?int $time = null, int $digits = 6): string
    {
        $counter = intdiv($time ?? time(), 30);
        $hash = hash_hmac('sha1', pack('J', $counter), self::base32Decode($secretBase32), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
        return str_pad((string) ($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /** Akceptuje kod z bieżącego okna ±1 (zegar telefonu może się spieszyć/spóźniać o 30 s). */
    public static function verify(string $secretBase32, string $code, ?int $time = null): bool
    {
        $code = (string) preg_replace('/\D+/', '', $code);
        if (strlen($code) !== 6) {
            return false;
        }
        $time ??= time();
        foreach ([-30, 0, 30] as $drift) {
            if (hash_equals(self::code($secretBase32, $time + $drift), $code)) {
                return true;
            }
        }
        return false;
    }

    /** Adres otpauth:// — aplikacje uwierzytelniające przyjmują go z kodu QR lub linku. */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
            . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
    }

    /** @return array{0:string[],1:string[]} [kody do pokazania, skróty do zapisu] */
    public static function recoveryCodes(int $count = 8): array
    {
        $plain = [];
        $hashes = [];
        for ($i = 0; $i < $count; $i++) {
            $c = strtolower(bin2hex(random_bytes(3)) . '-' . bin2hex(random_bytes(3)));
            $plain[] = $c;
            $hashes[] = hash('sha256', $c);
        }
        return [$plain, $hashes];
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $b) {
            $bits .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function base32Decode(string $b32): string
    {
        $b32 = strtoupper((string) preg_replace('/[\s=]+/', '', $b32));
        $bits = '';
        foreach (str_split($b32) as $ch) {
            $pos = strpos(self::ALPHABET, $ch);
            if ($pos === false) {
                throw new \InvalidArgumentException('Niepoprawny znak w sekrecie Base32.');
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }
}
