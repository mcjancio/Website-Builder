<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Walidacja serwerowa (JS w przeglądarce jest tylko wygodą).
 *
 *   $v = Validator::make($request->post, [
 *       'email' => 'required|email|maxlength:190',
 *       'phone' => 'required|phone_pl',
 *       'nip'   => 'required|nip',
 *       'terms' => 'accepted',
 *   ], ['email' => 'E-mail']);
 *   if ($v->fails()) { $v->errors(); } else { $v->validated(); }
 *
 * Reguły phone_pl i nip zwracają w validated() wartość znormalizowaną (+48…, 10 cyfr).
 */
final class Validator
{
    private array $errors = [];
    private array $validated = [];

    private function __construct(private array $data, private array $rules, private array $labels)
    {
    }

    public static function make(array $data, array $rules, array $labels = []): self
    {
        $v = new self($data, $rules, $labels);
        $v->run();
        return $v;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string,string> pole => pierwszy komunikat błędu */
    public function errors(): array
    {
        return $this->errors;
    }

    public function validated(): array
    {
        return $this->validated;
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $ruleString) {
            $rules = is_array($ruleString) ? $ruleString : explode('|', $ruleString);
            $value = $this->data[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }
            $label = $this->labels[$field] ?? $field;
            $empty = $value === null || $value === '' || $value === [];
            $required = in_array('required', $rules, true) || in_array('accepted', $rules, true);

            if ($empty && !$required) {
                $this->validated[$field] = null;
                continue;
            }

            foreach ($rules as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $error = $this->check($name, $arg, $value, $empty);
                if ($error === null) {
                    // normalizacja wartości przez regułę
                    if ($name === 'phone_pl') {
                        $value = self::normalizePhone((string) $value);
                    } elseif ($name === 'nip') {
                        $value = self::digits((string) $value);
                    } elseif ($name === 'int') {
                        $value = (int) $value;
                    } elseif ($name === 'accepted') {
                        $value = true;
                    }
                    continue;
                }
                $this->errors[$field] = __('validation.' . $error, ['field' => $label, 'arg' => (string) $arg]);
                continue 2;
            }
            $this->validated[$field] = $value;
        }
    }

    private function check(string $name, ?string $arg, mixed $value, bool $empty): ?string
    {
        $str = is_scalar($value) ? (string) $value : '';
        return match ($name) {
            'required' => $empty ? 'required' : null,
            'accepted' => in_array($value, ['1', 'on', 'yes', 'true', 1, true], true) ? null : 'accepted',
            'email' => filter_var($str, FILTER_VALIDATE_EMAIL) !== false && mb_strlen($str) <= 190 ? null : 'email',
            'maxlength' => mb_strlen($str) <= (int) $arg ? null : 'maxlength',
            'minlength' => mb_strlen($str) >= (int) $arg ? null : 'minlength',
            'int' => preg_match('/^-?\d+$/', $str) ? null : 'int',
            'decimal' => preg_match('/^-?\d+([.,]\d+)?$/', $str) ? null : 'decimal',
            'min' => is_numeric(str_replace(',', '.', $str)) && (float) str_replace(',', '.', $str) >= (float) $arg ? null : 'min',
            'max' => is_numeric(str_replace(',', '.', $str)) && (float) str_replace(',', '.', $str) <= (float) $arg ? null : 'max',
            'date' => self::isDate($str) ? null : 'date',
            'in' => in_array($str, explode(',', (string) $arg), true) ? null : 'in',
            'url' => self::isUrl($str) ? null : 'url',
            'phone_pl' => self::normalizePhone($str) !== null ? null : 'phone_pl',
            'nip' => self::nipValid($str) ? null : 'nip',
            default => throw new \InvalidArgumentException("Nieznana reguła walidacji: {$name}"),
        };
    }

    /** NIP: 10 cyfr, suma kontrolna z wagami 6,5,7,2,3,4,5,6,7. Dopuszcza myślniki/spacje i prefiks PL. */
    public static function nipValid(string $nip): bool
    {
        $d = self::digits(preg_replace('/^PL/i', '', trim($nip)) ?? '');
        if (strlen($d) !== 10 || $d === '0000000000') {
            return false;
        }
        $weights = [6, 5, 7, 2, 3, 4, 5, 6, 7];
        $sum = 0;
        foreach ($weights as $i => $w) {
            $sum += $w * (int) $d[$i];
        }
        $check = $sum % 11;
        return $check !== 10 && $check === (int) $d[9];
    }

    /**
     * Telefon do formatu E.164. Bez prefiksu → Polska (+48).
     * PL: 9 cyfr, pierwsza 4–9 (komórkowe i stacjonarne). Inne kraje: 8–15 cyfr łącznie.
     */
    public static function normalizePhone(string $input): ?string
    {
        $s = trim($input);
        if ($s === '' || preg_match('/[^0-9+\s\-().]/', $s)) {
            return null;
        }
        $plus = str_starts_with($s, '+') || str_starts_with($s, '00');
        $d = self::digits($s);
        if (str_starts_with($s, '00')) {
            $d = substr($d, 2);
        }
        if (!$plus) {
            if (strlen($d) === 11 && str_starts_with($d, '48')) {
                $d = substr($d, 2); // ktoś wpisał 48600… bez plusa
            }
            $d = '48' . $d;
        }
        if (str_starts_with($d, '48')) {
            $national = substr($d, 2);
            return preg_match('/^[4-9]\d{8}$/', $national) ? '+48' . $national : null;
        }
        return preg_match('/^[1-9]\d{7,14}$/', $d) ? '+' . $d : null;
    }

    private static function digits(string $s): string
    {
        return (string) preg_replace('/\D+/', '', $s);
    }

    private static function isDate(string $s): bool
    {
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $s);
        return $dt !== false && $dt->format('Y-m-d') === $s;
    }

    private static function isUrl(string $s): bool
    {
        return filter_var($s, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($s, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
