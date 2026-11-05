<?php
/** @var string[] $codes */
?>
<section class="container section auth">
    <h1><?= e(__('twofa.recovery_title')) ?></h1>
    <p class="notice notice--warning"><?= e(__('twofa.recovery_info')) ?></p>
    <ul class="recovery-codes">
        <?php foreach ($codes as $c): ?><li><code><?= e($c) ?></code></li><?php endforeach; ?>
    </ul>
    <p><a class="button" href="<?= e(url('/konto')) ?>"><?= e(__('twofa.recovery_done')) ?></a></p>
</section>
