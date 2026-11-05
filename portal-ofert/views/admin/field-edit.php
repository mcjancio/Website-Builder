<?php
/**
 * Edycja / dodanie kryterium.
 * @var ?array $field @var array $errors @var array $old @var array $types @var bool $hasValues
 * @var array $options @var array $categories @var array $fieldCategories @var array $groups
 */
use App\Core\View;

$isNew = $field === null;
$v = static function (string $k, mixed $default = '') use ($old, $field): mixed {
    return $old !== [] ? ($old[$k] ?? ($default === '' ? '' : null)) : ($field[$k] ?? $default);
};
$validation = $old !== [] ? (array) ($old['validation'] ?? []) : (json_decode((string) ($field['validation'] ?? ''), true) ?: []);
$selectedCats = $old !== [] ? array_map('intval', (array) ($old['categories'] ?? [])) : $fieldCategories;
$type = (string) ($v('type') ?: ($field['type'] ?? 'text'));
$hasOptions = in_array($field['type'] ?? '', ['select', 'multiselect', 'region'], true);
?>
<section class="container section admin">
    <?= View::partial('admin/partials/nav', ['active' => 'fields']) ?>
    <p class="breadcrumbs"><a href="<?= e(url('/admin/kryteria')) ?>">← <?= e(__('admin.fields')) ?></a></p>
    <h1><?= e($title) ?></h1>

    <form method="post" action="<?= e(url($isNew ? '/admin/kryteria/nowe' : '/admin/kryteria/' . $field['id'])) ?>" class="form form--wide" novalidate>
        <?= csrf_field() ?>
        <?php if ($errors !== []): ?><p class="form-summary" role="alert"><?= e(__('form.fix_errors', ['count' => count($errors)])) ?></p><?php endif; ?>

        <div class="field-row">
            <div class="field">
                <label for="label"><?= e(__('fields.label')) ?> <span class="req" aria-hidden="true">*</span></label>
                <input id="label" name="label" value="<?= e($v('label')) ?>" maxlength="150" required<?= field_aria($errors, 'label') ?>>
                <?= field_error($errors, 'label') ?>
            </div>
            <div class="field">
                <label for="key"><?= e(__('fields.key')) ?></label>
                <?php if ($isNew): ?>
                    <input id="key" name="key" value="<?= e($v('key')) ?>" maxlength="64" pattern="[a-z][a-z0-9_]*" aria-describedby="key-hint"<?= field_aria($errors, 'key') ?>>
                    <p class="hint" id="key-hint"><?= e(__('fields.key_hint')) ?></p>
                    <?= field_error($errors, 'key') ?>
                <?php else: ?>
                    <input id="key" value="<?= e($field['key']) ?>" readonly aria-describedby="key-hint">
                    <p class="hint" id="key-hint"><?= e(__('fields.key_locked')) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="field-row">
            <div class="field">
                <label for="type"><?= e(__('fields.type')) ?></label>
                <select id="type" name="type" <?= $hasValues ? 'disabled aria-describedby="type-hint"' : '' ?><?= field_aria($errors, 'type') ?>>
                    <?php foreach ($types as $t): ?>
                        <option value="<?= e($t) ?>" <?= $type === $t ? 'selected' : '' ?>><?= e(__('field_type.' . $t)) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($hasValues): ?><p class="hint" id="type-hint"><?= e(__('fields.err_type_locked')) ?></p><?php endif; ?>
                <?= field_error($errors, 'type') ?>
            </div>
            <div class="field">
                <label for="group_name"><?= e(__('fields.group')) ?></label>
                <input id="group_name" name="group_name" value="<?= e($v('group_name', 'Parametry')) ?>" maxlength="80" list="groups">
                <datalist id="groups"><?php foreach ($groups as $g): ?><option value="<?= e($g) ?>"><?php endforeach; ?></datalist>
            </div>
        </div>

        <div class="field">
            <label for="help"><?= e(__('fields.help_text')) ?></label>
            <input id="help" name="help" value="<?= e($v('help')) ?>" maxlength="300">
        </div>

        <fieldset class="field-group">
            <legend><?= e(__('fields.level')) ?></legend>
            <?php foreach ([1, 2, 3] as $lvl): ?>
                <label class="check"><input type="radio" name="access_level" value="<?= $lvl ?>" <?= (int) $v('access_level', 2) === $lvl ? 'checked' : '' ?>> <span><?= e(__('access.level_' . $lvl)) ?> — <span class="muted"><?= e(__('fields.level_' . $lvl . '_hint')) ?></span></span></label>
            <?php endforeach; ?>
            <?= field_error($errors, 'access_level') ?>
        </fieldset>

        <fieldset class="field-group">
            <legend><?= e(__('fields.behaviour')) ?></legend>
            <label class="check"><input type="checkbox" name="required" value="1" <?= $v('required', 0) ? 'checked' : '' ?>> <span><?= e(__('fields.required')) ?></span></label>
            <label class="check"><input type="checkbox" name="show_in_card" value="1" <?= $v('show_in_card', 0) ? 'checked' : '' ?>> <span><?= e(__('fields.in_card')) ?></span></label>
            <label class="check"><input type="checkbox" name="filterable" value="1" <?= $v('filterable', 0) ? 'checked' : '' ?><?= field_aria($errors, 'filterable') ?>> <span><?= e(__('fields.filterable')) ?></span></label>
            <?= field_error($errors, 'filterable') ?>
            <div class="field">
                <label for="filter_ui"><?= e(__('fields.filter')) ?></label>
                <select id="filter_ui" name="filter_ui"<?= field_aria($errors, 'filter_ui') ?>>
                    <?php foreach (\App\Presets\Importer::FILTER_UI as $ui): ?>
                        <option value="<?= e($ui) ?>" <?= $v('filter_ui', 'none') === $ui ? 'selected' : '' ?>><?= e(__('filter_ui.' . $ui)) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= field_error($errors, 'filter_ui') ?>
            </div>
        </fieldset>

        <fieldset class="field-group">
            <legend><?= e(__('fields.validation')) ?></legend>
            <div class="field-row">
                <?php foreach (['min', 'max', 'maxlength'] as $rule): ?>
                    <div class="field">
                        <label for="val-<?= $rule ?>"><?= e(__('fields.val_' . $rule)) ?></label>
                        <input id="val-<?= $rule ?>" name="validation[<?= $rule ?>]" value="<?= e((string) ($validation[$rule] ?? '')) ?>" inputmode="decimal" class="input--short">
                    </div>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <fieldset class="field-group">
            <legend><?= e(__('fields.categories')) ?></legend>
            <p class="hint"><?= e(__('fields.categories_hint')) ?></p>
            <div class="checks">
                <?php foreach ($categories as $c): ?>
                    <label class="check"><input type="checkbox" name="categories[]" value="<?= (int) $c['id'] ?>" <?= in_array((int) $c['id'], $selectedCats, true) ? 'checked' : '' ?>> <span><?= $c['parent_id'] ? '↳ ' : '' ?><?= e($c['name']) ?></span></label>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <button type="submit" class="button"><?= e(__('form.save')) ?></button>
    </form>

    <?php if (!$isNew && $hasOptions): ?>
        <h2 id="opcje"><?= e(__('fields.options')) ?></h2>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th scope="col"><?= e(__('fields.label')) ?></th><th scope="col"><?= e(__('fields.key')) ?></th><th scope="col"><?= e(__('admin.col_actions')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($options as $o): $back = '/admin/kryteria/' . $field['id'] . '#opcje'; ?>
                    <tr class="<?= $o['is_active'] ? '' : 'row--inactive' ?>">
                        <td>
                            <form method="post" action="<?= e(url('/admin/kryteria/' . $field['id'] . '/opcje')) ?>" class="inline-form admin-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="option_id" value="<?= (int) $o['id'] ?>">
                                <label class="visually-hidden" for="opt-<?= (int) $o['id'] ?>"><?= e(__('fields.label')) ?></label>
                                <input id="opt-<?= (int) $o['id'] ?>" name="label" value="<?= e($o['label']) ?>" maxlength="200" required>
                                <button type="submit" class="button button--small button--secondary"><?= e(__('form.save')) ?></button>
                            </form>
                            <?php if (!$o['is_active']): ?><span class="status"><?= e(__('fields.hidden')) ?></span><?php endif; ?>
                        </td>
                        <td><code><?= e($o['key']) ?></code></td>
                        <td class="row-actions">
                            <?php foreach ([['w-gore', '↑'], ['w-dol', '↓'], [$o['is_active'] ? 'ukryj' : 'przywroc', __($o['is_active'] ? 'fields.hide' : 'fields.restore')]] as [$act, $lab]): ?>
                                <form method="post" action="<?= e(url('/admin/kryteria/opcja/' . $o['id'])) ?>" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="<?= e($act) ?>">
                                    <input type="hidden" name="back" value="<?= e($back) ?>">
                                    <button type="submit" class="link-button"><?= e($lab) ?></button>
                                </form>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <form method="post" action="<?= e(url('/admin/kryteria/' . $field['id'] . '/opcje')) ?>" class="admin-filters">
            <?= csrf_field() ?>
            <div class="field">
                <label for="new-opt"><?= e(__('fields.option_add')) ?></label>
                <input id="new-opt" name="label" maxlength="200" required>
            </div>
            <div class="field">
                <label for="new-opt-key"><?= e(__('fields.key')) ?> <span class="muted">(<?= e(__('form.optional')) ?>)</span></label>
                <input id="new-opt-key" name="key" maxlength="64">
            </div>
            <button type="submit" class="button button--secondary"><?= e(__('fields.option_add_button')) ?></button>
        </form>
    <?php endif; ?>
</section>
