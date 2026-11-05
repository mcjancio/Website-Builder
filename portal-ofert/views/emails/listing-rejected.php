<?php /** @var string $name @var string $title @var ?string $note @var string $kind @var string $url */ ?>
<!--subject:<?= e(__('email.rejected.subject', ['title' => $title])) ?>-->
<p><?= e(__('email.hello', ['name' => $name])) ?></p>
<p><?= e(__('email.rejected.text_' . $kind, ['title' => $title])) ?></p>
<?php if ($note): ?><p style="padding:12px;background:#fff8e1;border-left:4px solid #b26a00"><?= e($note) ?></p><?php endif; ?>
<p><a href="<?= e($url) ?>"><?= e(__('email.rejected.button')) ?></a></p>
