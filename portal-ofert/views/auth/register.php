<?php
/**
 * @var array $errors
 * @var array $old
 * @var array $accountTypes
 * @var bool $requireNip
 * @var bool $allowPrivate
 */
$v = static fn(string $k): string => e($old[$k] ?? '');
?>
<section class="container section auth">
    <h1><?= e(__('register.title')) ?></h1>
    <p class="muted"><?= e(__('register.lead')) ?></p>

    <?php if ($errors !== []): ?>
        <div class="form-summary" role="alert" tabindex="-1" data-focus>
            <p><?= e(__('form.fix_errors', ['count' => count($errors)])) ?></p>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/rejestracja')) ?>" class="form" novalidate data-loading-form>
        <?= csrf_field() ?>

        <?php if ($accountTypes !== []): ?>
            <fieldset class="field">
                <legend><?= e(__('register.account_type')) ?> <span class="req" aria-hidden="true">*</span></legend>
                <div class="choice-cards">
                    <?php foreach ($accountTypes as $t): ?>
                        <label class="choice-card">
                            <input type="radio" name="account_type" value="<?= e($t['key']) ?>" required
                                <?= ($old['account_type'] ?? '') === $t['key'] ? 'checked' : '' ?><?= field_aria($errors, 'account_type') ?>>
                            <span class="choice-card__label"><?= e($t['label']) ?></span>
                            <?php if (!empty($t['description'])): ?><span class="choice-card__desc"><?= e($t['description']) ?></span><?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <?= field_error($errors, 'account_type') ?>
            </fieldset>
        <?php endif; ?>

        <div class="field-row">
            <div class="field">
                <label for="first_name"><?= e(__('register.first_name')) ?> <span class="req" aria-hidden="true">*</span></label>
                <input id="first_name" name="first_name" value="<?= $v('first_name') ?>" required autocomplete="given-name" maxlength="100"<?= field_aria($errors, 'first_name') ?>>
                <?= field_error($errors, 'first_name') ?>
            </div>
            <div class="field">
                <label for="last_name"><?= e(__('register.last_name')) ?> <span class="req" aria-hidden="true">*</span></label>
                <input id="last_name" name="last_name" value="<?= $v('last_name') ?>" required autocomplete="family-name" maxlength="100"<?= field_aria($errors, 'last_name') ?>>
                <?= field_error($errors, 'last_name') ?>
            </div>
        </div>

        <div class="field">
            <label for="email"><?= e(__('register.email')) ?> <span class="req" aria-hidden="true">*</span></label>
            <input id="email" name="email" type="email" value="<?= $v('email') ?>" required autocomplete="email" maxlength="190"<?= field_aria($errors, 'email') ?>>
            <?= field_error($errors, 'email') ?>
        </div>

        <div class="field">
            <label for="phone"><?= e(__('register.phone')) ?> <span class="req" aria-hidden="true">*</span></label>
            <input id="phone" name="phone" type="tel" value="<?= e($old['phone'] ?? '+48 ') ?>" required autocomplete="tel" inputmode="tel" maxlength="20"
                   aria-describedby="phone-hint<?= isset($errors['phone']) ? ' phone-error' : '' ?>"<?= isset($errors['phone']) ? ' aria-invalid="true"' : '' ?>>
            <p class="hint" id="phone-hint"><?= e(__('register.phone_hint_' . $phoneHint)) ?></p>
            <?= field_error($errors, 'phone') ?>
        </div>

        <div class="field">
            <label for="nip"><?= e(__('register.nip')) ?><?= $requireNip ? ' <span class="req" aria-hidden="true">*</span>' : ' <span class="muted">(' . e(__('form.optional')) . ')</span>' ?></label>
            <input id="nip" name="nip" value="<?= $v('nip') ?>" inputmode="numeric" maxlength="16" autocomplete="off"
                   data-nip-lookup="<?= e(url('/api/firma/')) ?>" data-target="#company_name" data-status="#nip-status"
                   data-text-searching="<?= e(__('register.nip_searching')) ?>" data-text-found="<?= e(__('register.nip_found')) ?>"
                   data-text-not-found="<?= e(__('register.nip_not_found')) ?>"
                   aria-describedby="nip-status<?= isset($errors['nip']) ? ' nip-error' : '' ?>"<?= $requireNip ? ' required' : '' ?><?= isset($errors['nip']) ? ' aria-invalid="true"' : '' ?>>
            <p class="hint" id="nip-status" aria-live="polite"><?= e(__($requireNip ? 'register.nip_hint' : 'register.nip_hint_optional')) ?></p>
            <?= field_error($errors, 'nip') ?>
        </div>

        <div class="field">
            <label for="company_name"><?= e(__('register.company_name')) ?></label>
            <input id="company_name" name="company_name" value="<?= $v('company_name') ?>" maxlength="255" autocomplete="organization"<?= field_aria($errors, 'company_name') ?>>
            <?= field_error($errors, 'company_name') ?>
        </div>

        <div class="field-row">
            <div class="field">
                <label for="password"><?= e(__('register.password')) ?> <span class="req" aria-hidden="true">*</span></label>
                <input id="password" name="password" type="password" required minlength="10" autocomplete="new-password"
                       aria-describedby="password-hint<?= isset($errors['password']) ? ' password-error' : '' ?>"<?= isset($errors['password']) ? ' aria-invalid="true"' : '' ?>>
                <p class="hint" id="password-hint"><?= e(__('register.password_hint')) ?></p>
                <?= field_error($errors, 'password') ?>
            </div>
            <div class="field">
                <label for="password_confirmation"><?= e(__('register.password_confirmation')) ?> <span class="req" aria-hidden="true">*</span></label>
                <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"<?= field_aria($errors, 'password_confirmation') ?>>
                <?= field_error($errors, 'password_confirmation') ?>
            </div>
        </div>

        <div class="field field--check">
            <label>
                <input type="checkbox" name="terms" value="1" required <?= !empty($old['terms']) ? 'checked' : '' ?><?= field_aria($errors, 'terms') ?>>
                <span><?= e(__('register.terms_label')) ?> <span class="req" aria-hidden="true">*</span></span>
            </label>
            <?= field_error($errors, 'terms') ?>
        </div>
        <div class="field field--check">
            <label>
                <input type="checkbox" name="marketing" value="1" <?= !empty($old['marketing']) ? 'checked' : '' ?>>
                <span><?= e(__('register.marketing_label')) ?></span>
            </label>
        </div>

        <p class="hint"><?= e(__('register.next_step_hint')) ?></p>
        <button type="submit" class="button button--block" data-loading-text="<?= e(__('form.sending')) ?>"><?= e(__('register.submit')) ?></button>
    </form>

    <p class="auth__alt"><?= e(__('register.have_account')) ?> <a href="<?= e(url('/logowanie')) ?>"><?= e(__('nav.login')) ?></a></p>
</section>
