<?php

declare(strict_types=1);

namespace App\Integrations;

use App\Core\Http;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Validator;

/**
 * Dane firmy po NIP z publicznego API „Wykaz podatników VAT” Ministerstwa Finansów
 * (wl-api.mf.gov.pl, bez klucza; limit MF: kilkadziesiąt zapytań dziennie na IP dla wyszukiwania).
 * Wyniki cache'owane 24 h w storage/cache. Firma spoza wykazu (np. zwolniona z VAT) → null,
 * użytkownik wpisuje dane ręcznie (companies.source = 'manual').
 */
final class MfVatWhitelist
{
    /**
     * @return array{nip:string,name:string,regon:?string,krs:?string,address:?string,postal_code:?string,city:?string,vat_status:?string}|null
     */
    public static function lookup(string $nip): ?array
    {
        if (!Validator::nipValid($nip)) {
            return null;
        }
        $nip = (string) preg_replace('/\D+/', '', preg_replace('/^PL/i', '', $nip) ?? '');
        $cacheFile = APP_ROOT . '/storage/cache/nip-' . $nip . '.json';
        if (is_file($cacheFile) && filemtime($cacheFile) > time() - 86400) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            return is_array($cached) && $cached !== [] ? $cached : null;
        }

        try {
            $res = Http::request('GET', 'https://wl-api.mf.gov.pl/api/search/nip/' . $nip . '?date=' . gmdate('Y-m-d'), ['Accept' => 'application/json'], null, 6);
        } catch (HttpException $e) {
            Logger::warning('API MF niedostępne', ['error' => $e->getMessage()]);
            return null; // bez cache — następna próba zapyta ponownie
        }
        if ($res['status'] !== 200) {
            Logger::warning('API MF zwróciło błąd', ['status' => $res['status'], 'body' => mb_substr($res['body'], 0, 300)]);
            return null;
        }

        $s = $res['json']['result']['subject'] ?? null;
        $data = is_array($s) ? self::map($s, $nip) : [];
        @file_put_contents($cacheFile, json_encode($data, JSON_UNESCAPED_UNICODE));
        return $data === [] ? null : $data;
    }

    private static function map(array $s, string $nip): array
    {
        $address = (string) ($s['workingAddress'] ?? $s['residenceAddress'] ?? '');
        $postal = null;
        $city = null;
        if (preg_match('/^(.*?),?\s*(\d{2}-\d{3})\s+(.+)$/u', $address, $m)) {
            $postal = $m[2];
            $city = mb_convert_case(mb_strtolower($m[3]), MB_CASE_TITLE);
            $address = trim($m[1], ' ,');
        }
        return [
            'nip' => $nip,
            'name' => trim((string) ($s['name'] ?? '')),
            'regon' => $s['regon'] ?? null,
            'krs' => $s['krs'] ?? null,
            'address' => $address !== '' ? $address : null,
            'postal_code' => $postal,
            'city' => $city,
            'vat_status' => $s['statusVat'] ?? null,
        ];
    }
}
