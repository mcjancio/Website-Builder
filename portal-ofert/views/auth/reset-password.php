<?php
/** @var array $errors */
?>
<section class="container section auth">
    <h1><?= e(__('password.reset_title')) ?></h1>
    <form method="post" class="form" novalidate data-loading-form>
        <?= csrf_field() ?>
        <div class="field">
            <label for="password"><?= e(__('password.new')) ?></label>
            <input id="password" name="password" type="password" required minlength="10" autocomplete="new-password"
                   aria-describedby="password-hint<?= isset($errors['password']) ? ' password-error' : '' ?>"<?= isset($errors['password']) ? ' aria-invalid="true"' : '' ?>>
            <p class="hint" id="password-hint"><?= e(__('register.password_hint')) ?></p>
            <?= field_error($errors, 'password') ?>
        </div>
        <div class="field">
            <label for="password_confirmation"><?= e(__('register.password_confirmation')) ?></label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"<?= field_aria($errors, 'password_confirmation') ?>>
            <?= field_error($errors, 'password_confirmation') ?>
        </div>
        <button type="submit" class="button button--block"><?= e(__('password.save')) ?></button>
    </form>
</section>
