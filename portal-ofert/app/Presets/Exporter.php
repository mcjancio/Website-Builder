<?php

declare(strict_types=1);

namespace App\Presets;

use App\Core\DB;
use App\Core\Settings;

/**
 * Bieżąca konfiguracja portalu jako preset JSON (format jak presets/*.json).
 * Do kopii zapasowej kryteriów albo jako start dla nowego portalu na tym samym silniku.
 * Ukryte pola/opcje/kategorie są pomijane.
 */
final class Exporter
{
    public static function export(): array
    {
        $preset = ['$schema_version' => 1];
        $meta = (array) Settings::get('preset', []);
        $preset['preset'] = (string) ($meta['name'] ?? 'portal') ?: 'portal';
        $preset['description'] = 'Eksport z panelu admina ' . gmdate('Y-m-d H:i') . ' UTC';
        foreach (Importer::SETTINGS_KEYS as $key) {
            $v = Settings::get($key);
            if ($v !== null) {
                $preset[$key] = $v;
            }
        }

        $cats = DB::fetchAll('SELECT c.slug, c.name, c.icon, p.slug AS parent FROM categories c LEFT JOIN categories p ON p.id = c.parent_id WHERE c.is_active = 1 ORDER BY c.sort_order, c.id');
        $preset['categories'] = array_map(static fn($c) => array_filter(['slug' => $c['slug'], 'name' => $c['name'], 'parent' => $c['parent'], 'icon' => $c['icon']], static fn($v) => $v !== null), $cats);

        $preset['fields'] = [];
        foreach (DB::fetchAll('SELECT * FROM field_definitions WHERE is_active = 1 ORDER BY sort_order, id') as $f) {
            $field = [
                'key' => $f['key'], 'label' => $f['label'], 'type' => $f['type'], 'group' => $f['group_name'],
                'required' => (bool) $f['required'], 'access_level' => (int) $f['access_level'],
                'filterable' => (bool) $f['filterable'], 'filter_ui' => $f['filter_ui'], 'show_in_card' => (bool) $f['show_in_card'],
            ];
            if ($f['help']) {
                $field['help'] = $f['help'];
            }
            if ($f['validation']) {
                $field['validation'] = json_decode((string) $f['validation'], true);
            }
            if ($f['type'] !== 'region') {
                $opts = DB::fetchAll('SELECT `key`, label FROM field_options WHERE field_id = ? AND is_active = 1 ORDER BY sort_order, id', [$f['id']]);
                if ($opts !== []) {
                    $field['options'] = array_map(static fn($o) => [$o['key'], $o['label']], $opts);
                }
            }
            $cs = array_column(DB::fetchAll('SELECT c.slug FROM field_categories fc JOIN categories c ON c.id = fc.category_id WHERE fc.field_id = ? AND c.is_active = 1', [$f['id']]), 'slug');
            if ($cs !== []) {
                $field['categories'] = $cs;
            }
            $preset['fields'][] = $field;
        }
        $preset['pages'] = array_map(static fn($p) => ['slug' => $p['slug'], 'title' => $p['title'], 'in_menu' => (bool) $p['in_menu'], 'body_md' => $p['body_md']],
            DB::fetchAll('SELECT slug, title, in_menu, body_md FROM pages ORDER BY menu_order, id'));
        return $preset;
    }
}
