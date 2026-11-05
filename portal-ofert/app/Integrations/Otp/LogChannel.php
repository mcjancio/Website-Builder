<?php

declare(strict_types=1);

namespace App\Integrations\Otp;

use App\Core\Logger;

/**
 * Tryb testowy (otp.test_mode = true): kod NIE jest wysyłany, trafia do storage/logs.
 * Pozwala przejść rejestrację bez kont WhatsApp/SMSAPI — nigdy na produkcji.
 * Zapamiętuje kanał, który „udaje”, żeby interfejs pokazywał właściwy komunikat.
 */
final class LogChannel implements OtpChannel
{
    /** Ostatnie kody — do testów automatycznych. */
    public static array $sent = [];

    public function __construct(private string $imitates = 'sms')
    {
    }

    /** Nazwa udawanego kanału — cały przepływ (komunikaty, SMS zapasowy) działa jak na produkcji. */
    public function name(): string
    {
        return $this->imitates;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(string $phoneE164, string $code): DeliveryResult
    {
        self::$sent[] = ['phone' => $phoneE164, 'code' => $code, 'imitates' => $this->imitates];
        Logger::warning('TRYB TESTOWY: kod weryfikacyjny nie został wysłany', [
            'channel' => $this->imitates, 'phone' => $phoneE164, 'code' => $code,
        ]);
        return DeliveryResult::sent('log-' . bin2hex(random_bytes(6)));
    }
}
