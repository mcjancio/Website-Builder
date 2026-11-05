<?php
/** @var array $errors */
?>
<section class="container section auth">
    <h1><?= e(__('twofa.title')) ?></h1>
    <p><?= e(__('twofa.enter_code')) ?></p>
    <form method="post" action="<?= e(url('/logowanie/2fa')) ?>" class="form" novalidate data-loading-form>
        <?= csrf_field() ?>
        <div class="field">
            <label for="code"><?= e(__('twofa.code_label')) ?></label>
            <input id="code" name="code" class="otp-input" inputmode="text" maxlength="13" autocomplete="one-time-code" required autofocus
                   aria-describedby="code-hint<?= isset($errors['code']) ? ' code-error' : '' ?>"<?= isset($errors['code']) ? ' aria-invalid="true"' : '' ?>>
            <p class="hint" id="code-hint"><?= e(__('twofa.recovery_hint')) ?></p>
            <?= field_error($errors, 'code') ?>
        </div>
        <button type="submit" class="button button--block"><?= e(__('twofa.submit')) ?></button>
    </form>
</section>
