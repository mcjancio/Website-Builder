<?php
/** @var array $items */
use App\Core\View;
?>
<section class="container section">
    <?= View::partial('account/partials/nav', ['active' => 'favorites']) ?>
    <h1><?= e(__('favorites.title')) ?></h1>
    <?php if ($items === []): ?>
        <p class="empty"><?= e(__('favorites.empty')) ?> <a href="<?= e(url('/oferty')) ?>"><?= e(__('favorites.browse')) ?></a></p>
    <?php else: ?>
        <ul class="my-listings">
            <?php foreach ($items as $l): ?>
                <li class="my-listing">
                    <div class="my-listing__thumb">
                        <?php if ($l['cover_id']): ?><img src="<?= e(url('/media/' . $l['cover_id'] . '/400')) ?>" alt="" width="160" height="107" loading="lazy"><?php endif; ?>
                    </div>
                    <div class="my-listing__body">
                        <h2 class="h3"><a href="<?= e(url('/oferta/' . $l['slug'])) ?>"><?= e($l['title']) ?></a></h2>
                        <p class="muted"><?= e($l['category_name']) ?><?= $l['city'] ? ' · ' . e($l['city']) : '' ?></p>
                        <?php if ($l['status'] === 'sold'): ?><p><span class="status status--sold"><?= e(__('status.sold')) ?></span></p><?php endif; ?>
                        <?php if ($l['short_desc']): ?><p><?= e($l['short_desc']) ?></p><?php endif; ?>
                        <form method="post" action="<?= e(url('/oferta/' . $l['id'] . '/obserwuj')) ?>" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="back" value="/konto/obserwowane">
                            <button type="submit" class="link-button"><?= e(__('favorites.remove')) ?></button>
                        </form>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
