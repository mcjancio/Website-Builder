<?php

declare(strict_types=1);

namespace App\Integrations\Otp;

/** Kanał dostarczenia kodu weryfikacyjnego (WhatsApp, SMS, log testowy). */
interface OtpChannel
{
    /** Identyfikator zapisywany w otp_codes.channel: whatsapp | sms | log */
    public function name(): string;

    /** Czy kanał ma komplet danych w config.php (nieskonfigurowane kanały są pomijane). */
    public function isConfigured(): bool;

    /** @param string $phoneE164 np. +48600100200 */
    public function send(string $phoneE164, string $code): DeliveryResult;
}
