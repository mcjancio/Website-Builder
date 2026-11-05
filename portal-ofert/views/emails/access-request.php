<?php /** @var string $title @var int $id @var int $listingId @var string $name @var string $email @var ?string $company @var string $message */ ?>
<!--subject:<?= e(__('email.access_request.subject', ['title' => $title])) ?>-->
<p><?= e(__('email.access_request.text', ['name' => $name])) ?></p>
<p><strong><?= e($title) ?></strong></p>
<ul>
    <li><?= e($name) ?> — <a href="mailto:<?= e($email) ?>"><?= e($email) ?></a></li>
    <?php if ($company): ?><li><?= e($company) ?></li><?php endif; ?>
</ul>
<?php if ($message !== ''): ?><p style="white-space:pre-wrap;padding:12px;background:#f5f5f3;border-radius:6px"><?= e($message) ?></p><?php endif; ?>
<p style="color:#5b5f66;font-size:13px"><?= e(__('email.access_request.cli', ['id' => $id])) ?></p>
