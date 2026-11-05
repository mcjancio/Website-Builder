<?php /** @var string $name @var string $search @var int $total @var array $items @var string $url @var string $manage */ ?>
<!--subject:<?= e(__('email.saved_search.subject', ['search' => $search, 'count' => $total])) ?>-->
<p><?= e(__('email.hello', ['name' => $name])) ?></p>
<p><?= e(__('email.saved_search.text', ['search' => $search, 'count' => $total])) ?></p>
<?php foreach ($items as $i): ?>
    <p style="margin:0 0 14px"><a href="<?= e($i['url']) ?>" style="font-weight:bold;color:#2457d6"><?= e($i['title']) ?></a><br>
        <?php if (!empty($i['short'])): ?><span style="color:#555555"><?= e(mb_strimwidth((string) $i['short'], 0, 200, '…')) ?></span><?php endif; ?></p>
<?php endforeach; ?>
<p><a href="<?= e($url) ?>" style="display:inline-block;padding:12px 20px;background:#2457d6;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:bold"><?= e(__('email.saved_search.button')) ?></a></p>
<p style="color:#777777;font-size:13px"><?= e(__('email.saved_search.manage')) ?> <a href="<?= e($manage) ?>"><?= e($manage) ?></a></p>
