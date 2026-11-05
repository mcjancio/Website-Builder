<?php
/** @var array $user @var array $errors @var array $devices @var array $lastEvents */
use App\Core\View;
use App\Listings\Display;
?>
<section class="container section">
    <?= View::partial('account/partials/nav', ['active' => 'security']) ?>
    <h1><?= e(__('security.title')) ?></h1>

    <section aria-labelledby="haslo-h" class="account-section">
        <h2 id="haslo-h"><?= e(__('security.password')) ?></h2>
        <form method="post" action="<?= e(url('/konto/haslo')) ?>" class="form" novalidate>
            <?= csrf_field() ?>
            <div class="field">
                <label for="current_password"><?= e(__('security.current_password')) ?></label>
                <input id="current_password" name="current_password" type="password" required autocomplete="current-password"<?= field_aria($errors, 'current_password') ?>>
                <?= field_error($errors, 'current_password') ?>
            </div>
            <div class="field">
                <label for="password"><?= e(__('password.new')) ?></label>
                <input id="password" name="password" type="password" required minlength="10" autocomplete="new-password"<?= field_aria($errors, 'password') ?>>
                <?= field_error($errors, 'password') ?>
            </div>
            <div class="field">
                <label for="password_confirmation"><?= e(__('register.password_confirmation')) ?></label>
                <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"<?= field_aria($errors, 'password_confirmation') ?>>
                <?= field_error($errors, 'password_confirmation') ?>
            </div>
            <button type="submit" class="button"><?= e(__('security.change_password')) ?></button>
        </form>
    </section>

    <section aria-labelledby="2fa-h" class="account-section">
        <h2 id="2fa-h"><?= e(__('twofa.short')) ?></h2>
        <p><?= e($user['totp_enabled_at'] ? __('twofa.status_on') : __('twofa.status_off')) ?> · <a href="<?= e(url('/konto/2fa')) ?>"><?= e(__('twofa.manage')) ?></a></p>
    </section>

    <section aria-labelledby="urzadzenia-h" class="account-section">
        <h2 id="urzadzenia-h"><?= e(__('security.devices')) ?></h2>
        <?php if ($devices === []): ?>
            <p class="muted"><?= e(__('security.no_devices')) ?></p>
        <?php else: ?>
            <ul class="plain-list">
                <?php foreach ($devices as $d): ?>
                    <li><?= e($d['label']) ?> <span class="muted"><?= e(__('security.device_since', ['date' => Display::datetime($d['created_at'])])) ?></span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <form method="post" action="<?= e(url('/konto/urzadzenia/wyloguj')) ?>" class="inline-form" data-confirm="<?= e(__('security.logout_all_confirm')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="button button--secondary"><?= e(__('security.logout_all')) ?></button>
        </form>
    </section>

    <?php if ($lastEvents !== []): ?>
        <section aria-labelledby="logowania-h" class="account-section">
            <h2 id="logowania-h"><?= e(__('security.last_logins')) ?></h2>
            <ul class="plain-list">
                <?php foreach ($lastEvents as $ev): ?>
                    <li><?= e(__(in_array($ev['action'], ['login.fail', 'login.locked'], true) ? 'security.event_fail' : 'security.event_ok')) ?> <span class="muted"><?= e(Display::datetime($ev['created_at'])) ?></span></li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <p class="danger-zone"><a href="<?= e(url('/konto/usun')) ?>" class="link-button--danger"><?= e(__('delete.title')) ?></a></p>
</section>
