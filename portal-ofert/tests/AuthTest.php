<?php

declare(strict_types=1);

use App\Auth\OtpService;
use App\Auth\Totp;
use App\Core\Crypto;
use App\Integrations\Otp\WhatsAppChannel;

return [
    'TOTP: wektory testowe RFC 6238 (SHA-1)' => function (): void {
        $secret = Totp::base32Encode('12345678901234567890');
        T::same('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secret);
        T::same('94287082', Totp::code($secret, 59, 8));
        T::same('07081804', Totp::code($secret, 1111111109, 8));
        T::same('89005924', Totp::code($secret, 1234567890, 8));
        T::same('287082', Totp::code($secret, 59), '6 cyfr');
        T::assert(Totp::verify($secret, '287 082', 59), 'spacje dozwolone');
        T::assert(Totp::verify($secret, Totp::code($secret, 1000 - 30), 1000), 'okno -30 s');
        T::assert(!Totp::verify($secret, Totp::code($secret, 1000 - 90), 1000), 'poza oknem');
        T::same('12345678901234567890', Totp::base32Decode($secret));
        T::assert(str_starts_with(Totp::uri($secret, 'jan@firma.pl', 'Portal Ofert'), 'otpauth://totp/Portal%20Ofert%3Ajan%40firma.pl?secret='));
        [$codes, $hashes] = Totp::recoveryCodes();
        T::same(8, count(array_unique($codes)));
        T::assert((bool) preg_match('/^[a-f0-9]{6}-[a-f0-9]{6}$/', $codes[0]));
        T::same(hash('sha256', $codes[3]), $hashes[3]);
    },

    'Szyfrowanie sekretów (sodium + app.key)' => function (): void {
        $c = Crypto::encrypt('123456');
        T::assert($c !== '123456' && strlen($c) > 24);
        T::same('123456', Crypto::decrypt($c));
        T::same(null, Crypto::decrypt(substr($c, 0, -1) . 'x'), 'zmieniony szyfrogram odrzucony');
        T::assert(Crypto::encrypt('a') !== Crypto::encrypt('a'), 'losowy nonce');
    },

    'Webhook WhatsApp: podpis X-Hub-Signature-256' => function (): void {
        $body = '{"entry":[]}';
        $good = 'sha256=' . hash_hmac('sha256', $body, 'test-app-secret');
        T::assert(WhatsAppChannel::validSignature($body, $good));
        T::assert(!WhatsAppChannel::validSignature($body . ' ', $good), 'zmieniona treść');
        T::assert(!WhatsAppChannel::validSignature($body, null), 'brak nagłówka');
        T::assert(!WhatsAppChannel::validSignature($body, 'sha1=' . hash_hmac('sha1', $body, 'test-app-secret')), 'stary SHA-1');
    },

    'Maskowanie numeru w komunikatach' => function (): void {
        T::same('+48 600 *** 200', OtpService::mask('+48600100200'));
        T::same('+49151 *** 678', OtpService::mask('+4915112345678'));
    },
];
