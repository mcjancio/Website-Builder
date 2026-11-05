<?php

declare(strict_types=1);

namespace App\Listings;

use App\Core\Settings;

/**
 * Formularz → wartości pól. Normalizuje to, co wpisał użytkownik („1 250 000,50”, „600100200”),
 * i sprawdza typ. Wymagalność sprawdzamy tylko przy wysłaniu do moderacji ($requireComplete),
 * żeby szkic dało się zapisać w dowolnym momencie.
 *
 * Postać znormalizowana (także w JSON rewizji):
 *   text/textarea/url → string, url_list → string[], int → int, bool → bool,
 *   decimal/percent → "123.45", money → ["amount" => "1000.00", "currency" => "PLN"],
 *   date → "RRRR-MM-DD", select/region → klucz opcji, multiselect → string[] kluczy.
 */
final class FieldInput
{
    public const TEXT_MAX = 1000;
    public const URL_LIST_MAX = 5;

    /**
     * @param array<string,array> $fields definicje (Fields::forCategory)
     * @param array<string,mixed> $raw    $_POST['f']
     * @return array{0:array<string,mixed>,1:array<string,string>} [wartości, błędy]
     */
    public static function parse(array $fields, array $raw, bool $requireComplete = false): array
    {
        $values = [];
        $errors = [];
        foreach ($fields as $key => $f) {
            $in = $raw[$key] ?? null;
            [$value, $error] = self::parseOne($f, $in);
            if ($error !== null) {
                $errors[$key] = $error;
                continue;
            }
            if ($value === null) {
                if ($requireComplete && $f['required']) {
                    $errors[$key] = __('field.required', ['field' => $f['label']]);
                }
                continue;
            }
            $values[$key] = $value;
        }
        return [$values, $errors];
    }

    /** Sprawdza komplet wymaganych pól w już zapisanych wartościach (przed wysłaniem do moderacji). */
    public static function missingRequired(array $fields, array $values): array
    {
        $missing = [];
        foreach ($fields as $key => $f) {
            if ($f['required'] && !array_key_exists($key, $values)) {
                $missing[$key] = $f['label'];
            }
        }
        return $missing;
    }

    /** @return array{0:mixed,1:?string} [wartość albo null gdy puste, błąd] */
    private static function parseOne(array $f, mixed $in): array
    {
        $label = $f['label'];
        $v = $f['validation'];
        switch ($f['type']) {
            case 'text':
            case 'textarea':
                $s = self::str($in);
                if ($s === '') {
                    return [null, null];
                }
                $max = (int) ($v['maxlength'] ?? self::TEXT_MAX);
                return mb_strlen($s) > $max ? [null, __('field.too_long', ['field' => $label, 'max' => $max])] : [$s, null];

            case 'url':
                $s = self::str($in);
                if ($s === '') {
                    return [null, null];
                }
                return self::isUrl($s) ? [$s, null] : [null, __('field.url', ['field' => $label])];

            case 'url_list':
                $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', self::str($in)) ?: [])));
                if ($lines === []) {
                    return [null, null];
                }
                if (count($lines) > self::URL_LIST_MAX) {
                    return [null, __('field.url_list_max', ['field' => $label, 'max' => self::URL_LIST_MAX])];
                }
                foreach ($lines as $l) {
                    if (!self::isUrl($l)) {
                        return [null, __('field.url', ['field' => $label])];
                    }
                }
                return [$lines, null];

            case 'int':
                $s = self::numeric(self::str($in));
                if ($s === '') {
                    return [null, null];
                }
                if (!preg_match('/^-?\d{1,15}$/', $s)) {
                    return [null, __('field.int', ['field' => $label])];
                }
                return self::range((int) $s, $v, $label);

            case 'decimal':
            case 'percent':
                $s = self::numeric(self::str($in));
                if ($s === '') {
                    return [null, null];
                }
                if (!preg_match('/^-?\d{1,14}(\.\d{1,4})?$/', $s)) {
                    return [null, __('field.decimal', ['field' => $label])];
                }
                if ($f['type'] === 'percent' && ((float) $s < -100 || (float) $s > 1000)) {
                    return [null, __('field.percent', ['field' => $label])];
                }
                [$ok, $err] = self::range((float) $s, $v, $label);
                return $err ? [null, $err] : [self::dec($s), null];

            case 'money':
                $amount = self::numeric(self::str(is_array($in) ? ($in['amount'] ?? '') : $in));
                if ($amount === '') {
                    return [null, null];
                }
                if (!preg_match('/^\d{1,14}(\.\d{1,2})?$/', $amount)) {
                    return [null, __('field.money', ['field' => $label])];
                }
                $currencies = (array) Settings::get('portal.currencies', ['PLN']);
                $currency = strtoupper(self::str(is_array($in) ? ($in['currency'] ?? '') : ''));
                if ($currency === '') {
                    $currency = (string) Settings::get('portal.default_currency', $currencies[0] ?? 'PLN');
                }
                if (!in_array($currency, $currencies, true)) {
                    return [null, __('field.currency', ['field' => $label])];
                }
                return [['amount' => number_format((float) $amount, 2, '.', ''), 'currency' => $currency], null];

            case 'date':
                $s = self::str($in);
                if ($s === '') {
                    return [null, null];
                }
                $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $s);
                if ($d === false || $d->format('Y-m-d') !== $s) {
                    return [null, __('field.date', ['field' => $label])];
                }
                $pastYears = (int) ($v['allow_past_years'] ?? 50);
                $year = (int) $d->format('Y');
                $now = (int) gmdate('Y');
                if ($year < $now - $pastYears || $year > $now + 50) {
                    return [null, __('field.date_range', ['field' => $label, 'years' => $pastYears])];
                }
                return [$s, null];

            case 'select':
            case 'region':
                $s = self::str($in);
                if ($s === '') {
                    return [null, null];
                }
                return isset($f['options'][$s]) ? [$s, null] : [null, __('field.option', ['field' => $label])];

            case 'multiselect':
                $keys = array_values(array_unique(array_filter(array_map('strval', is_array($in) ? $in : []))));
                if ($keys === []) {
                    return [null, null];
                }
                foreach ($keys as $k) {
                    if (!isset($f['options'][$k])) {
                        return [null, __('field.option', ['field' => $label])];
                    }
                }
                return [$keys, null];

            case 'bool':
                // Niezaznaczony checkbox = brak wartości (filtr „tak” szuka tylko true).
                return in_array($in, ['1', 'on', 'true', 1, true], true) ? [true, null] : [null, null];
        }
        return [null, null];
    }

    /** @return array{0:mixed,1:?string} */
    private static function range(int|float $n, array $v, string $label): array
    {
        if (isset($v['min']) && $n < $v['min']) {
            return [null, __('field.min', ['field' => $label, 'min' => $v['min']])];
        }
        if (isset($v['max']) && $n > $v['max']) {
            return [null, __('field.max', ['field' => $label, 'max' => $v['max']])];
        }
        return [$n, null];
    }

    private static function str(mixed $in): string
    {
        return is_scalar($in) ? trim((string) $in) : '';
    }

    /** „1 250 000,50 zł” → „1250000.50” */
    private static function numeric(string $s): string
    {
        $s = (string) preg_replace('/[\s\x{00A0}\x{202F}]|zł|pln|eur|usd|gbp|%/iu', '', $s);
        if (substr_count($s, ',') === 1 && !str_contains($s, '.')) {
            $s = str_replace(',', '.', $s);
        }
        return $s;
    }

    private static function dec(string $s): string
    {
        return rtrim(rtrim(number_format((float) $s, 4, '.', ''), '0'), '.');
    }

    private static function isUrl(string $s): bool
    {
        return mb_strlen($s) <= 500 && filter_var($s, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($s, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
