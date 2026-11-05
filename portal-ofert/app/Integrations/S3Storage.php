<?php

declare(strict_types=1);

namespace App\Integrations;

use App\Core\Config;

/**
 * Kopia poza serwerem: dowolny magazyn zgodny z S3 (Backblaze B2, Wasabi, Cloudflare R2, AWS, OVH…).
 * Podpis AWS Signature V4, adresy w stylu ścieżki (endpoint/bucket/klucz). Plik jest wysyłany
 * strumieniowo (bez wczytywania do pamięci). Rotację po stronie magazynu ustaw regułą cyklu życia
 * (np. „usuń po 30 dniach”) — patrz INSTALACJA.md.
 */
final class S3Storage
{
    /** @var (callable(string $method, string $url, array $headers, string $file): int)|null  transport w testach */
    public static $transport = null;

    public function __construct(
        private readonly string $endpoint,
        private readonly string $region,
        private readonly string $bucket,
        private readonly string $accessKey,
        private readonly string $secretKey,
        private readonly string $prefix = '',
    ) {
    }

    public static function fromConfig(): ?self
    {
        $c = (array) Config::get('backup.s3', []);
        foreach (['endpoint', 'bucket', 'key', 'secret'] as $k) {
            if (trim((string) ($c[$k] ?? '')) === '') {
                return null;
            }
        }
        return new self(rtrim((string) $c['endpoint'], '/'), (string) ($c['region'] ?? 'us-east-1'), (string) $c['bucket'],
            (string) $c['key'], (string) $c['secret'], trim((string) ($c['prefix'] ?? ''), '/'));
    }

    /** Wysyła plik; zwraca klucz obiektu. */
    public function put(string $file): string
    {
        $key = ($this->prefix !== '' ? $this->prefix . '/' : '') . basename($file);
        $url = $this->endpoint . '/' . rawurlencode($this->bucket) . '/' . implode('/', array_map('rawurlencode', explode('/', $key)));
        $hash = (string) hash_file('sha256', $file);
        $date = gmdate('Ymd\THis\Z');
        $headers = [
            'host' => (string) parse_url($url, PHP_URL_HOST) . (parse_url($url, PHP_URL_PORT) ? ':' . parse_url($url, PHP_URL_PORT) : ''),
            'x-amz-content-sha256' => $hash,
            'x-amz-date' => $date,
            'content-type' => 'application/octet-stream',
        ];
        $headers['authorization'] = self::authorization('PUT', $url, $headers, $hash, $this->accessKey, $this->secretKey, $this->region, $date);
        $status = self::$transport !== null ? (self::$transport)('PUT', $url, $headers, $file) : self::curlPut($url, $headers, $file);
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("Wysyłka kopii do magazynu S3 nieudana (HTTP {$status}).");
        }
        return $key;
    }

    /**
     * Nagłówek Authorization (AWS SigV4) — czysta funkcja, sprawdzana na przykładzie z dokumentacji AWS.
     * @param array<string,string> $headers nagłówki do podpisania (nazwy małymi literami)
     */
    public static function authorization(string $method, string $url, array $headers, string $payloadHash, string $accessKey, string $secretKey, string $region, string $amzDate, string $service = 's3'): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        ksort($query);
        $canonicalQuery = implode('&', array_map(static fn($k, $v) => rawurlencode((string) $k) . '=' . rawurlencode((string) $v), array_keys($query), $query));
        $h = [];
        foreach ($headers as $k => $v) {
            $h[strtolower($k)] = trim((string) preg_replace('/\s+/', ' ', $v));
        }
        ksort($h);
        $signed = implode(';', array_keys($h));
        $canonicalHeaders = implode('', array_map(static fn($k, $v) => "{$k}:{$v}\n", array_keys($h), $h));
        $canonical = implode("\n", [$method, $path, $canonicalQuery, $canonicalHeaders, $signed, $payloadHash]);
        $day = substr($amzDate, 0, 8);
        $scope = "{$day}/{$region}/{$service}/aws4_request";
        $toSign = implode("\n", ['AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $canonical)]);
        $k = hash_hmac('sha256', $day, 'AWS4' . $secretKey, true);
        $k = hash_hmac('sha256', $region, $k, true);
        $k = hash_hmac('sha256', $service, $k, true);
        $k = hash_hmac('sha256', 'aws4_request', $k, true);
        $signature = hash_hmac('sha256', $toSign, $k);
        return "AWS4-HMAC-SHA256 Credential={$accessKey}/{$scope},SignedHeaders={$signed},Signature={$signature}";
    }

    private static function curlPut(string $url, array $headers, string $file): int
    {
        $fh = fopen($file, 'rb');
        $ch = curl_init($url);
        $lines = [];
        foreach ($headers as $k => $v) {
            // Host wysyłamy dokładnie taki, jaki został podpisany (także z portem).
            $lines[] = ($k === 'host' ? 'Host' : $k) . ': ' . $v;
        }
        curl_setopt_array($ch, [
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILE => $fh,
            CURLOPT_INFILESIZE => filesize($file),
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 1800,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fh);
        if ($ok === false) {
            throw new \RuntimeException("Błąd połączenia z magazynem S3: {$error}");
        }
        return $status;
    }
}
