<?php

declare(strict_types=1);

/**
 * Rejestr obsługi zadań kolejki: typ => callable(array $payload): void.
 * Wyjątek w handlerze = próba nieudana (ponowienie z opóźnieniem).
 * Kolejne etapy dopisują: mail, sms, getresponse.upsert, webhook, ai.extract, alerts.send.
 */

use App\Core\Logger;

return [
    // Zadanie testowe — sprawdza, czy cron workera działa: php bin/worker.php --ping
    'system.ping' => static function (array $payload): void {
        Logger::info('Worker: ping', $payload);
    },
];
