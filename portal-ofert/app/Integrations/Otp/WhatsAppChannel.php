<?php

declare(strict_types=1);

namespace App\Integrations\Otp;

use App\Core\Config;
use App\Core\Http;
use App\Core\HttpException;

/**
 * WhatsApp Business Platform (Cloud API, bezpośrednio u Meta) — szablon kategorii
 * AUTHENTICATION z przyciskiem „Kopiuj kod”. Konfiguracja: config.php → whatsapp,
 * instrukcja dla właściciela konta: docs/WHATSAPP-KONFIGURACJA.md.
 *
 * Odpowiedź 200 oznacza tylko przyjęcie przez Meta. O doręczeniu (albo o tym, że numer
 * nie ma WhatsAppa) Meta informuje później webhookiem → WhatsAppWebhook → SMS zapasowy.
 */
final class WhatsAppChannel implements OtpChannel
{
    public function name(): string
    {
        return 'whatsapp';
    }

    public function isConfigured(): bool
    {
        return Config::get('whatsapp.phone_number_id', '') !== ''
            && Config::get('whatsapp.access_token', '') !== ''
            && Config::get('whatsapp.template', '') !== '';
    }

    public function send(string $phoneE164, string $code): DeliveryResult
    {
        $url = sprintf(
            'https://graph.facebook.com/%s/%s/messages',
            Config::get('whatsapp.graph_version', 'v23.0'),
            rawurlencode((string) Config::get('whatsapp.phone_number_id'))
        );
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => ltrim($phoneE164, '+'),
            'type' => 'template',
            'template' => [
                'name' => (string) Config::get('whatsapp.template'),
                'language' => ['code' => (string) Config::get('whatsapp.language', 'pl')],
                'components' => [
                    ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $code]]],
                    // Przycisk „Kopiuj kod” szablonu uwierzytelniającego też dostaje kod.
                    ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $code]]],
                ],
            ],
        ];

        try {
            $res = Http::postJson($url, $payload, ['Authorization' => 'Bearer ' . Config::get('whatsapp.access_token')]);
        } catch (HttpException $e) {
            return DeliveryResult::failed($e->getMessage());
        }

        $id = $res['json']['messages'][0]['id'] ?? null;
        if ($res['status'] === 200 && is_string($id)) {
            return DeliveryResult::sent($id);
        }
        $err = $res['json']['error'] ?? [];
        return DeliveryResult::failed(sprintf('WhatsApp HTTP %d: %s (kod %s)',
            $res['status'], $err['message'] ?? 'brak opisu', $err['code'] ?? '?'));
    }

    /** Podpis webhooka: nagłówek X-Hub-Signature-256 = "sha256=" + HMAC-SHA256(surowa treść, app_secret). */
    public static function validSignature(string $rawBody, ?string $header): bool
    {
        $secret = (string) Config::get('whatsapp.app_secret', '');
        if ($secret === '' || $header === null || !str_starts_with($header, 'sha256=')) {
            return false;
        }
        return hash_equals('sha256=' . hash_hmac('sha256', $rawBody, $secret), $header);
    }
}
