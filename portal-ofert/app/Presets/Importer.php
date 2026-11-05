<?php

declare(strict_types=1);

namespace App\Presets;

use App\Core\DB;
use App\Core\Settings;

/**
 * Import presetu JSON (kategorie, pola, opcje, strony, ustawienia) do bazy.
 *
 * Idempotentny: drugi import tego samego pliku niczego nie zmienia ani nie dubluje.
 * Bezpieczny dla działającego portalu:
 *  - pola i opcje dopasowywane po stałym `key`, kategorie po `slug`;
 *  - etykiety, kolejność, flagi są aktualizowane;
 *  - NIC nie jest usuwane; z opcją `sync` elementy spoza presetu są ukrywane (is_active = 0);
 *  - zmiana typu pola, które ma już wartości w ofertach, jest blokowana;
 *  - strony treści i ustawienia są tylko dodawane, chyba że `overwrite` (żeby import
 *    nie nadpisał zmian zrobionych później w panelu).
 */
final class Importer
{
    public const FIELD_TYPES = ['text', 'textarea', 'int', 'decimal', 'money', 'percent', 'date',
        'select', 'multiselect', 'bool', 'url', 'url_list', 'region'];
    public const FILTER_UI = ['none', 'select', 'checkboxes', 'range', 'toggle'];
    public const SETTINGS_KEYS = ['portal', 'registration', 'limits', 'access_levels', 'ai'];

    /** Pola typu `region` bez własnych opcji dostają listę województw. */
    public const REGIONS = [
        'dolnoslaskie' => 'dolnośląskie', 'kujawsko-pomorskie' => 'kujawsko-pomorskie',
        'lubelskie' => 'lubelskie', 'lubuskie' => 'lubuskie', 'lodzkie' => 'łódzkie',
        'malopolskie' => 'małopolskie', 'mazowieckie' => 'mazowieckie', 'opolskie' => 'opolskie',
        'podkarpackie' => 'podkarpackie', 'podlaskie' => 'podlaskie', 'pomorskie' => 'pomorskie',
        'slaskie' => 'śląskie', 'swietokrzyskie' => 'świętokrzyskie',
        'warminsko-mazurskie' => 'warmińsko-mazurskie', 'wielkopolskie' => 'wielkopolskie',
        'zachodniopomorskie' => 'zachodniopomorskie',
    ];

    private array $report = [];

    public function __construct(private bool $sync = false, private bool $overwrite = false)
    {
    }

    public static function readFile(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Nie znaleziono pliku presetu: {$path}");
        }
        try {
            $data = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("Plik {$path} nie jest poprawnym JSON: " . $e->getMessage());
        }
        if (!is_array($data)) {
            throw new \RuntimeException("Plik {$path} musi zawierać obiekt JSON.");
        }
        return $data;
    }

    /**
     * Sprawdza strukturę presetu bez dotykania bazy.
     * @return string[] lista błędów (pusta = poprawny)
     */
    public static function validate(array $p): array
    {
        $errors = [];
        if (!is_string($p['preset'] ?? null) || !preg_match('/^[a-z0-9\-]+$/', $p['preset'])) {
            $errors[] = 'Brak lub niepoprawna nazwa "preset" (małe litery, cyfry, myślniki).';
        }
        if (!is_array($p['fields'] ?? null) || $p['fields'] === []) {
            $errors[] = 'Preset musi zawierać niepustą listę "fields".';
        }

        $catSlugs = [];
        foreach ($p['categories'] ?? [] as $i => $c) {
            $slug = $c['slug'] ?? null;
            if (!is_string($slug) || !preg_match('/^[a-z0-9\-]{1,120}$/', $slug)) {
                $errors[] = "categories[{$i}]: niepoprawny slug.";
                continue;
            }
            if (isset($catSlugs[$slug])) {
                $errors[] = "categories: powtórzony slug \"{$slug}\".";
            }
            if (!is_string($c['name'] ?? null) || trim($c['name']) === '') {
                $errors[] = "categories[{$slug}]: brak nazwy.";
            }
            $catSlugs[$slug] = true;
        }

        $keys = [];
        foreach ($p['fields'] ?? [] as $i => $f) {
            $key = $f['key'] ?? null;
            $where = is_string($key) ? "fields[{$key}]" : "fields[{$i}]";
            if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key)) {
                $errors[] = "{$where}: niepoprawny key (małe litery, cyfry, podkreślenia).";
                continue;
            }
            if (isset($keys[$key])) {
                $errors[] = "fields: powtórzony key \"{$key}\".";
            }
            $keys[$key] = true;
            if (!is_string($f['label'] ?? null) || trim($f['label']) === '') {
                $errors[] = "{$where}: brak label.";
            }
            if (!in_array($f['type'] ?? null, self::FIELD_TYPES, true)) {
                $errors[] = "{$where}: nieznany type \"" . ($f['type'] ?? '') . '".';
            }
            if (isset($f['access_level']) && !in_array($f['access_level'], [1, 2, 3], true)) {
                $errors[] = "{$where}: access_level musi być 1, 2 lub 3.";
            }
            if (isset($f['filter_ui']) && !in_array($f['filter_ui'], self::FILTER_UI, true)) {
                $errors[] = "{$where}: nieznany filter_ui.";
            }
            if (in_array($f['type'] ?? null, ['select', 'multiselect'], true) && empty($f['options'])) {
                $errors[] = "{$where}: pole wyboru musi mieć options.";
            }
            $optKeys = [];
            foreach ($f['options'] ?? [] as $j => $o) {
                [$ok, $ol] = self::option($o);
                if ($ok === null || !preg_match('/^[a-z0-9_\-]{1,64}$/', $ok) || $ol === '') {
                    $errors[] = "{$where}.options[{$j}]: oczekiwano [\"klucz\", \"Etykieta\"] lub {\"key\",\"label\"}.";
                    continue;
                }
                if (isset($optKeys[$ok])) {
                    $errors[] = "{$where}: powtórzona opcja \"{$ok}\".";
                }
                $optKeys[$ok] = true;
            }
            foreach ($f['categories'] ?? [] as $slug) {
                if (!isset($catSlugs[$slug])) {
                    $errors[] = "{$where}: kategoria \"{$slug}\" nie istnieje w presecie.";
                }
            }
        }

        foreach ($p['pages'] ?? [] as $i => $pg) {
            if (!is_string($pg['slug'] ?? null) || !preg_match('/^[a-z0-9\-]{1,120}$/', $pg['slug'])
                || !is_string($pg['title'] ?? null)) {
                $errors[] = "pages[{$i}]: wymagane slug i title.";
            }
        }
        return $errors;
    }

    /** @return array<string,int> licznik zmian: categories.created, fields.updated, … */
    public function import(array $preset, bool $dryRun = false): array
    {
        $errors = self::validate($preset);
        if ($errors !== []) {
            throw new PresetException($errors);
        }
        $this->report = [];

        $pdo = DB::pdo();
        $pdo->beginTransaction();
        try {
            $catIds = $this->importCategories($preset['categories'] ?? []);
            $this->importFields($preset['fields'], $catIds);
            $this->importPages($preset['pages'] ?? []);
            $this->importSettings($preset);
            $dryRun ? $pdo->rollBack() : $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        Settings::flush();
        ksort($this->report);
        return $this->report;
    }

    private function importCategories(array $categories): array
    {
        $existing = [];
        foreach (DB::fetchAll('SELECT id, slug, name, sort_order, is_active FROM categories') as $row) {
            $existing[$row['slug']] = $row;
        }

        $ids = [];
        foreach (array_values($categories) as $pos => $c) {
            $data = ['name' => trim($c['name']), 'sort_order' => ($pos + 1) * 10, 'is_active' => 1,
                'icon' => $c['icon'] ?? null];
            $row = $existing[$c['slug']] ?? null;
            if ($row === null) {
                $ids[$c['slug']] = DB::insert('categories', ['slug' => $c['slug'], ...$data]);
                $this->count('categories.created');
                continue;
            }
            $ids[$c['slug']] = (int) $row['id'];
            if ($row['name'] !== $data['name'] || (int) $row['sort_order'] !== $data['sort_order'] || !(int) $row['is_active']) {
                DB::update('categories', $data, ['id' => $row['id']]);
                $this->count('categories.updated');
            } else {
                $this->count('categories.unchanged');
            }
        }

        // Rodzic: dopiero gdy wszystkie kategorie mają ID.
        foreach ($categories as $c) {
            $parent = isset($c['parent']) ? ($ids[$c['parent']] ?? null) : null;
            DB::query('UPDATE categories SET parent_id = ? WHERE id = ?', [$parent, $ids[$c['slug']]]);
        }

        if ($this->sync) {
            foreach ($existing as $slug => $row) {
                if (!isset($ids[$slug]) && (int) $row['is_active'] === 1) {
                    DB::update('categories', ['is_active' => 0], ['id' => $row['id']]);
                    $this->count('categories.hidden');
                }
            }
        }
        return $ids;
    }

    private function importFields(array $fields, array $catIds): void
    {
        $existing = [];
        foreach (DB::fetchAll('SELECT * FROM field_definitions') as $row) {
            $existing[$row['key']] = $row;
        }

        $seen = [];
        foreach (array_values($fields) as $pos => $f) {
            $data = [
                'label' => trim($f['label']),
                'help' => isset($f['help']) ? trim((string) $f['help']) : null,
                'type' => $f['type'],
                'group_name' => trim((string) ($f['group'] ?? 'Parametry')),
                'required' => (int) (bool) ($f['required'] ?? false),
                'access_level' => (int) ($f['access_level'] ?? 2),
                'filterable' => (int) (bool) ($f['filterable'] ?? false),
                'filter_ui' => $f['filter_ui'] ?? (($f['filterable'] ?? false) ? 'select' : 'none'),
                'show_in_card' => (int) (bool) ($f['show_in_card'] ?? false),
                'validation' => isset($f['validation'])
                    ? json_encode($f['validation'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
                'sort_order' => ($pos + 1) * 10,
                'is_active' => 1,
            ];

            $row = $existing[$f['key']] ?? null;
            if ($row === null) {
                $fieldId = DB::insert('field_definitions', ['key' => $f['key'], ...$data]);
                $this->count('fields.created');
            } else {
                $fieldId = (int) $row['id'];
                if ($row['type'] !== $data['type'] && $this->fieldHasValues($fieldId)) {
                    throw new PresetException([sprintf(
                        'Pole "%s" ma już wartości w ofertach — nie można zmienić typu z %s na %s. '
                        . 'Dodaj nowe pole z innym key i ukryj stare.',
                        $f['key'], $row['type'], $data['type']
                    )]);
                }
                if ($this->differs($row, $data)) {
                    DB::update('field_definitions', $data, ['id' => $fieldId]);
                    $this->count('fields.updated');
                } else {
                    $this->count('fields.unchanged');
                }
            }
            $seen[$f['key']] = true;

            $options = $f['options'] ?? [];
            if ($f['type'] === 'region' && $options === []) {
                $options = array_map(null, array_keys(self::REGIONS), array_values(self::REGIONS));
            }
            $this->importOptions($fieldId, $options);
            $this->syncFieldCategories($fieldId, array_map(static fn($s) => $catIds[$s], $f['categories'] ?? []));
        }

        if ($this->sync) {
            foreach ($existing as $key => $row) {
                if (!isset($seen[$key]) && (int) $row['is_active'] === 1) {
                    DB::update('field_definitions', ['is_active' => 0], ['id' => $row['id']]);
                    $this->count('fields.hidden');
                }
            }
        }
    }

    private function importOptions(int $fieldId, array $options): void
    {
        $existing = [];
        foreach (DB::fetchAll('SELECT id, `key`, label, sort_order, is_active FROM field_options WHERE field_id = ?', [$fieldId]) as $row) {
            $existing[$row['key']] = $row;
        }
        $seen = [];
        foreach (array_values($options) as $pos => $o) {
            [$key, $label] = self::option($o);
            $data = ['label' => $label, 'sort_order' => ($pos + 1) * 10, 'is_active' => 1];
            $row = $existing[$key] ?? null;
            if ($row === null) {
                DB::insert('field_options', ['field_id' => $fieldId, 'key' => $key, ...$data]);
                $this->count('options.created');
            } elseif ($this->differs($row, $data)) {
                DB::update('field_options', $data, ['id' => $row['id']]);
                $this->count('options.updated');
            } else {
                $this->count('options.unchanged');
            }
            $seen[$key] = true;
        }
        if ($this->sync) {
            foreach ($existing as $key => $row) {
                if (!isset($seen[$key]) && (int) $row['is_active'] === 1) {
                    DB::update('field_options', ['is_active' => 0], ['id' => $row['id']]);
                    $this->count('options.hidden');
                }
            }
        }
    }

    private function syncFieldCategories(int $fieldId, array $categoryIds): void
    {
        $current = array_map('intval', array_column(
            DB::fetchAll('SELECT category_id FROM field_categories WHERE field_id = ?', [$fieldId]),
            'category_id'
        ));
        sort($current);
        $wanted = array_values(array_unique(array_map('intval', $categoryIds)));
        sort($wanted);
        if ($current === $wanted) {
            return;
        }
        DB::query('DELETE FROM field_categories WHERE field_id = ?', [$fieldId]);
        foreach ($wanted as $cid) {
            DB::insert('field_categories', ['field_id' => $fieldId, 'category_id' => $cid]);
        }
        $this->count('field_categories.changed');
    }

    private function importPages(array $pages): void
    {
        foreach (array_values($pages) as $pos => $pg) {
            $data = [
                'title' => trim($pg['title']),
                'body_md' => (string) ($pg['body_md'] ?? ''),
                'in_menu' => (int) (bool) ($pg['in_menu'] ?? false),
                'menu_order' => ($pos + 1) * 10,
            ];
            $row = DB::fetch('SELECT id, title, body_md, in_menu, menu_order FROM pages WHERE slug = ?', [$pg['slug']]);
            if ($row === null) {
                DB::insert('pages', ['slug' => $pg['slug'], ...$data]);
                $this->count('pages.created');
            } elseif ($this->overwrite && $this->differs($row, $data)) {
                DB::query(
                    'UPDATE pages SET title = ?, body_md = ?, in_menu = ?, menu_order = ?, version = version + 1 WHERE id = ?',
                    [$data['title'], $data['body_md'], $data['in_menu'], $data['menu_order'], $row['id']]
                );
                $this->count('pages.updated');
            } else {
                $this->count('pages.kept');
            }
        }
    }

    private function importSettings(array $preset): void
    {
        $values = ['preset' => ['name' => $preset['preset'], 'description' => $preset['description'] ?? '']];
        foreach (self::SETTINGS_KEYS as $key) {
            if (isset($preset[$key])) {
                $values[$key] = $preset[$key];
            }
        }
        foreach ($values as $key => $value) {
            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $current = DB::value('SELECT `value` FROM settings WHERE `key` = ?', [$key]);
            if ($current === null) {
                DB::insert('settings', ['key' => $key, 'value' => $json]);
                $this->count('settings.created');
            } elseif ($this->overwrite && json_decode((string) $current, true) !== $value) {
                DB::update('settings', ['value' => $json], ['key' => $key]);
                $this->count('settings.updated');
            } else {
                $this->count('settings.kept');
            }
        }
    }

    private function fieldHasValues(int $fieldId): bool
    {
        return DB::value('SELECT 1 FROM listing_values WHERE field_id = ? LIMIT 1', [$fieldId]) !== null
            || DB::value('SELECT 1 FROM listing_value_options WHERE field_id = ? LIMIT 1', [$fieldId]) !== null;
    }

    /** Porównanie wiersza z bazy z nowymi danymi (bazy zwracają liczby/JSON w różnych formach). */
    private function differs(array $row, array $data): bool
    {
        foreach ($data as $col => $value) {
            $current = $row[$col] ?? null;
            if ($col === 'validation') {
                $a = $current === null ? null : json_decode((string) $current, true);
                $b = $value === null ? null : json_decode((string) $value, true);
                if ($a != $b) {
                    return true;
                }
                continue;
            }
            if ($current === null || $value === null ? $current !== $value : (string) $current !== (string) $value) {
                return true;
            }
        }
        return false;
    }

    /** @return array{0:?string,1:string} [klucz, etykieta] z ["k","Etykieta"] lub {"key":…,"label":…} */
    private static function option(mixed $o): array
    {
        if (is_array($o) && array_is_list($o) && count($o) === 2) {
            return [is_string($o[0]) ? $o[0] : null, trim((string) $o[1])];
        }
        if (is_array($o) && isset($o['key'], $o['label'])) {
            return [is_string($o['key']) ? $o['key'] : null, trim((string) $o['label'])];
        }
        return [null, ''];
    }

    private function count(string $what): void
    {
        $this->report[$what] = ($this->report[$what] ?? 0) + 1;
    }
}
