<?php /** @var string $title @var string $kind @var string $url @var int $id */ ?>
<!--subject:<?= e(__('email.moderation.subject_' . $kind, ['title' => $title])) ?>-->
<p><?= e(__('email.moderation.text_' . $kind)) ?></p>
<p><strong><?= e($title) ?></strong></p>
<p><a href="<?= e($url) ?>" style="display:inline-block;padding:12px 20px;background:#2457d6;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:bold"><?= e(__('email.moderation.button')) ?></a></p>
<p style="color:#5b5f66;font-size:13px"><?= e(__('email.moderation.cli', ['id' => $id])) ?></p>
