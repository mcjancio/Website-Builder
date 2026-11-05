<?php
/** @var array $errors @var array $old */
?>
<section class="container section auth">
    <h1><?= e(__('login.title')) ?></h1>
    <form method="post" action="<?= e(url('/logowanie')) ?>" class="form" novalidate data-loading-form>
        <?= csrf_field() ?>
        <input type="hidden" name="next" value="<?= e($old['next'] ?? '') ?>">
        <?php if (isset($errors['email'])): ?>
            <p class="form-summary" role="alert"><?= e($errors['email']) ?></p>
        <?php endif; ?>
        <div class="field">
            <label for="email"><?= e(__('register.email')) ?></label>
            <input id="email" name="email" type="email" value="<?= e($old['email'] ?? '') ?>" required autocomplete="username" autofocus>
        </div>
        <div class="field">
            <label for="password"><?= e(__('register.password')) ?></label>
            <input id="password" name="password" type="password" required autocomplete="current-password">
        </div>
        <div class="field field--check">
            <label><input type="checkbox" name="remember" value="1" <?= !empty($old['remember']) ? 'checked' : '' ?>> <span><?= e(__('login.remember')) ?></span></label>
        </div>
        <button type="submit" class="button button--block" data-loading-text="<?= e(__('form.checking')) ?>"><?= e(__('login.submit')) ?></button>
    </form>
    <p class="auth__alt">
        <a href="<?= e(url('/reset-hasla')) ?>"><?= e(__('login.forgot')) ?></a> ·
        <?= e(__('login.no_account')) ?> <a href="<?= e(url('/rejestracja')) ?>"><?= e(__('nav.register')) ?></a>
    </p>
</section>
