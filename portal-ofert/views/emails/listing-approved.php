<?php /** @var string $name @var string $title @var string $kind @var string $url */ ?>
<!--subject:<?= e(__('email.approved.subject_' . $kind, ['title' => $title])) ?>-->
<p><?= e(__('email.hello', ['name' => $name])) ?></p>
<p><?= e(__('email.approved.text_' . $kind, ['title' => $title])) ?></p>
<p><a href="<?= e($url) ?>"><?= e(__('email.approved.button')) ?></a></p>
