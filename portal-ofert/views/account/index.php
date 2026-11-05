<?php
/**
 * @var array $user
 * @var ?string $accountTypeLabel
 * @var ?int $graceLeft  godziny do wymaganego potwierdzenia e-maila (null = potwierdzony)
 * @var bool $emailOk
 */
?>
<section class="container section">
    <h1><?= e(__('account.hello', ['name' => $user['first_name']])) ?></h1>

    <?php if ($user['email_verified_at'] === null): ?>
        <div class="notice notice--warning" role="status">
            <p><strong><?= e(__('verify_email.banner_title')) ?></strong></p>
            <p><?= e($emailOk ? __('verify_email.banner_grace', ['email' => $user['email'], 'hours' => $graceLeft]) : __('verify_email.banner_blocked', ['email' => $user['email']])) ?></p>
            <form method="post" action="<?= e(url('/konto/potwierdz-email')) ?>" class="inline-form">
                <?= csrf_field() ?>
                <button type="submit" class="link-button"><?= e(__('verify_email.resend')) ?></button>
            </form>
        </div>
    <?php endif; ?>

    <dl class="data-list">
        <dt><?= e(__('register.email')) ?></dt>
        <dd><?= e($user['email']) ?> <?= $user['email_verified_at'] ? '<span class="badge badge--ok">' . e(__('account.verified')) . '</span>' : '<span class="badge">' . e(__('account.unverified')) . '</span>' ?></dd>
        <dt><?= e(__('register.phone')) ?></dt>
        <dd><?= e($user['phone_e164']) ?> <?= $user['phone_verified_at'] ? '<span class="badge badge--ok">' . e(__('account.verified')) . '</span>' : '<span class="badge">' . e(__('account.unverified')) . '</span>' ?></dd>
        <?php if ($accountTypeLabel): ?>
            <dt><?= e(__('register.account_type')) ?></dt><dd><?= e($accountTypeLabel) ?></dd>
        <?php endif; ?>
        <?php if ($user['company_name']): ?>
            <dt><?= e(__('register.company_name')) ?></dt><dd><?= e($user['company_name']) ?> <span class="muted">(NIP <?= e($user['company_nip']) ?>)</span></dd>
        <?php endif; ?>
        <dt><?= e(__('twofa.short')) ?></dt>
        <dd><?= e($user['totp_enabled_at'] ? __('twofa.status_on') : __('twofa.status_off')) ?> · <a href="<?= e(url('/konto/2fa')) ?>"><?= e(__('twofa.manage')) ?></a></dd>
    </dl>

    <p class="muted"><?= e(__('account.coming_soon')) ?></p>
    <p><a href="<?= e(url('/reset-hasla')) ?>"><?= e(__('account.change_password')) ?></a></p>
</section>
