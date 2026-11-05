<?php /** @var string $name @var string $url */ ?>
<!--subject:<?= e(__('email.verify.subject', ['portal' => $portalName])) ?>-->
<p><?= e(__('email.hello', ['name' => $name])) ?></p>
<p><?= e(__('email.verify.text')) ?></p>
<p><a href="<?= e($url) ?>" style="display:inline-block;padding:12px 20px;background:#2457d6;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:bold"><?= e(__('email.verify.button')) ?></a></p>
<p style="color:#5b5f66;font-size:13px"><?= e(__('email.link_fallback')) ?><br><?= e($url) ?></p>
