<?php
/** @var array $errors @var array $old */
?>
<section class="container section auth">
    <h1><?= e(__('password.forgot_title')) ?></h1>
    <p><?= e(__('password.forgot_lead')) ?></p>
    <form method="post" action="<?= e(url('/reset-hasla')) ?>" class="form" novalidate data-loading-form>
        <?= csrf_field() ?>
        <div class="field">
            <label for="email"><?= e(__('register.email')) ?></label>
            <input id="email" name="email" type="email" value="<?= e($old['email'] ?? '') ?>" required autocomplete="email"<?= field_aria($errors, 'email') ?>>
            <?= field_error($errors, 'email') ?>
        </div>
        <button type="submit" class="button button--block" data-loading-text="<?= e(__('form.sending')) ?>"><?= e(__('password.send_link')) ?></button>
    </form>
    <p class="auth__alt"><a href="<?= e(url('/logowanie')) ?>"><?= e(__('nav.login')) ?></a></p>
</section>
