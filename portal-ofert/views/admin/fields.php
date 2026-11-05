<?php
/** Lista kryteriów i kategorii. @var array $fields @var array $categories */
use App\Core\View;

$moveForm = static function (string $kind, int $id, string $action, string $label, string $back): string {
    return '<form method="post" action="' . e(url('/admin/kryteria/' . $kind . '/' . $id)) . '" class="inline-form">'
        . csrf_field()
        . '<input type="hidden" name="action" value="' . e($action) . '">'
        . '<input type="hidden" name="back" value="' . e($back) . '">'
        . '<button type="submit" class="link-button">' . e($label) . '</button></form>';
};
$parents = array_filter($categories, static fn($c) => $c['parent_id'] === null && $c['is_active']);
?>
<section class="container section admin">
    <?= View::partial('admin/partials/nav', ['active' => 'fields']) ?>
    <div class="section-head">
        <h1><?= e(__('admin.fields')) ?></h1>
        <div class="row-actions">
            <a class="button" href="<?= e(url('/admin/kryteria/nowe')) ?>"><?= e(__('fields.add')) ?></a>
            <a class="button button--secondary" href="<?= e(url('/admin/kryteria/eksport.json')) ?>"><?= e(__('fields.export')) ?></a>
        </div>
    </div>
    <p class="muted"><?= e(__('fields.help')) ?></p>

    <div class="table-wrap">
        <table class="table">
            <thead><tr>
                <th scope="col"><?= e(__('fields.label')) ?></th>
                <th scope="col"><?= e(__('fields.type')) ?></th>
                <th scope="col"><?= e(__('fields.group')) ?></th>
                <th scope="col"><?= e(__('fields.level')) ?></th>
                <th scope="col"><?= e(__('fields.filter')) ?></th>
                <th scope="col"><?= e(__('admin.col_actions')) ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($fields as $f): ?>
                <tr class="<?= $f['is_active'] ? '' : 'row--inactive' ?>">
                    <td>
                        <a href="<?= e(url('/admin/kryteria/' . $f['id'])) ?>"><?= e($f['label']) ?></a>
                        <?php if ($f['required']): ?><span class="req" title="<?= e(__('fields.required')) ?>">*</span><?php endif; ?>
                        <?php if (!$f['is_active']): ?><span class="status"><?= e(__('fields.hidden')) ?></span><?php endif; ?>
                        <br><code class="muted"><?= e($f['key']) ?></code>
                    </td>
                    <td><?= e(__('field_type.' . $f['type'])) ?><?= in_array($f['type'], ['select', 'multiselect', 'region'], true) ? ' (' . (int) $f['options_count'] . ')' : '' ?></td>
                    <td><?= e($f['group_name']) ?></td>
                    <td><span class="level-badge level-badge--<?= (int) $f['access_level'] ?>"><?= e(__('access.level_' . (int) $f['access_level'])) ?></span></td>
                    <td><?= e($f['filterable'] ? __('filter_ui.' . $f['filter_ui']) : '—') ?><?= $f['show_in_card'] ? ' · ' . e(__('fields.in_card_short')) : '' ?></td>
                    <td class="row-actions">
                        <?= $moveForm('pole', (int) $f['id'], 'w-gore', '↑', '/admin/kryteria') ?>
                        <?= $moveForm('pole', (int) $f['id'], 'w-dol', '↓', '/admin/kryteria') ?>
                        <?= $moveForm('pole', (int) $f['id'], $f['is_active'] ? 'ukryj' : 'przywroc', __($f['is_active'] ? 'fields.hide' : 'fields.restore'), '/admin/kryteria') ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <h2 id="kategorie"><?= e(__('term.categories')) ?></h2>
    <div class="table-wrap">
        <table class="table">
            <thead><tr>
                <th scope="col"><?= e(__('fields.category_name')) ?></th>
                <th scope="col" class="num"><?= e(__('term.listings')) ?></th>
                <th scope="col"><?= e(__('admin.col_actions')) ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($categories as $c): ?>
                <tr class="<?= $c['is_active'] ? '' : 'row--inactive' ?>">
                    <td>
                        <form method="post" action="<?= e(url('/admin/kategorie')) ?>" class="inline-form admin-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="category_id" value="<?= (int) $c['id'] ?>">
                            <?php if ($c['parent_id'] !== null): ?><span class="muted" aria-hidden="true">↳</span><?php endif; ?>
                            <label class="visually-hidden" for="cat-<?= (int) $c['id'] ?>"><?= e(__('fields.category_name')) ?></label>
                            <input id="cat-<?= (int) $c['id'] ?>" name="name" value="<?= e($c['name']) ?>" maxlength="150" required>
                            <label class="visually-hidden" for="cat-parent-<?= (int) $c['id'] ?>"><?= e(__('fields.parent')) ?></label>
                            <select id="cat-parent-<?= (int) $c['id'] ?>" name="parent_id">
                                <option value=""><?= e(__('fields.no_parent')) ?></option>
                                <?php foreach ($parents as $p): if ((int) $p['id'] === (int) $c['id']) { continue; } ?>
                                    <option value="<?= (int) $p['id'] ?>" <?= (int) $c['parent_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="button button--small button--secondary"><?= e(__('form.save')) ?></button>
                        </form>
                        <?php if (!$c['is_active']): ?><span class="status"><?= e(__('fields.hidden')) ?></span><?php endif; ?>
                    </td>
                    <td class="num"><?= (int) $c['listings'] ?></td>
                    <td class="row-actions">
                        <?= $moveForm('kategoria', (int) $c['id'], 'w-gore', '↑', '/admin/kryteria#kategorie') ?>
                        <?= $moveForm('kategoria', (int) $c['id'], 'w-dol', '↓', '/admin/kryteria#kategorie') ?>
                        <?= $moveForm('kategoria', (int) $c['id'], $c['is_active'] ? 'ukryj' : 'przywroc', __($c['is_active'] ? 'fields.hide' : 'fields.restore'), '/admin/kryteria#kategorie') ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <form method="post" action="<?= e(url('/admin/kategorie')) ?>" class="admin-filters">
        <?= csrf_field() ?>
        <div class="field">
            <label for="new-cat"><?= e(__('fields.category_add')) ?></label>
            <input id="new-cat" name="name" maxlength="150" required>
        </div>
        <div class="field">
            <label for="new-cat-parent"><?= e(__('fields.parent')) ?></label>
            <select id="new-cat-parent" name="parent_id">
                <option value=""><?= e(__('fields.no_parent')) ?></option>
                <?php foreach ($parents as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="button button--secondary"><?= e(__('fields.category_add_button')) ?></button>
    </form>
</section>
