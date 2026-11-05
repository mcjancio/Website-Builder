<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimalny klient HTTP (cURL) do integracji: WhatsApp, SMSAPI, MF, GetResponse.
 * W testach Http::fake() podmienia sieć — żadna integracja nie wychodzi na zewnątrz.
 */
final class Http
{
    /** @var (callable(string $method, string $url, array $headers, ?string $body): array{status:int,body:string})|null */
    private static $fake = null;

    /** Ostatnie żądania (tylko w trybie fake) — do asercji w testach. */
    public static array $sent = [];

    public static function fake(?callable $handler): void
    {
        self::$fake = $handler;
        self::$sent = [];
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string,json:?array}
     * @throws HttpException przy błędzie sieci (timeout, DNS, TLS) — NIE przy kodach 4xx/5xx
     */
    public static function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 10): array
    {
        if (self::$fake !== null) {
            self::$sent[] = compact('method', 'url', 'headers', 'body');
            $res = (self::$fake)($method, $url, $headers, $body);
            return self::result((int) $res['status'], (string) $res['body']);
        }

        $ch = curl_init($url);
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_USERAGENT => 'PortalOfert/1.0',
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new HttpException("Błąd połączenia z {$url}: {$error}");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return self::result($status, (string) $response);
    }

    public static function postJson(string $url, array $data, array $headers = [], int $timeout = 10): array
    {
        return self::request('POST', $url, ['Content-Type' => 'application/json'] + $headers,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $timeout);
    }

    public static function postForm(string $url, array $data, array $headers = [], int $timeout = 10): array
    {
        return self::request('POST', $url, ['Content-Type' => 'application/x-www-form-urlencoded'] + $headers,
            http_build_query($data), $timeout);
    }

    private static function result(int $status, string $body): array
    {
        $json = json_decode($body, true);
        return ['status' => $status, 'body' => $body, 'json' => is_array($json) ? $json : null];
    }
}
