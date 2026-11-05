<?php
/** Layout e-maili: proste style inline (klienty poczty ignorują arkusze), bez obrazków zewnętrznych. @var string $content @var string $portalName */
?>
<!doctype html>
<html lang="pl">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>
<body style="margin:0;padding:24px;background:#f5f5f3;font-family:Arial,Helvetica,sans-serif;color:#16181b;line-height:1.5">
<div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;padding:32px">
    <p style="margin:0 0 24px;font-weight:bold;font-size:18px"><?= e($portalName) ?></p>
    <?= $content ?>
    <p style="margin:32px 0 0;color:#5b5f66;font-size:13px"><?= e(__('email.footer', ['portal' => $portalName])) ?></p>
</div>
</body>
</html>
