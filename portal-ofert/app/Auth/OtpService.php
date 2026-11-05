<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Config;
use App\Core\Crypto;
use App\Core\DB;
use App\Core\Logger;
use App\Core\Queue;
use App\Core\RateLimiter;
use App\Core\Settings;
use App\Integrations\Otp\LogChannel;
use App\Integrations\Otp\OtpChannel;
use App\Integrations\Otp\SmsApiChannel;
use App\Integrations\Otp\WhatsAppChannel;

/**
 * Kody weryfikacyjne telefonu.
 *
 * Kolejność kanałów: config otp.channels → preset registration.otp_channels → ['whatsapp', 'sms'].
 * Nieskonfigurowane kanały są pomijane. Gdy wysyłka pierwszym kanałem się nie uda od razu
 * (błąd API), próbujemy następnego. Gdy WhatsApp przyjmie wiadomość, ale Meta zgłosi później
 * „nie doręczono” (np. numer bez WhatsAppa) — webhook uruchamia SMS zapasowy z TYM SAMYM kodem.
 * Użytkownik może też sam poprosić o SMS po otp.resend_after sekundach.
 */
final class OtpService
{
    /** @var array<string,OtpChannel>|null podmiana kanałów w testach */
    private static ?array $channelsOverride = null;

    public static function useChannels(?array $channels): void
    {
        self::$channelsOverride = $channels;
    }

    /** @return OtpChannel[] skonfigurowane kanały w ustalonej kolejności */
    public static function channels(): array
    {
        $available = self::$channelsOverride ?? self::defaultChannels();
        $order = (array) (Config::get('otp.channels') ?? Settings::get('registration.otp_channels') ?? ['whatsapp', 'sms']);
        $out = [];
        foreach ($order as $name) {
            $ch = $available[$name] ?? null;
            if ($ch !== null && $ch->isConfigured()) {
                $out[] = $ch;
            }
        }
        return $out;
    }

    /**
     * Generuje i wysyła kod. Zwraca dane do ekranu „wpisz kod”.
     *
     * @return array{id:int,channel:string,phone:string,phone_masked:string}
     * @throws OtpException gdy limit wysyłek przekroczony albo żaden kanał nie zadziałał
     */
    public static function send(string $phone, string $purpose, string $ip): array
    {
        $channels = self::channels();
        if ($channels === []) {
            Logger::critical('Brak skonfigurowanego kanału weryfikacji telefonu (otp/whatsapp/sms w config.php)');
            throw new OtpException(__('otp.error_no_channel'));
        }
        self::checkSendLimits($phone, $ip);

        $code = self::generateCode();
        // Poprzednie aktywne kody dla tego numeru i celu przestają działać.
        DB::query(
            'UPDATE otp_codes SET expires_at = UTC_TIMESTAMP(), code_enc = NULL
             WHERE phone_e164 = ? AND purpose = ? AND verified_at IS NULL AND expires_at > UTC_TIMESTAMP()',
            [$phone, $purpose]
        );

        $errors = [];
        foreach ($channels as $channel) {
            $result = $channel->send($phone, $code);
            if ($result->ok) {
                $id = DB::insert('otp_codes', [
                    'phone_e164' => $phone,
                    'purpose' => $purpose,
                    'channel' => $channel->name(),
                    'code_hash' => Crypto::hmac($code),
                    'code_enc' => Crypto::encrypt($code),
                    'expires_at' => gmdate('Y-m-d H:i:s', time() + self::ttl()),
                    'ip' => inet_pton($ip) ?: inet_pton('0.0.0.0'),
                    'provider_id' => $result->providerId,
                    'delivery_status' => 'sent',
                ]);
                if ($errors !== []) {
                    Logger::warning('Kod wysłany kanałem zapasowym', ['channel' => $channel->name(), 'errors' => $errors]);
                }
                return ['id' => $id, 'channel' => $channel->name(), 'phone' => $phone, 'phone_masked' => self::mask($phone)];
            }
            $errors[$channel->name()] = $result->error;
        }

        Logger::error('Nie udało się wysłać kodu żadnym kanałem', ['phone' => self::mask($phone), 'errors' => $errors]);
        throw new OtpException(__('otp.error_send_failed'));
    }

    /**
     * SMS zapasowy z tym samym kodem — na prośbę użytkownika ($auto = false) albo po
     * zgłoszeniu niedoręczenia przez WhatsApp ($auto = true). Zwraca true, gdy wysłano.
     */
    public static function fallbackToSms(int $otpId, bool $auto = false): bool
    {
        $row = DB::fetch(
            'SELECT *, TIMESTAMPDIFF(SECOND, created_at, UTC_TIMESTAMP()) AS age FROM otp_codes
             WHERE id = ? AND verified_at IS NULL AND expires_at > UTC_TIMESTAMP()',
            [$otpId]
        );
        if ($row === null || $row['channel'] !== 'whatsapp' || $row['fallback_sent_at'] !== null || $row['code_enc'] === null) {
            return false;
        }
        if (!$auto && (int) $row['age'] < self::resendAfter()) {
            return false;
        }
        $sms = null;
        foreach (self::$channelsOverride ?? self::defaultChannels() as $ch) {
            if ($ch->name() === 'sms' && $ch->isConfigured()) {
                $sms = $ch;
            }
        }
        $code = Crypto::decrypt((string) $row['code_enc']);
        if ($sms === null || $code === null) {
            return false;
        }
        if (!RateLimiter::attempt('otp:fallback:' . $row['phone_e164'], 3, 3600)) {
            return false;
        }
        // Najpierw oznaczamy (warunkowo), żeby równoległy webhook i kliknięcie nie wysłały dwóch SMS-ów.
        $claimed = DB::query('UPDATE otp_codes SET fallback_sent_at = UTC_TIMESTAMP() WHERE id = ? AND fallback_sent_at IS NULL', [$otpId])->rowCount();
        if ($claimed !== 1) {
            return false;
        }
        $result = $sms->send((string) $row['phone_e164'], $code);
        if (!$result->ok) {
            DB::query('UPDATE otp_codes SET fallback_sent_at = NULL, delivery_error = ? WHERE id = ?', [$result->error, $otpId]);
            Logger::error('SMS zapasowy nieudany', ['otp' => $otpId, 'error' => $result->error]);
            return false;
        }
        Logger::info('Wysłano SMS zapasowy', ['otp' => $otpId, 'auto' => $auto]);
        return true;
    }

    /**
     * @return 'ok'|'invalid'|'expired'|'too_many'
     */
    public static function verify(int $otpId, string $phone, string $purpose, string $code): string
    {
        $row = DB::fetch(
            'SELECT id, code_hash, attempts, verified_at, expires_at > UTC_TIMESTAMP() AS active
             FROM otp_codes WHERE id = ? AND phone_e164 = ? AND purpose = ?',
            [$otpId, $phone, $purpose]
        );
        if ($row === null || $row['verified_at'] !== null || !(int) $row['active']) {
            return 'expired';
        }
        if ((int) $row['attempts'] >= self::maxAttempts()) {
            return 'too_many';
        }
        DB::query('UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?', [$otpId]);

        $code = (string) preg_replace('/\D+/', '', $code);
        if (!hash_equals((string) $row['code_hash'], Crypto::hmac($code))) {
            return (int) $row['attempts'] + 1 >= self::maxAttempts() ? 'too_many' : 'invalid';
        }
        DB::query('UPDATE otp_codes SET verified_at = UTC_TIMESTAMP(), code_enc = NULL WHERE id = ?', [$otpId]);
        return 'ok';
    }

    /** Status z webhooka WhatsApp (sent/delivered/read/failed). Niedoręczenie → SMS zapasowy w kolejce. */
    public static function handleDeliveryStatus(string $providerId, string $status, ?string $error = null): void
    {
        $row = DB::fetch('SELECT id, channel, delivery_status, verified_at, fallback_sent_at FROM otp_codes WHERE provider_id = ?', [$providerId]);
        if ($row === null) {
            return; // wiadomość spoza weryfikacji albo bardzo stara
        }
        // Nie cofamy statusu (Meta potrafi przysłać „sent” po „delivered”).
        $rank = ['sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4];
        if (($rank[$status] ?? 0) > ($rank[$row['delivery_status'] ?? ''] ?? 0)) {
            DB::query(
                'UPDATE otp_codes SET delivery_status = ?, delivery_error = COALESCE(?, delivery_error) WHERE id = ?',
                [$status, $error !== null ? mb_substr($error, 0, 250) : null, $row['id']]
            );
        }
        if ($status === 'failed' && $row['channel'] === 'whatsapp' && $row['verified_at'] === null && $row['fallback_sent_at'] === null) {
            Queue::push('otp.fallback_sms', ['otp_id' => (int) $row['id']], 0, 3);
        }
    }

    public static function status(int $otpId): ?array
    {
        return DB::fetch(
            'SELECT id, channel, delivery_status, fallback_sent_at IS NOT NULL AS fallback_sent,
                    GREATEST(0, ? - TIMESTAMPDIFF(SECOND, created_at, UTC_TIMESTAMP())) AS fallback_in
             FROM otp_codes WHERE id = ?',
            [self::resendAfter(), $otpId]
        );
    }

    public static function mask(string $phone): string
    {
        $len = strlen($phone);
        if ($len < 7) {
            return $phone;
        }
        $visible = substr($phone, 0, 6) . ' *** ' . substr($phone, -3);
        return str_starts_with($phone, '+48') ? '+48 ' . substr($phone, 3, 3) . ' *** ' . substr($phone, -3) : $visible;
    }

    public static function ttl(): int
    {
        return (int) Config::get('otp.ttl', 600);
    }

    public static function resendAfter(): int
    {
        return (int) Config::get('otp.resend_after', 30);
    }

    public static function maxAttempts(): int
    {
        return (int) Config::get('otp.max_attempts', 5);
    }

    private static function checkSendLimits(string $phone, string $ip): void
    {
        $perPhone = (int) Config::get('otp.limit_per_phone_hour', 5);
        $perIp = (int) Config::get('otp.limit_per_ip_day', 20);
        if (!RateLimiter::attempt('otp:phone:' . $phone, $perPhone, 3600)
            || !RateLimiter::attempt('otp:ip:' . $ip, $perIp, 86400)) {
            throw new OtpException(__('otp.error_rate_limit'));
        }
    }

    private static function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private static function defaultChannels(): array
    {
        if (Config::get('otp.test_mode', false)) {
            if (Config::get('app.env') === 'production') {
                Logger::critical('otp.test_mode włączony na produkcji — kody nie są wysyłane!');
            }
            return ['whatsapp' => new LogChannel('whatsapp'), 'sms' => new LogChannel('sms')];
        }
        return ['whatsapp' => new WhatsAppChannel(), 'sms' => new SmsApiChannel()];
    }
}
