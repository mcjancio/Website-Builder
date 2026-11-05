<?php
/** @var ?array $page @var array $errors */
use App\Core\View;

$isNew = empty($page['id']);
?>
<section class="container section admin">
    <?= View::partial('admin/partials/nav', ['active' => 'pages']) ?>
    <p class="breadcrumbs"><a href="<?= e(url('/admin/strony')) ?>">← <?= e(__('admin.pages')) ?></a></p>
    <h1><?= e($isNew ? __('pages.new') : $page['title']) ?></h1>
    <?php if (!$isNew && isset($page['version'])): ?>
        <p class="muted"><?= e(__('pages.version_info', ['version' => (int) $page['version']])) ?></p>
    <?php endif; ?>

    <form method="post" action="<?= e(url($isNew ? '/admin/strony/nowa' : '/admin/strony/' . $page['id'])) ?>" class="form form--wide" novalidate>
        <?= csrf_field() ?>
        <?php if ($errors !== []): ?><p class="form-summary" role="alert"><?= e(__('form.fix_errors', ['count' => count($errors)])) ?></p><?php endif; ?>
        <div class="field-row">
            <div class="field">
                <label for="title"><?= e(__('pages.title_label')) ?> <span class="req" aria-hidden="true">*</span></label>
                <input id="title" name="title" value="<?= e($page['title'] ?? '') ?>" maxlength="200" required<?= field_aria($errors, 'title') ?>>
                <?= field_error($errors, 'title') ?>
            </div>
            <div class="field">
                <label for="slug"><?= e(__('pages.address')) ?></label>
                <input id="slug" name="slug" value="<?= e($page['slug'] ?? '') ?>" maxlength="120" aria-describedby="slug-hint"<?= field_aria($errors, 'slug') ?>>
                <p class="hint" id="slug-hint"><?= e(__('pages.slug_hint')) ?></p>
                <?= field_error($errors, 'slug') ?>
            </div>
        </div>
        <div class="field">
            <label for="body_md"><?= e(__('pages.body')) ?></label>
            <textarea id="body_md" name="body_md" rows="18" data-md-preview="<?= e(url('/podglad-markdown')) ?>" data-md-show="<?= e(__('wizard.md_preview')) ?>" data-md-hide="<?= e(__('wizard.md_edit')) ?>" aria-describedby="body-hint"><?= e($page['body_md'] ?? '') ?></textarea>
            <p class="hint" id="body-hint"><?= e(__('listing.markdown_hint')) ?></p>
        </div>
        <div class="field field--check">
            <label><input type="checkbox" name="in_menu" value="1" <?= !empty($page['in_menu']) ? 'checked' : '' ?>> <span><?= e(__('pages.in_menu_label')) ?></span></label>
        </div>
        <?php if (!$isNew): ?><p class="hint"><?= e(__('pages.version_hint')) ?></p><?php endif; ?>
        <button type="submit" class="button"><?= e(__('form.save')) ?></button>
    </form>

    <?php if (!$isNew): ?>
        <form method="post" action="<?= e(url('/admin/strony/' . $page['id'])) ?>" class="inline-form danger-zone" data-confirm="<?= e(__('pages.delete_confirm')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <button type="submit" class="link-button link-button--danger"><?= e(__('pages.delete')) ?></button>
        </form>
    <?php endif; ?>
</section>
