<?php
/**
 * @var array $errors
 * @var array $otp     id, channel, phone_masked
 * @var ?array $status channel, delivery_status, fallback_sent, fallback_in
 * @var bool $canSms
 */
$channel = $status['channel'] ?? $otp['channel'];
$sentVia = !empty($status['fallback_sent']) ? 'sms_fallback' : $channel;
?>
<section class="container section auth">
    <h1><?= e(__('otp.title')) ?></h1>

    <p class="otp-sent" id="otp-sent" aria-live="polite"
       data-status-url="<?= e(url('/rejestracja/status')) ?>"
       data-text-fallback="<?= e(__('otp.sent_sms_fallback', ['phone' => $otp['phone_masked']])) ?>">
        <?= e(__('otp.sent_' . $sentVia, ['phone' => $otp['phone_masked']])) ?>
    </p>

    <form method="post" action="<?= e(url('/rejestracja/weryfikacja')) ?>" class="form" novalidate data-loading-form>
        <?= csrf_field() ?>
        <div class="field">
            <label for="code"><?= e(__('otp.code_label')) ?></label>
            <input id="code" name="code" class="otp-input" inputmode="numeric" pattern="[0-9 ]*" maxlength="7"
                   autocomplete="one-time-code" required autofocus<?= field_aria($errors, 'code') ?>>
            <?= field_error($errors, 'code') ?>
        </div>
        <button type="submit" class="button button--block" data-loading-text="<?= e(__('form.checking')) ?>"><?= e(__('otp.submit')) ?></button>
    </form>

    <div class="otp-help">
        <?php if ($canSms): ?>
            <form method="post" action="<?= e(url('/rejestracja/wyslij-sms')) ?>" class="inline-form" data-sms-fallback>
                <?= csrf_field() ?>
                <button type="submit" class="button button--secondary" data-countdown="<?= (int) ($status['fallback_in'] ?? 0) ?>"
                        data-countdown-text="<?= e(__('otp.sms_button_wait')) ?>"><?= e(__('otp.sms_button')) ?></button>
            </form>
        <?php endif; ?>
        <form method="post" action="<?= e(url('/rejestracja/wyslij-ponownie')) ?>" class="inline-form">
            <?= csrf_field() ?>
            <button type="submit" class="link-button"><?= e(__('otp.resend')) ?></button>
        </form>
        <a href="<?= e(url('/rejestracja')) ?>"><?= e(__('otp.change_phone')) ?></a>
    </div>
</section>
