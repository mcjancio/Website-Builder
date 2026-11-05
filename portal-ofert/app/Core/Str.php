<?php

declare(strict_types=1);

namespace App\Core;

final class Str
{
    private const PL = ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        'Ą' => 'a', 'Ć' => 'c', 'Ę' => 'e', 'Ł' => 'l', 'Ń' => 'n', 'Ó' => 'o', 'Ś' => 's', 'Ź' => 'z', 'Ż' => 'z'];

    /** „Osiedle Zielone — Poznań, etap II” → „osiedle-zielone-poznan-etap-ii” */
    public static function slug(string $text, int $max = 80): string
    {
        $s = strtr($text, self::PL);
        if (function_exists('transliterator_transliterate')) {
            $s = (string) transliterator_transliterate('Any-Latin; Latin-ASCII', $s);
        }
        $s = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $s));
        $s = trim(substr(trim($s, '-'), 0, $max), '-');
        return $s !== '' ? $s : 'oferta';
    }

    /** Liczba w formacie polskim: 1 250 000,50 */
    public static function number(float|int|string|null $value, int $decimals = 0): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        return number_format((float) $value, $decimals, ',', "\u{00A0}");
    }

    public static function bytes(int $bytes): string
    {
        return $bytes >= 1048576 ? self::number($bytes / 1048576, 1) . ' MB' : self::number(max(1, (int) round($bytes / 1024))) . ' KB';
    }
}
