<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Auth\Audit;
use App\Auth\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Presets\Exporter;
use App\Presets\FieldEditor;
use App\Presets\Importer;

/** Edytor kryteriów ofert i kategorii (tylko admin) — zmiany bez programisty. */
final class FieldsController
{
    public function index(Request $request): Response
    {
        return Response::view('admin/fields', [
            'title' => __('admin.fields'),
            'fields' => DB::fetchAll(
                'SELECT f.*, (SELECT COUNT(*) FROM field_options o WHERE o.field_id = f.id AND o.is_active = 1) AS options_count
                 FROM field_definitions f ORDER BY f.is_active DESC, f.sort_order, f.id'
            ),
            'categories' => DB::fetchAll(
                'SELECT c.*, p.name AS parent_name, (SELECT COUNT(*) FROM listings l WHERE l.category_id = c.id) AS listings
                 FROM categories c LEFT JOIN categories p ON p.id = c.parent_id ORDER BY c.is_active DESC, COALESCE(c.parent_id, c.id), c.parent_id IS NOT NULL, c.sort_order'
            ),
            'noindex' => true,
        ]);
    }

    public function edit(Request $request): Response
    {
        $id = isset($request->params['id']) ? (int) $request->params['id'] : null;
        $field = $id ? DB::fetch('SELECT * FROM field_definitions WHERE id = ?', [$id]) : null;
        if ($id && $field === null) {
            return Response::view('errors/404', [], 404);
        }
        return Response::view('admin/field-edit', $this->formData($field, [], []));
    }

    public function save(Request $request): Response
    {
        $id = isset($request->params['id']) ? (int) $request->params['id'] : null;
        $errors = FieldEditor::saveField($id, $request->post, Auth::id());
        if ($errors !== []) {
            $field = $id ? DB::fetch('SELECT * FROM field_definitions WHERE id = ?', [$id]) : null;
            return Response::html(View::render('admin/field-edit', $this->formData($field, $errors, $request->post)), 422);
        }
        Session::flash('success', __('admin.saved'));
        if ($id === null) {
            $id = (int) DB::value('SELECT id FROM field_definitions WHERE `key` = ?', [$request->str('key')]);
        }
        return Response::redirect(url('/admin/kryteria/' . $id));
    }

    /** Ukryj / przywróć / w górę / w dół — dla pól, opcji i kategorii. */
    public function action(Request $request): Response
    {
        $table = match ($request->params['kind']) {
            'pole' => 'field_definitions',
            'opcja' => 'field_options',
            'kategoria' => 'categories',
        };
        $id = (int) $request->params['id'];
        match ($request->str('action')) {
            'ukryj' => FieldEditor::setActive($table, $id, false, Auth::id()),
            'przywroc' => FieldEditor::setActive($table, $id, true, Auth::id()),
            'w-gore' => FieldEditor::move($table, $id, -1),
            'w-dol' => FieldEditor::move($table, $id, 1),
            default => null,
        };
        $back = $request->str('back');
        return Response::redirect(str_starts_with($back, '/admin/') ? url($back) : url('/admin/kryteria'));
    }

    public function saveOption(Request $request): Response
    {
        $fieldId = (int) $request->params['id'];
        $optionId = $request->str('option_id') !== '' ? (int) $request->str('option_id') : null;
        $errors = FieldEditor::saveOption($fieldId, $optionId, $request->str('key'), $request->str('label'), Auth::id());
        Session::flash($errors ? 'error' : 'success', $errors ? implode(' ', $errors) : __('admin.saved'));
        return Response::redirect(url('/admin/kryteria/' . $fieldId) . '#opcje');
    }

    public function saveCategory(Request $request): Response
    {
        $id = $request->str('category_id') !== '' ? (int) $request->str('category_id') : null;
        $parent = $request->str('parent_id') !== '' ? (int) $request->str('parent_id') : null;
        $errors = FieldEditor::saveCategory($id, $request->str('name'), $parent, Auth::id());
        Session::flash($errors ? 'error' : 'success', $errors ? implode(' ', $errors) : __('admin.saved'));
        return Response::redirect(url('/admin/kryteria') . '#kategorie');
    }

    public function export(Request $request): Response
    {
        $json = json_encode(Exporter::export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        Audit::log('preset.export', null, null, [], $request, Auth::id());
        return (new Response((string) $json, 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="preset-' . gmdate('Y-m-d') . '.json"',
        ]));
    }

    private function formData(?array $field, array $errors, array $old): array
    {
        $id = $field ? (int) $field['id'] : null;
        return [
            'title' => $field ? __('fields.edit_title', ['label' => $field['label']]) : __('fields.new_title'),
            'field' => $field,
            'errors' => $errors,
            'old' => $old,
            'types' => Importer::FIELD_TYPES,
            'hasValues' => $id ? FieldEditor::hasValues($id) : false,
            'options' => $id ? DB::fetchAll('SELECT * FROM field_options WHERE field_id = ? ORDER BY is_active DESC, sort_order, id', [$id]) : [],
            'categories' => DB::fetchAll('SELECT id, parent_id, name FROM categories WHERE is_active = 1 ORDER BY COALESCE(parent_id, id), parent_id IS NOT NULL, sort_order'),
            'fieldCategories' => $id ? array_map('intval', array_column(DB::fetchAll('SELECT category_id FROM field_categories WHERE field_id = ?', [$id]), 'category_id')) : [],
            'groups' => array_column(DB::fetchAll('SELECT DISTINCT group_name FROM field_definitions ORDER BY group_name'), 'group_name'),
            'noindex' => true,
        ];
    }
}
