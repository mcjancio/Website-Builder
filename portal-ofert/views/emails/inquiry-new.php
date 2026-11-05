<?php /** @var string $name @var string $email @var ?string $phone @var string $message @var ?string $title @var ?string $url */ ?>
<!--subject:<?= e($title ? __('email.inquiry.subject', ['title' => $title]) : __('email.inquiry.subject_general')) ?>-->
<p><?= e(__('email.inquiry.text')) ?></p>
<?php if ($title): ?><p><strong><a href="<?= e((string) $url) ?>"><?= e($title) ?></a></strong></p><?php endif; ?>
<table style="border-collapse:collapse;font-size:15px">
    <tr><td style="padding:4px 12px 4px 0;color:#5b5f66"><?= e(__('inquiry.name')) ?></td><td><?= e($name) ?></td></tr>
    <tr><td style="padding:4px 12px 4px 0;color:#5b5f66"><?= e(__('register.email')) ?></td><td><a href="mailto:<?= e($email) ?>"><?= e($email) ?></a></td></tr>
    <?php if ($phone): ?><tr><td style="padding:4px 12px 4px 0;color:#5b5f66"><?= e(__('inquiry.phone')) ?></td><td><a href="tel:<?= e($phone) ?>"><?= e($phone) ?></a></td></tr><?php endif; ?>
</table>
<p style="white-space:pre-wrap;padding:12px;background:#f5f5f3;border-radius:6px"><?= e($message) ?></p>
