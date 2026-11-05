<?php

declare(strict_types=1);

namespace App\Listings;

use App\Core\DB;
use App\Integrations\NbpRates;

/**
 * Zapis/odczyt wartości pól oferty w typowanych kolumnach (listing_values, listing_value_options),
 * żeby filtry w etapie 4 działały na indeksach SQL. Kwoty dostają też równowartość w PLN
 * (kurs NBP z dnia zapisu) — filtr „kapitał od–do” porównuje oferty w różnych walutach.
 */
final class ValueStore
{
    /**
     * Zastępuje wartości oferty dla podanych pól (pozostałe pola zostają nietknięte).
     * @param array<string,array> $fields
     * @param array<string,mixed> $values znormalizowane (FieldInput)
     */
    public static function save(int $listingId, array $fields, array $values): void
    {
        $ids = array_map(static fn($f) => $f['id'], $fields);
        if ($ids === []) {
            return;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        DB::query("DELETE FROM listing_values WHERE listing_id = ? AND field_id IN ({$in})", [$listingId, ...array_values($ids)]);
        DB::query("DELETE FROM listing_value_options WHERE listing_id = ? AND field_id IN ({$in})", [$listingId, ...array_values($ids)]);

        foreach ($values as $key => $value) {
            $f = $fields[$key] ?? null;
            if ($f === null) {
                continue;
            }
            if ($f['type'] === 'multiselect') {
                foreach ((array) $value as $optKey) {
                    if (isset($f['options'][$optKey])) {
                        DB::insert('listing_value_options', ['listing_id' => $listingId, 'field_id' => $f['id'], 'option_id' => $f['options'][$optKey]['id']]);
                    }
                }
                continue;
            }
            $row = ['listing_id' => $listingId, 'field_id' => $f['id']];
            switch ($f['type']) {
                case 'int':
                    $row['v_int'] = (int) $value;
                    break;
                case 'bool':
                    $row['v_int'] = $value ? 1 : 0;
                    break;
                case 'decimal':
                case 'percent':
                    $row['v_dec'] = (string) $value;
                    break;
                case 'money':
                    $row['v_dec'] = $value['amount'];
                    $row['currency'] = $value['currency'];
                    $pln = NbpRates::toPln((float) $value['amount'], $value['currency']);
                    $row['v_dec_pln'] = $pln !== null ? number_format($pln, 2, '.', '') : null;
                    break;
                case 'date':
                    $row['v_date'] = (string) $value;
                    break;
                case 'select':
                case 'region':
                    if (!isset($f['options'][$value])) {
                        continue 2;
                    }
                    $row['v_option_id'] = $f['options'][$value]['id'];
                    break;
                case 'url_list':
                    $row['v_text'] = implode("\n", (array) $value);
                    break;
                default:
                    $row['v_text'] = mb_substr((string) $value, 0, FieldInput::TEXT_MAX);
            }
            DB::insert('listing_values', $row);
        }
    }

    /**
     * @return array<string,mixed> key => wartość znormalizowana (jak FieldInput)
     */
    public static function load(int $listingId): array
    {
        $fieldsById = [];
        foreach (Fields::all() as $key => $f) {
            $fieldsById[$f['id']] = $f;
        }
        $optionKeys = [];
        foreach (DB::fetchAll('SELECT id, `key` FROM field_options') as $o) {
            $optionKeys[(int) $o['id']] = $o['key'];
        }

        $out = [];
        foreach (DB::fetchAll('SELECT * FROM listing_values WHERE listing_id = ?', [$listingId]) as $r) {
            $f = $fieldsById[(int) $r['field_id']] ?? null;
            if ($f === null) {
                continue; // pole ukryte w presecie — wartość zostaje w bazie, ale nie jest pokazywana
            }
            $out[$f['key']] = match ($f['type']) {
                'int' => (int) $r['v_int'],
                'bool' => (bool) $r['v_int'],
                'decimal', 'percent' => rtrim(rtrim((string) $r['v_dec'], '0'), '.'),
                'money' => ['amount' => number_format((float) $r['v_dec'], 2, '.', ''), 'currency' => (string) $r['currency']],
                'date' => (string) $r['v_date'],
                'select', 'region' => $optionKeys[(int) $r['v_option_id']] ?? null,
                'url_list' => array_values(array_filter(explode("\n", (string) $r['v_text']))),
                default => (string) $r['v_text'],
            };
        }
        foreach (DB::fetchAll('SELECT field_id, option_id FROM listing_value_options WHERE listing_id = ? ORDER BY option_id', [$listingId]) as $r) {
            $f = $fieldsById[(int) $r['field_id']] ?? null;
            if ($f !== null && isset($optionKeys[(int) $r['option_id']])) {
                $out[$f['key']][] = $optionKeys[(int) $r['option_id']];
            }
        }
        return array_filter($out, static fn($v) => $v !== null);
    }
}
