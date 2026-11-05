<?php
/** @var array $user @var ?array $company @var array $errors @var array $old @var array $accountTypes */
use App\Core\View;

$val = static fn(string $k) => (string) ($old[$k] ?? $user[$k] ?? '');
?>
<section class="container section">
    <?= View::partial('account/partials/nav', ['active' => 'profile']) ?>
    <h1><?= e(__('profile.title')) ?></h1>

    <form method="post" action="<?= e(url('/konto/profil')) ?>" class="form" novalidate>
        <?= csrf_field() ?>
        <?php if ($errors !== []): ?><p class="form-summary" role="alert"><?= e(__('form.fix_errors', ['count' => count($errors)])) ?></p><?php endif; ?>
        <div class="field-row">
            <div class="field">
                <label for="first_name"><?= e(__('register.first_name')) ?></label>
                <input id="first_name" name="first_name" value="<?= e($val('first_name')) ?>" maxlength="100" required autocomplete="given-name"<?= field_aria($errors, 'first_name') ?>>
                <?= field_error($errors, 'first_name') ?>
            </div>
            <div class="field">
                <label for="last_name"><?= e(__('register.last_name')) ?></label>
                <input id="last_name" name="last_name" value="<?= e($val('last_name')) ?>" maxlength="100" required autocomplete="family-name"<?= field_aria($errors, 'last_name') ?>>
                <?= field_error($errors, 'last_name') ?>
            </div>
        </div>

        <?php if ($accountTypes !== []): ?>
            <fieldset class="field-group">
                <legend><?= e(__('register.account_type')) ?></legend>
                <?php foreach ($accountTypes as $t): ?>
                    <label class="check"><input type="radio" name="account_type" value="<?= e($t['key']) ?>" <?= $val('account_type') === $t['key'] ? 'checked' : '' ?>> <span><?= e($t['label']) ?></span></label>
                <?php endforeach; ?>
                <?= field_error($errors, 'account_type') ?>
            </fieldset>
        <?php endif; ?>

        <?php if ($company): ?>
            <fieldset class="field-group">
                <legend><?= e(__('profile.company')) ?></legend>
                <?php if ($company['source'] === 'manual'): ?>
                    <div class="field">
                        <label for="company_name"><?= e(__('register.company_name')) ?></label>
                        <input id="company_name" name="company_name" value="<?= e((string) ($old['company_name'] ?? $company['name'])) ?>" maxlength="255">
                    </div>
                <?php else: ?>
                    <p><strong><?= e($company['name']) ?></strong></p>
                    <p class="hint"><?= e(__('profile.company_registry')) ?></p>
                <?php endif; ?>
                <p class="muted">NIP <?= e($company['nip']) ?></p>
            </fieldset>
        <?php endif; ?>

        <div class="field">
            <span class="label"><?= e(__('register.email')) ?></span>
            <p><?= e($user['email']) ?></p>
            <p class="hint"><?= e(__('profile.contact_change')) ?></p>
        </div>

        <button type="submit" class="button"><?= e(__('form.save')) ?></button>
    </form>
</section>
