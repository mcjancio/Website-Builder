<?php
/** @var array $listings */
?>
<section class="container section">
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
                        <p class="my-listing__actions">
                            <a href="<?= e(url('/moje-oferty/' . $l['id'] . '/edycja')) ?>"><?= e(__('mine.edit')) ?></a>
                            <a href="<?= e(url('/moje-oferty/' . $l['id'] . '/podglad')) ?>"><?= e(__('wizard.preview')) ?></a>
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
