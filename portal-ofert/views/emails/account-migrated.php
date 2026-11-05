<?php /** @var string $name @var string $url @var int $days @var string $portal */ ?>
<!--subject:<?= e(__('email.migrated.subject', ['portal' => $portal])) ?>-->
<p><?= e(__('email.hello', ['name' => $name])) ?></p>
<p><?= e(__('email.migrated.text', ['portal' => $portal])) ?></p>
<p><a href="<?= e($url) ?>" style="display:inline-block;padding:12px 20px;background:#2457d6;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:bold"><?= e(__('email.migrated.button')) ?></a></p>
<p style="color:#777777;font-size:13px"><?= e(__('email.migrated.validity', ['days' => $days])) ?></p>
