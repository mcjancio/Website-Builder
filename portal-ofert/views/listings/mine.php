<?php
/** @var array $listings */
use App\Core\View;

$statusActions = [
    'published' => ['pause', 'sold', 'archive'],
    'paused' => ['resume', 'sold', 'archive'],
    'sold' => ['archive'],
];
?>
<section class="container section">
    <?= View::partial('account/partials/nav', ['active' => 'mine']) ?>
    <div class="section-head">
        <h1><?= e(__('mine.title')) ?></h1>
        <a class="button" href="<?= e(url('/dodaj-oferte')) ?>"><?= e(__('term.listing_add')) ?></a>
    </div>

    <?php if ($listings === []): ?>
        <p class="empty"><?= e(__('mine.empty')) ?></p>
    <?php else: ?>
        <ul class="my-listings">
            <?php foreach ($listings as $l): ?>
                <li class="my-listing">
                    <div class="my-listing__thumb">
                        <?php if ($l['cover_id']): ?>
                            <img src="<?= e(url('/media/' . $l['cover_id'] . '/400')) ?>" alt="" width="160" height="107" loading="lazy">
                        <?php endif; ?>
                    </div>
                    <div class="my-listing__body">
                        <h2 class="h3"><a href="<?= e(url('/moje-oferty/' . $l['id'] . '/edycja')) ?>"><?= e($l['title']) ?></a></h2>
                        <p class="muted"><?= e($l['category_name']) ?></p>
                        <p>
                            <span class="status status--<?= e($l['status']) ?>"><?= e(__('status.' . $l['status'])) ?></span>
                            <?php if ($l['revision_status'] === 'pending'): ?><span class="status status--pending"><?= e(__('mine.revision_pending')) ?></span><?php endif; ?>
                            <?php if ($l['revision_status'] === 'draft'): ?><span class="status status--draft"><?= e(__('mine.revision_draft')) ?></span><?php endif; ?>
                        </p>
                        <?php if ($l['status'] === 'rejected' && $l['moderation_note']): ?>
                            <p class="notice notice--warning"><?= e(__('mine.rejected_note', ['note' => $l['moderation_note']])) ?></p>
                        <?php endif; ?>
                        <?php if ($l['revision_status'] === 'rejected' && $l['revision_note']): ?>
                            <p class="notice notice--warning"><?= e(__('mine.revision_rejected', ['note' => $l['revision_note']])) ?></p>
                        <?php endif; ?>
                        <?php if ($l['published_at'] !== null): ?>
                            <p class="muted my-listing__stats"><?= e(__('mine.stats_line', ['views' => (int) $l['views30'], 'inquiries' => (int) $l['inquiries']])) ?></p>
                        <?php endif; ?>
                        <p class="my-listing__actions">
                            <a href="<?= e(url('/moje-oferty/' . $l['id'] . '/edycja')) ?>"><?= e(__('mine.edit')) ?></a>
                            <a href="<?= e(url('/moje-oferty/' . $l['id'] . '/podglad')) ?>"><?= e(__('wizard.preview')) ?></a>
                            <?php if ($l['published_at'] !== null): ?>
                                <a href="<?= e(url('/moje-oferty/' . $l['id'] . '/statystyki')) ?>"><?= e(__('mine.stats')) ?></a>
                            <?php endif; ?>
                            <?php if (!empty($l['paused_by_staff'])): ?><span class="muted"><?= e(__('mine.paused_by_staff')) ?></span><?php endif; ?>
                            <?php foreach (!empty($l['paused_by_staff']) ? array_diff($statusActions[$l['status']] ?? [], ['resume', 'sold']) : ($statusActions[$l['status']] ?? []) as $a): ?>
                                <form method="post" action="<?= e(url('/moje-oferty/' . $l['id'] . '/status')) ?>" class="inline-form"<?= in_array($a, ['sold', 'archive'], true) ? ' data-confirm="' . e(__('mine.confirm_' . $a)) . '"' : '' ?>>
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="<?= e($a) ?>">
                                    <button type="submit" class="link-button"><?= e(__('mine.action_' . $a)) ?></button>
                                </form>
                            <?php endforeach; ?>
                            <?php if (in_array($l['status'], ['draft', 'rejected'], true) && $l['published_at'] === null): ?>
                                <form method="post" action="<?= e(url('/moje-oferty/' . $l['id'] . '/usun')) ?>" class="inline-form" data-confirm="<?= e(__('mine.delete_confirm')) ?>">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="link-button link-button--danger"><?= e(__('mine.delete')) ?></button>
                                </form>
                            <?php endif; ?>
                        </p>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
