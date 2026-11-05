<?php
/**
 * Stronicowanie tabel w panelach. @var int $page @var int $pages @var string $base @var array $query
 */
if ($pages <= 1) {
    return;
}
$link = static function (int $p) use ($base, $query): string {
    $q = $query;
    unset($q['strona']);
    if ($p > 1) {
        $q['strona'] = $p;
    }
    return url($base, array_filter($q, static fn($v) => $v !== '' && $v !== null));
};
?>
<nav class="pagination" aria-label="<?= e(__('listings.pages')) ?>">
    <?php if ($page > 1): ?><a href="<?= e($link($page - 1)) ?>" rel="prev">← <?= e(__('listings.prev')) ?></a><?php endif; ?>
    <span><?= e(__('listings.page_of', ['page' => $page, 'pages' => $pages])) ?></span>
    <?php if ($page < $pages): ?><a href="<?= e($link($page + 1)) ?>" rel="next"><?= e(__('listings.next')) ?> →</a><?php endif; ?>
</nav>
