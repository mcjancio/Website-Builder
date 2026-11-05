<?php /** @var array $errors @var int $failed_jobs @var ?int $backup_age_h @var bool $backup_stale @var string $portal */ ?>
<!--subject:<?= e(__('email.error_report.subject', ['count' => count($errors) + ($failed_jobs > 0 ? 1 : 0) + ($backup_stale ? 1 : 0)])) ?>-->
<p><?= e(__('email.error_report.intro', ['portal' => $portal])) ?></p>
<?php if ($backup_stale): ?>
    <p style="padding:10px 14px;background:#fdecea;border-left:4px solid #c62828"><strong><?= e($backup_age_h === null ? __('email.error_report.backup_none') : __('email.error_report.backup_old', ['hours' => $backup_age_h])) ?></strong></p>
<?php endif; ?>
<?php if ($failed_jobs > 0): ?>
    <p style="padding:10px 14px;background:#fff8e1;border-left:4px solid #b26a00"><?= e(__('email.error_report.failed_jobs', ['count' => $failed_jobs])) ?></p>
<?php endif; ?>
<?php if ($errors !== []): ?>
    <p><strong><?= e(__('email.error_report.errors')) ?></strong></p>
    <?php foreach ($errors as $err): ?>
        <p style="margin:0 0 12px"><strong><?= e($err['message']) ?></strong> — <?= e(__('email.error_report.times', ['count' => $err['count']])) ?>, <?= e(__('email.error_report.last', ['time' => \App\Listings\Display::datetime(str_replace(['T', 'Z'], [' ', ''], $err['last']))])) ?><br>
            <code style="font-size:12px;color:#555555"><?= e($err['context']) ?></code></p>
    <?php endforeach; ?>
<?php endif; ?>
<p style="color:#777777;font-size:13px"><?= e(__('email.error_report.footer')) ?></p>
