<?php

declare(strict_types=1);

namespace App\Listings;

use App\Core\Str;

/** Prezentacja wartości pól (lista, strona oferty, podgląd) — jedno miejsce formatowania. */
final class Display
{
    public static function value(array $field, mixed $v): string
    {
        return match ($field['type']) {
            'money' => self::money($v),
            'decimal' => Str::number((float) $v, str_contains((string) $v, '.') ? 2 : 0),
            'percent' => Str::number((float) $v, str_contains((string) $v, '.') ? 1 : 0) . "\u{00A0}%",
            'int' => Str::number((int) $v),
            'bool' => $v ? __('common.yes') : __('common.no'),
            'date' => self::date((string) $v),
            'select', 'region' => $field['options'][$v]['label'] ?? (string) $v,
            'multiselect' => implode(', ', array_map(static fn($k) => $field['options'][$k]['label'] ?? $k, (array) $v)),
            'url_list' => implode(', ', (array) $v),
            default => (string) $v,
        };
    }

    public static function money(mixed $v): string
    {
        if (!is_array($v)) {
            return '';
        }
        $amount = (float) $v['amount'];
        return Str::number($amount, fmod($amount, 1.0) == 0.0 ? 0 : 2) . "\u{00A0}" . ($v['currency'] === 'PLN' ? 'zł' : $v['currency']);
    }

    public static function date(string $ymd): string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
        return $d ? $d->format('d.m.Y') : $ymd;
    }

    /** Data i godzina z bazy (UTC) w strefie portalu, np. „05.11.2026 14:30”. */
    public static function datetime(?string $utc): string
    {
        if ($utc === null || $utc === '') {
            return '—';
        }
        try {
            $d = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
            return $d->setTimezone(new \DateTimeZone((string) config('app.timezone', 'Europe/Warsaw')))->format('d.m.Y H:i');
        } catch (\Exception) {
            return $utc;
        }
    }

    /**
     * Adres do osadzenia filmu: YouTube (domena bez ciasteczek) i Vimeo — tylko te są w CSP frame-src.
     * Inne adresy zostają zwykłymi linkami.
     */
    public static function embedUrl(string $url): ?string
    {
        $p = parse_url($url);
        $host = strtolower((string) ($p['host'] ?? ''));
        $host = (string) preg_replace('/^(www\.|m\.)/', '', $host);
        $id = null;
        if ($host === 'youtu.be') {
            $id = ltrim((string) ($p['path'] ?? ''), '/');
        } elseif ($host === 'youtube.com') {
            parse_str((string) ($p['query'] ?? ''), $q);
            $id = $q['v'] ?? (preg_match('#^/(?:embed|shorts|live)/([^/?]+)#', (string) ($p['path'] ?? ''), $m) ? $m[1] : null);
        }
        if (is_string($id) && preg_match('/^[A-Za-z0-9_-]{6,20}$/', $id)) {
            return 'https://www.youtube-nocookie.com/embed/' . $id;
        }
        if ($host === 'vimeo.com' && preg_match('#^/(\d{5,12})#', (string) ($p['path'] ?? ''), $m)) {
            return 'https://player.vimeo.com/video/' . $m[1];
        }
        return null;
    }

    /**
     * Wartości pól pokazywane na kafelku (show_in_card) — tylko te, które użytkownik może widzieć.
     * Lokalizacja (region/city) jest już w nagłówku kafelka, więc jej nie powtarzamy.
     */
    public static function cardValues(array $values, int $maxLevel): array
    {
        $out = [];
        foreach (Fields::all() as $key => $f) {
            if ($f['show_in_card'] && !in_array($key, ['region', 'city'], true) && $f['access_level'] <= $maxLevel && array_key_exists($key, $values) && !in_array($f['type'], ['url_list', 'textarea'], true)) {
                $out[$f['label']] = self::value($f, $values[$key]);
            }
        }
        return $out;
    }
}
