<?php
/**
 * @var bool $enabled
 * @var ?string $secret
 * @var ?string $uri
 * @var array $errors
 * @var array $user
 * @var int $recoveryLeft
 */
?>
<section class="container section auth">
    <h1><?= e(__('twofa.setup_title')) ?></h1>
    <?php if ($enabled): ?>
        <p class="notice"><?= e(__('twofa.enabled_info', ['count' => $recoveryLeft ?? 0])) ?></p>
        <?php if (!in_array($user['role'], ['admin', 'moderator'], true)): ?>
            <form method="post" action="<?= e(url('/konto/2fa/wylacz')) ?>" class="form">
                <?= csrf_field() ?>
                <div class="field">
                    <label for="code"><?= e(__('twofa.disable_label')) ?></label>
                    <input id="code" name="code" class="otp-input" inputmode="numeric" maxlength="7" autocomplete="one-time-code" required>
                </div>
                <button type="submit" class="button button--secondary"><?= e(__('twofa.disable')) ?></button>
            </form>
        <?php endif; ?>
    <?php else: ?>
        <ol class="steps">
            <li><?= e(__('twofa.step1')) ?></li>
            <li>
                <?= e(__('twofa.step2')) ?>
                <p class="secret" aria-label="<?= e(__('twofa.secret_label')) ?>"><code><?= e(trim(chunk_split((string) $secret, 4, ' '))) ?></code></p>
                <p class="hint"><a href="<?= e((string) $uri) ?>"><?= e(__('twofa.open_app')) ?></a></p>
            </li>
            <li><?= e(__('twofa.step3')) ?></li>
        </ol>
        <form method="post" action="<?= e(url('/konto/2fa')) ?>" class="form" novalidate>
            <?= csrf_field() ?>
            <div class="field">
                <label for="code"><?= e(__('twofa.code_label')) ?></label>
                <input id="code" name="code" class="otp-input" inputmode="numeric" maxlength="7" autocomplete="one-time-code" required<?= field_aria($errors, 'code') ?>>
                <?= field_error($errors, 'code') ?>
            </div>
            <button type="submit" class="button button--block"><?= e(__('twofa.enable')) ?></button>
        </form>
    <?php endif; ?>
</section>
