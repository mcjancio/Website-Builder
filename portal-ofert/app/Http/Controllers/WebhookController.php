<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Auth\OtpService;
use App\Core\Config;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Integrations\Otp\WhatsAppChannel;

/**
 * Webhook WhatsApp Business Platform: /webhook/whatsapp
 *  GET  — weryfikacja adresu przy konfiguracji w panelu Meta (hub.challenge)
 *  POST — statusy wiadomości; każde żądanie podpisane X-Hub-Signature-256 (app_secret)
 * Bez sesji i CSRF (wywołuje serwer Meta), za to z obowiązkowym podpisem.
 */
final class WebhookController
{
    public function whatsappVerify(Request $request): Response
    {
        // PHP zamienia kropki w nazwach parametrów na podkreślenia: hub.mode → hub_mode.
        $mode = (string) ($request->query['hub_mode'] ?? $request->query['hub.mode'] ?? '');
        $token = (string) ($request->query['hub_verify_token'] ?? $request->query['hub.verify_token'] ?? '');
        $challenge = (string) ($request->query['hub_challenge'] ?? $request->query['hub.challenge'] ?? '');
        $expected = (string) Config::get('whatsapp.verify_token', '');

        if ($mode === 'subscribe' && $expected !== '' && hash_equals($expected, $token) && preg_match('/^[A-Za-z0-9_\-]{1,200}$/', $challenge)) {
            return Response::text($challenge);
        }
        return Response::text('Forbidden', 403);
    }

    public function whatsapp(Request $request): Response
    {
        $raw = $request->rawBody();
        if (!WhatsAppChannel::validSignature($raw, $request->header('X-Hub-Signature-256'))) {
            Logger::warning('Webhook WhatsApp: niepoprawny podpis', ['ip' => $request->ip()]);
            return Response::text('Forbidden', 403);
        }
        $data = json_decode($raw, true);
        foreach ((array) ($data['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                foreach ((array) ($change['value']['statuses'] ?? []) as $s) {
                    if (!isset($s['id'], $s['status'])) {
                        continue;
                    }
                    $error = isset($s['errors'][0]) ? trim(($s['errors'][0]['code'] ?? '') . ' ' . ($s['errors'][0]['title'] ?? '')) : null;
                    OtpService::handleDeliveryStatus((string) $s['id'], (string) $s['status'], $error);
                }
            }
        }
        // Zawsze 200 po poprawnym podpisie — inaczej Meta ponawia i w końcu wyłącza webhook.
        return Response::text('OK');
    }
}
