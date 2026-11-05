<?php
/**
 * Statystyki oferty dla autora.
 * @var array $listing @var array $series @var array $totals @var int $members @var int $favorites @var array $inquiries
 */
use App\Core\Str;
use App\Core\View;
use App\Listings\Display;
?>
<section class="container section">
    <?= View::partial('account/partials/nav', ['active' => 'mine']) ?>
    <p class="breadcrumbs"><a href="<?= e(url('/moje-oferty')) ?>">← <?= e(__('mine.title')) ?></a></p>
    <h1><?= e($title) ?></h1>
    <p><span class="status status--<?= e($listing['status']) ?>"><?= e(__('status.' . $listing['status'])) ?></span>
        <?php if (in_array($listing['status'], ['published', 'sold'], true)): ?><a href="<?= e(url('/oferta/' . $listing['slug'])) ?>"><?= e(__('stats.open_listing')) ?></a><?php endif; ?></p>

    <ul class="stat-tiles">
        <li class="stat-tile"><span class="stat-tile__value"><?= e(Str::number((int) $totals['pub'] + (int) $totals['mem'])) ?></span><span class="stat-tile__label"><?= e(__('stats.views_total')) ?></span></li>
        <li class="stat-tile"><span class="stat-tile__value"><?= e(Str::number($members)) ?></span><span class="stat-tile__label"><?= e(__('stats.members')) ?></span></li>
        <li class="stat-tile"><span class="stat-tile__value"><?= e(Str::number($favorites)) ?></span><span class="stat-tile__label"><?= e(__('stats.favorites')) ?></span></li>
        <li class="stat-tile"><span class="stat-tile__value"><?= e(Str::number(count($inquiries))) ?></span><span class="stat-tile__label"><?= e(__('stats.inquiries')) ?></span></li>
    </ul>

    <?= View::partial('partials/chart', ['series' => $series]) ?>
    <p class="hint"><?= e(__('stats.privacy')) ?></p>

    <h2><?= e(__('stats.inquiries')) ?></h2>
    <?php if ($inquiries === []): ?>
        <p class="empty"><?= e(__('stats.no_inquiries')) ?></p>
    <?php else: ?>
        <ul class="request-list">
            <?php foreach ($inquiries as $i): ?>
                <li class="admin-box">
                    <p><strong><?= e($i['name']) ?></strong> · <a href="mailto:<?= e($i['email']) ?>"><?= e($i['email']) ?></a><?= $i['phone_e164'] ? ' · <a href="tel:' . e($i['phone_e164']) . '">' . e($i['phone_e164']) . '</a>' : '' ?>
                        <span class="muted"><?= e(Display::datetime($i['created_at'])) ?></span></p>
                    <div class="message-box"><?= nl2br(e($i['message'])) ?></div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
