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

    // Zdarzenia do zewnętrznego CRM / Make / Zapier (config webhook.url). Podpis HMAC-SHA256 w nagłówku.
    'webhook' => static function (array $p): void {
        $url = (string) \App\Core\Config::get('webhook.url', '');
        if ($url === '') {
            return;
        }
        $body = json_encode($p + ['sent_at' => gmdate('c')], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $sig = hash_hmac('sha256', $body, (string) \App\Core\Config::get('webhook.secret', ''));
        $res = \App\Core\Http::request('POST', $url, ['Content-Type' => 'application/json', 'X-Portal-Signature' => 'sha256=' . $sig], $body);
        if ($res['status'] >= 300) {
            throw new \RuntimeException("Webhook HTTP {$res['status']}");
        }
    },

    'getresponse.upsert' => static function (array $p): void {
        GetResponse::upsertContact((string) $p['email'], (string) $p['name'], $p['phone'] ?? null, (array) ($p['tags'] ?? []));
    },
    'ai.run' => static function (array $p): void {
        \App\Ai\Assistant::run((int) $p['task']);
    },
];
