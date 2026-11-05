<?php
/** Komunikaty po przekierowaniu (Session::flash): success / error / notice. */
$flash = [];
foreach (['success', 'error', 'notice'] as $type) {
    $msg = \App\Core\Session::getFlash($type);
    if (is_string($msg) && $msg !== '') {
        $flash[$type] = $msg;
    }
}
?>
<?php if ($flash !== []): ?>
    <div class="container flash-stack">
        <?php foreach ($flash as $type => $msg): ?>
            <p class="flash flash--<?= e($type) ?>" role="<?= $type === 'error' ? 'alert' : 'status' ?>"><?= e($msg) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
