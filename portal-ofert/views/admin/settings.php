<?php
/**
 * @var array $portal @var array $terms @var array $termKeys @var array $defaults @var array $errors @var array $old
 * @var array $ai @var bool $aiKey @var string $aiModel @var float $aiSpend @var int $aiCalls
 */
use App\Core\View;

$val = static function (string $k, string $fromPortal) use ($old): string {
    return array_key_exists($k, $old) && is_scalar($old[$k]) ? (string) $old[$k] : $fromPortal;
};
?>
<section class="container section admin">
    <?= View::partial('admin/partials/nav', ['active' => 'settings']) ?>
    <h1><?= e(__('admin.settings')) ?></h1>

    <form method="post" action="<?= e(url('/admin/ustawienia')) ?>" class="form form--wide" novalidate>
        <?= csrf_field() ?>
        <?php if ($errors !== []): ?><p class="form-summary" role="alert"><?= e(__('form.fix_errors', ['count' => count($errors)])) ?></p><?php endif; ?>

        <fieldset class="field-group">
            <legend><?= e(__('settings.portal')) ?></legend>
            <div class="field">
                <label for="s-name"><?= e(__('settings.name')) ?> <span class="req" aria-hidden="true">*</span></label>
                <input id="s-name" name="portal[name]" value="<?= e($val('name', (string) ($portal['name'] ?? ''))) ?>" maxlength="100" required<?= field_aria($errors, 'name') ?>>
                <?= field_error($errors, 'name') ?>
            </div>
            <div class="field">
                <label for="s-tagline"><?= e(__('settings.tagline')) ?></label>
                <input id="s-tagline" name="portal[tagline]" value="<?= e($val('tagline', (string) ($portal['tagline'] ?? ''))) ?>" maxlength="200">
            </div>
            <div class="field">
                <label for="s-locked"><?= e(__('settings.locked_notice')) ?></label>
                <input id="s-locked" name="portal[locked_content_notice]" value="<?= e($val('locked_content_notice', (string) ($portal['locked_content_notice'] ?? ''))) ?>" maxlength="300" aria-describedby="s-locked-hint">
                <p class="hint" id="s-locked-hint"><?= e(__('settings.locked_notice_hint')) ?></p>
            </div>
        </fieldset>

        <fieldset class="field-group">
            <legend><?= e(__('settings.look')) ?></legend>
            <div class="field-row">
                <div class="field">
                    <label for="s-accent"><?= e(__('settings.accent')) ?></label>
                    <input id="s-accent" name="portal[accent]" type="color" value="<?= e($val('accent', (string) ($portal['theme']['accent'] ?? '#1f6feb'))) ?>"<?= field_aria($errors, 'accent') ?>>
                    <?= field_error($errors, 'accent') ?>
                </div>
                <div class="field">
                    <label for="s-mode"><?= e(__('settings.mode')) ?></label>
                    <select id="s-mode" name="portal[mode]">
                        <?php $mode = $val('mode', (string) ($portal['theme']['mode'] ?? 'light')); ?>
                        <option value="light" <?= $mode !== 'dark' ? 'selected' : '' ?>><?= e(__('settings.mode_light')) ?></option>
                        <option value="dark" <?= $mode === 'dark' ? 'selected' : '' ?>><?= e(__('settings.mode_dark')) ?></option>
                    </select>
                </div>
            </div>
        </fieldset>

        <fieldset class="field-group">
            <legend><?= e(__('settings.cta')) ?></legend>
            <p class="hint"><?= e(__('settings.cta_hint')) ?></p>
            <div class="field-row">
                <div class="field">
                    <label for="s-cta-url"><?= e(__('settings.cta_url')) ?></label>
                    <input id="s-cta-url" name="portal[consultation_url]" type="url" value="<?= e($val('consultation_url', (string) ($portal['cta']['consultation_url'] ?? ''))) ?>" maxlength="300"<?= field_aria($errors, 'consultation_url') ?>>
                    <?= field_error($errors, 'consultation_url') ?>
                </div>
                <div class="field">
                    <label for="s-cta-label"><?= e(__('settings.cta_label')) ?></label>
                    <input id="s-cta-label" name="portal[consultation_label]" value="<?= e($val('consultation_label', (string) ($portal['cta']['consultation_label'] ?? ''))) ?>" maxlength="60">
                </div>
            </div>
        </fieldset>

        <fieldset class="field-group" id="ai">
            <legend><?= e(__('settings.ai')) ?></legend>
            <?php if (!$aiKey): ?>
                <p class="notice notice--warning"><?= e(__('settings.ai_no_key')) ?></p>
            <?php else: ?>
                <p class="hint"><?= e(__('settings.ai_usage', ['spend' => number_format($aiSpend, 2, ',', ' '), 'budget' => number_format((float) ($ai['monthly_budget_usd'] ?? 30), 2, ',', ' '), 'calls' => $aiCalls, 'model' => $aiModel])) ?></p>
            <?php endif; ?>
            <label class="check"><input type="checkbox" name="ai[enabled]" value="1" <?= !empty($ai['enabled']) ? 'checked' : '' ?>> <span><strong><?= e(__('settings.ai_enabled')) ?></strong></span></label>
            <?php foreach (['extract_from_pdf', 'rewrite_description', 'moderation_hints'] as $flag): ?>
                <label class="check"><input type="checkbox" name="ai[<?= $flag ?>]" value="1" <?= ($ai[$flag] ?? true) ? 'checked' : '' ?>> <span><?= e(__('settings.ai_' . $flag)) ?></span></label>
            <?php endforeach; ?>
            <div class="field">
                <label for="ai-budget"><?= e(__('settings.ai_budget')) ?></label>
                <input id="ai-budget" name="ai[monthly_budget_usd]" value="<?= e((string) ($ai['monthly_budget_usd'] ?? 30)) ?>" inputmode="decimal" class="input--short" aria-describedby="ai-budget-hint">
                <p class="hint" id="ai-budget-hint"><?= e(__('settings.ai_budget_hint')) ?></p>
            </div>
        </fieldset>

        <fieldset class="field-group">
            <legend><?= e(__('settings.terms')) ?></legend>
            <p class="hint"><?= e(__('settings.terms_hint')) ?></p>
            <?php foreach ($termKeys as $i => $key): ?>
                <div class="field">
                    <label for="t-<?= $i ?>"><?= e($defaults[$key]) ?> <code class="muted"><?= e($key) ?></code></label>
                    <input id="t-<?= $i ?>" name="terms[<?= e($key) ?>]" value="<?= e((string) ($terms[$key] ?? '')) ?>" placeholder="<?= e($defaults[$key]) ?>" maxlength="300">
                </div>
            <?php endforeach; ?>
        </fieldset>

        <button type="submit" class="button"><?= e(__('form.save')) ?></button>
    </form>
</section>
