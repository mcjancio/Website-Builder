<?php

declare(strict_types=1);

namespace App\Integrations\Otp;

use App\Core\Config;
use App\Core\Http;
use App\Core\HttpException;
use App\Core\Settings;

/**
 * SMSAPI.pl — REST /sms.do z tokenem OAuth (panel SMSAPI → Ustawienia API).
 * normalize=1 zamienia polskie znaki na łacińskie: SMS mieści się w 160 znakach i jest tańszy.
 */
final class SmsApiChannel implements OtpChannel
{
    public function name(): string
    {
        return 'sms';
    }

    public function isConfigured(): bool
    {
        return Config::get('sms.token', '') !== '';
    }

    public function send(string $phoneE164, string $code): DeliveryResult
    {
        $data = [
            'to' => ltrim($phoneE164, '+'),
            'message' => __('otp.sms_text', [
                'code' => $code,
                'portal' => (string) Settings::get('portal.name', __('app.default_name')),
            ]),
            'format' => 'json',
            'encoding' => 'utf-8',
            'normalize' => '1',
        ];
        if (Config::get('sms.sender', '') !== '') {
            $data['from'] = (string) Config::get('sms.sender');
        }
        if (Config::get('sms.test', false)) {
            $data['test'] = '1'; // SMSAPI przyjmuje i nie wysyła — do testów połączenia
        }

        try {
            $res = Http::postForm('https://api.smsapi.pl/sms.do', $data, ['Authorization' => 'Bearer ' . Config::get('sms.token')]);
        } catch (HttpException $e) {
            return DeliveryResult::failed($e->getMessage());
        }

        $id = $res['json']['list'][0]['id'] ?? null;
        if ($res['status'] === 200 && $id !== null && !isset($res['json']['error'])) {
            return DeliveryResult::sent((string) $id);
        }
        return DeliveryResult::failed(sprintf('SMSAPI HTTP %d: %s (kod %s)',
            $res['status'], $res['json']['message'] ?? 'brak opisu', $res['json']['error'] ?? '?'));
    }
}
