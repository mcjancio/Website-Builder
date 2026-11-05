<?php /** @var string $name @var string $title @var string $url */ ?>
<!--subject:<?= e(__('email.access_granted.subject', ['title' => $title])) ?>-->
<p><?= e(__('email.hello', ['name' => $name])) ?></p>
<p><?= e(__('email.access_granted.text', ['title' => $title])) ?></p>
<p><a href="<?= e($url) ?>" style="display:inline-block;padding:12px 20px;background:#2457d6;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:bold"><?= e(__('email.access_granted.button')) ?></a></p>
