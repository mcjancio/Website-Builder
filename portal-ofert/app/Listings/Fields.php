<?php

declare(strict_types=1);

namespace App\Listings;

use App\Core\DB;

/**
 * Definicje pól ofert z bazy (zaimportowany preset): typ, grupa, poziom dostępu, opcje.
 * Kod nie zna żadnego konkretnego pola — wszystko przychodzi stąd.
 */
final class Fields
{
    private static ?array $all = null;

    /** @return array<string,array> wszystkie aktywne pola: key => definicja (z 'options' i 'category_ids') */
    public static function all(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }
        $fields = [];
        foreach (DB::fetchAll('SELECT * FROM field_definitions WHERE is_active = 1 ORDER BY sort_order, id') as $f) {
            $f['id'] = (int) $f['id'];
            $f['access_level'] = (int) $f['access_level'];
            $f['required'] = (bool) $f['required'];
            $f['filterable'] = (bool) $f['filterable'];
            $f['show_in_card'] = (bool) $f['show_in_card'];
            $f['validation'] = $f['validation'] !== null ? (json_decode((string) $f['validation'], true) ?: []) : [];
            $f['options'] = [];
            $f['category_ids'] = [];
            $fields[$f['key']] = $f;
        }
        $byId = array_column($fields, 'key', 'id');
        foreach (DB::fetchAll('SELECT id, field_id, `key`, label FROM field_options WHERE is_active = 1 ORDER BY sort_order, id') as $o) {
            if (isset($byId[$o['field_id']])) {
                $fields[$byId[$o['field_id']]]['options'][$o['key']] = ['id' => (int) $o['id'], 'label' => $o['label']];
            }
        }
        foreach (DB::fetchAll('SELECT field_id, category_id FROM field_categories') as $fc) {
            if (isset($byId[$fc['field_id']])) {
                $fields[$byId[$fc['field_id']]]['category_ids'][] = (int) $fc['category_id'];
            }
        }
        return self::$all = $fields;
    }

    /**
     * Pola dla kategorii: bez przypisania = wszystkie kategorie; przypisane do kategorii
     * nadrzędnej działają też w podkategoriach.
     * @return array<string,array>
     */
    public static function forCategory(?int $categoryId): array
    {
        $chain = $categoryId !== null ? self::categoryChain($categoryId) : [];
        return array_filter(self::all(), static fn($f) => $f['category_ids'] === [] || array_intersect($f['category_ids'], $chain) !== []);
    }

    /** @return array<string,array<string,array>> grupa => pola (kolejność z presetu) */
    public static function grouped(array $fields): array
    {
        $groups = [];
        foreach ($fields as $key => $f) {
            $groups[$f['group_name']][$key] = $f;
        }
        return $groups;
    }

    /** Opcje z ukrytymi włącznie — do wyświetlania starych ofert z wycofaną opcją. */
    public static function optionLabel(int $optionId): ?string
    {
        static $cache = [];
        return $cache[$optionId] ??= DB::value('SELECT label FROM field_options WHERE id = ?', [$optionId]);
    }

    public static function flush(): void
    {
        self::$all = null;
    }

    /** @return int[] kategoria i jej przodkowie */
    private static function categoryChain(int $id): array
    {
        $chain = [];
        $guard = 0;
        while ($id && $guard++ < 10) {
            $chain[] = $id;
            $id = (int) (DB::value('SELECT parent_id FROM categories WHERE id = ?', [$id]) ?? 0);
        }
        return $chain;
    }
}
