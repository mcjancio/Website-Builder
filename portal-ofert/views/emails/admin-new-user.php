<?php /** @var string $name @var string $email @var ?string $company @var ?string $account_type */ ?>
<!--subject:<?= e(__('email.admin_new_user.subject', ['name' => $name])) ?>-->
<p><?= e(__('email.admin_new_user.text')) ?></p>
<ul>
    <li><?= e($name) ?> — <?= e($email) ?></li>
    <?php if ($company): ?><li><?= e($company) ?></li><?php endif; ?>
    <?php if ($account_type): ?><li><?= e(__('register.account_type')) ?>: <?= e($account_type) ?></li><?php endif; ?>
</ul>
