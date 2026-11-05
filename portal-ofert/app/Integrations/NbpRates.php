<?php

declare(strict_types=1);

namespace App\Integrations;

use App\Core\Http;
use App\Core\HttpException;
use App\Core\Logger;

/**
 * Kursy średnie NBP (tabela A, api.nbp.pl, bez klucza) — do przeliczenia kwot ofert na PLN
 * na potrzeby filtrów. Cache 12 h; przy niedostępności API używamy ostatniego znanego kursu.
 */
final class NbpRates
{
    public static function toPln(float $amount, string $currency): ?float
    {
        $currency = strtoupper($currency);
        if ($currency === 'PLN') {
            return $amount;
        }
        $rate = self::rate($currency);
        return $rate !== null ? round($amount * $rate, 2) : null;
    }

    public static function rate(string $currency): ?float
    {
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            return null;
        }
        $file = APP_ROOT . '/storage/cache/nbp-' . $currency . '.json';
        $cached = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (is_array($cached) && filemtime($file) > time() - 43200) {
            return (float) $cached['rate'];
        }
        try {
            $res = Http::request('GET', 'https://api.nbp.pl/api/exchangerates/rates/a/' . strtolower($currency) . '/?format=json',
                ['Accept' => 'application/json'], null, 5);
            $rate = $res['json']['rates'][0]['mid'] ?? null;
            if ($res['status'] === 200 && is_numeric($rate)) {
                @file_put_contents($file, json_encode(['rate' => (float) $rate, 'date' => $res['json']['rates'][0]['effectiveDate'] ?? null]));
                return (float) $rate;
            }
            Logger::warning('NBP: brak kursu', ['currency' => $currency, 'status' => $res['status']]);
        } catch (HttpException $e) {
            Logger::warning('NBP niedostępne', ['error' => $e->getMessage()]);
        }
        return is_array($cached) ? (float) $cached['rate'] : null;
    }
}
