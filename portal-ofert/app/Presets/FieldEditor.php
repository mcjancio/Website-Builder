<?php

declare(strict_types=1);

namespace App\Presets;

use App\Auth\Audit;
use App\Core\DB;
use App\Listings\Fields;

/**
 * Edycja kryteriów w panelu admina (bez programisty). Te same zasady co import presetu:
 *  - klucz pola jest stały; etykietę, grupę, poziom dostępu, filtr itd. można zmieniać,
 *  - typu pola z wartościami w ofertach nie da się zmienić,
 *  - pola, opcje i kategorie się ukrywa, nie usuwa (stare oferty zachowują dane).
 */
final class FieldEditor
{
    /** @return array<string,string> błędy (pusta = zapisano) */
    public static function saveField(?int $id, array $in, ?int $by): array
    {
        $data = [
            'label' => trim((string) ($in['label'] ?? '')),
            'help' => trim((string) ($in['help'] ?? '')) ?: null,
            'group_name' => trim((string) ($in['group_name'] ?? '')) ?: 'Parametry',
            'required' => (int) !empty($in['required']),
            'access_level' => (int) ($in['access_level'] ?? 2),
            'filterable' => (int) !empty($in['filterable']),
            'filter_ui' => (string) ($in['filter_ui'] ?? 'none'),
            'show_in_card' => (int) !empty($in['show_in_card']),
        ];
        $validation = [];
        foreach (['min', 'max', 'maxlength', 'allow_past_years'] as $v) {
            $raw = trim((string) ($in['validation'][$v] ?? ''));
            if ($raw !== '' && is_numeric(str_replace(',', '.', $raw))) {
                $validation[$v] = $v === 'maxlength' || $v === 'allow_past_years' ? (int) $raw : (float) str_replace(',', '.', $raw);
            }
        }
        $data['validation'] = $validation !== [] ? json_encode($validation) : null;

        $errors = [];
        if ($data['label'] === '' || mb_strlen($data['label']) > 150) {
            $errors['label'] = __('fields.err_label');
        }
        if (!in_array($data['access_level'], [1, 2, 3], true)) {
            $errors['access_level'] = __('fields.err_level');
        }
        if (!in_array($data['filter_ui'], Importer::FILTER_UI, true)) {
            $errors['filter_ui'] = __('fields.err_filter');
        }
        if ($data['filterable'] && $data['filter_ui'] === 'none') {
            $data['filter_ui'] = 'select';
        }
        if (!$data['filterable']) {
            $data['filter_ui'] = 'none';
        }
        if ($data['access_level'] === 3 && $data['filterable']) {
            $errors['filterable'] = __('fields.err_filter_l3');
        }

        $type = (string) ($in['type'] ?? '');
        if ($id === null) {
            $key = trim((string) ($in['key'] ?? ''));
            if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key)) {
                $errors['key'] = __('fields.err_key');
            } elseif (DB::value('SELECT 1 FROM field_definitions WHERE `key` = ?', [$key]) !== null) {
                $errors['key'] = __('fields.err_key_taken');
            }
            if (!in_array($type, Importer::FIELD_TYPES, true)) {
                $errors['type'] = __('fields.err_type');
            }
        } else {
            $current = DB::fetch('SELECT * FROM field_definitions WHERE id = ?', [$id]);
            if ($current === null) {
                return ['label' => __('fields.err_missing')];
            }
            if ($type !== '' && $type !== $current['type']) {
                if (self::hasValues($id)) {
                    $errors['type'] = __('fields.err_type_locked');
                } elseif (!in_array($type, Importer::FIELD_TYPES, true)) {
                    $errors['type'] = __('fields.err_type');
                } else {
                    $data['type'] = $type;
                }
            }
        }
        $effectiveType = $data['type'] ?? $type ?: ($current['type'] ?? '');
        if ($data['filter_ui'] === 'range' && !in_array($effectiveType, ['int', 'decimal', 'money', 'percent', 'date'], true)) {
            $errors['filter_ui'] = __('fields.err_range');
        }
        if (in_array($data['filter_ui'], ['select', 'checkboxes'], true) && !in_array($effectiveType, ['select', 'multiselect', 'region'], true)) {
            $errors['filter_ui'] = __('fields.err_options_filter');
        }
        if ($data['filter_ui'] === 'toggle' && $effectiveType !== 'bool') {
            $errors['filter_ui'] = __('fields.err_toggle');
        }
        if ($errors !== []) {
            return $errors;
        }

        $catIds = array_values(array_unique(array_map('intval', (array) ($in['categories'] ?? []))));
        DB::transaction(static function () use ($id, $data, $in, $type, $catIds, $by): void {
            if ($id === null) {
                $data['key'] = trim((string) $in['key']);
                $data['type'] = $type;
                $data['sort_order'] = (int) DB::value('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM field_definitions');
                $id = DB::insert('field_definitions', $data);
                if ($type === 'region') {
                    $pos = 10;
                    foreach (Importer::REGIONS as $k => $label) {
                        DB::insert('field_options', ['field_id' => $id, 'key' => $k, 'label' => $label, 'sort_order' => $pos += 10]);
                    }
                }
                Audit::log('field.create', 'field', $id, ['key' => $data['key']], null, $by);
            } else {
                DB::update('field_definitions', $data, ['id' => $id]);
                Audit::log('field.update', 'field', $id, [], null, $by);
            }
            DB::query('DELETE FROM field_categories WHERE field_id = ?', [$id]);
            foreach ($catIds as $cid) {
                if (DB::value('SELECT 1 FROM categories WHERE id = ?', [$cid]) !== null) {
                    DB::insert('field_categories', ['field_id' => $id, 'category_id' => $cid]);
                }
            }
        });
        Fields::flush();
        return [];
    }

    public static function setActive(string $table, int $id, bool $active, ?int $by): void
    {
        self::assertTable($table);
        DB::update($table, ['is_active' => (int) $active], ['id' => $id]);
        Audit::log(($active ? 'restore.' : 'hide.') . $table, $table, $id, [], null, $by);
        Fields::flush();
    }

    /** Przesunięcie w kolejności (pola globalnie, opcje w obrębie pola, kategorie w obrębie rodzica). */
    public static function move(string $table, int $id, int $dir): void
    {
        self::assertTable($table);
        $scope = match ($table) {
            'field_options' => ['field_id', DB::value('SELECT field_id FROM field_options WHERE id = ?', [$id])],
            'categories' => ['parent_id', DB::value('SELECT parent_id FROM categories WHERE id = ?', [$id])],
            default => [null, null],
        };
        $where = $scope[0] === null ? '1=1' : ($scope[1] === null ? "{$scope[0]} IS NULL" : "{$scope[0]} = " . (int) $scope[1]);
        $ids = array_map('intval', array_column(DB::fetchAll("SELECT id FROM {$table} WHERE {$where} ORDER BY sort_order, id"), 'id'));
        $pos = array_search($id, $ids, true);
        $swap = $pos === false ? null : $pos + ($dir < 0 ? -1 : 1);
        if ($swap === null || !isset($ids[$swap])) {
            return;
        }
        [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
        foreach ($ids as $i => $rid) {
            DB::query("UPDATE {$table} SET sort_order = ? WHERE id = ?", [($i + 1) * 10, $rid]);
        }
        Fields::flush();
    }

    /** @return array<string,string> */
    public static function saveOption(int $fieldId, ?int $optionId, string $key, string $label, ?int $by): array
    {
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 200) {
            return ['label' => __('fields.err_label')];
        }
        if ($optionId !== null) {
            DB::query('UPDATE field_options SET label = ? WHERE id = ? AND field_id = ?', [$label, $optionId, $fieldId]);
        } else {
            $key = trim($key) !== '' ? trim($key) : \App\Core\Str::slug($label, 64);
            $key = str_replace('-', '_', $key);
            if (!preg_match('/^[a-z0-9_\-]{1,64}$/', $key)) {
                return ['key' => __('fields.err_key')];
            }
            if (DB::value('SELECT 1 FROM field_options WHERE field_id = ? AND `key` = ?', [$fieldId, $key]) !== null) {
                return ['key' => __('fields.err_key_taken')];
            }
            DB::insert('field_options', [
                'field_id' => $fieldId, 'key' => $key, 'label' => $label,
                'sort_order' => (int) DB::value('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM field_options WHERE field_id = ?', [$fieldId]),
            ]);
        }
        Audit::log('field.option', 'field', $fieldId, ['option' => $optionId ?? $key], null, $by);
        Fields::flush();
        return [];
    }

    /** @return array<string,string> */
    public static function saveCategory(?int $id, string $name, ?int $parentId, ?int $by): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 150) {
            return ['name' => __('fields.err_label')];
        }
        if ($parentId !== null && ($parentId === $id || DB::value('SELECT 1 FROM categories WHERE id = ?', [$parentId]) === null)) {
            $parentId = null;
        }
        if ($id !== null) {
            DB::update('categories', ['name' => $name, 'parent_id' => $parentId], ['id' => $id]);
        } else {
            $slug = \App\Core\Str::slug($name, 100);
            $n = 2;
            $base = $slug;
            while (DB::value('SELECT 1 FROM categories WHERE slug = ?', [$slug]) !== null) {
                $slug = $base . '-' . $n++;
            }
            DB::insert('categories', ['slug' => $slug, 'name' => $name, 'parent_id' => $parentId,
                'sort_order' => (int) DB::value('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM categories')]);
        }
        Audit::log('category.save', 'category', $id, ['name' => $name], null, $by);
        return [];
    }

    public static function hasValues(int $fieldId): bool
    {
        return DB::value('SELECT 1 FROM listing_values WHERE field_id = ? LIMIT 1', [$fieldId]) !== null
            || DB::value('SELECT 1 FROM listing_value_options WHERE field_id = ? LIMIT 1', [$fieldId]) !== null;
    }

    private static function assertTable(string $table): void
    {
        if (!in_array($table, ['field_definitions', 'field_options', 'categories'], true)) {
            throw new \InvalidArgumentException("Niedozwolona tabela: {$table}");
        }
    }
}
