<?php /** @var string $name @var string $title @var string $url */ ?>
<!--subject:<?= e(__('email.access_denied.subject', ['title' => $title])) ?>-->
<p><?= e(__('email.hello', ['name' => $name])) ?></p>
<p><?= e(__('email.access_denied.text', ['title' => $title])) ?></p>
