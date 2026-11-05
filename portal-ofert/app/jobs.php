<?php

declare(strict_types=1);

/**
 * Rejestr obsługi zadań kolejki: typ => callable(array $payload): void.
 * Wyjątek w handlerze = próba nieudana (ponowienie z opóźnieniem).
 */

use App\Auth\OtpService;
use App\Core\Logger;
use App\Core\Mailer;
use App\Integrations\GetResponse;

return [
    // Zadanie testowe — sprawdza, czy cron workera działa: php bin/worker.php --ping
    'system.ping' => static function (array $payload): void {
        Logger::info('Worker: ping', $payload);
    },

    'mail' => static function (array $p): void {
        Mailer::send((string) $p['to'], (string) $p['template'], (array) ($p['data'] ?? []));
    },

    // WhatsApp zgłosił niedoręczenie kodu → ten sam kod SMS-em.
    'otp.fallback_sms' => static function (array $p): void {
        OtpService::fallbackToSms((int) $p['otp_id'], auto: true);
    },

    'getresponse.upsert' => static function (array $p): void {
        GetResponse::upsertContact((string) $p['email'], (string) $p['name'], $p['phone'] ?? null, (array) ($p['tags'] ?? []));
    },
];
