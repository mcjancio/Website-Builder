<?php
/** @var array $items */
use App\Core\View;
use App\Listings\SavedSearches;
?>
<section class="container section">
    <?= View::partial('account/partials/nav', ['active' => 'searches']) ?>
    <h1><?= e(__('saved.title')) ?></h1>
    <p class="muted"><?= e(__('saved.help')) ?></p>
    <?php if ($items === []): ?>
        <p class="empty"><?= e(__('saved.empty')) ?> <a href="<?= e(url('/oferty')) ?>"><?= e(__('favorites.browse')) ?></a></p>
    <?php else: ?>
        <ul class="saved-list">
            <?php foreach ($items as $s): $summary = SavedSearches::summary($s); ?>
                <li class="admin-box">
                    <h2 class="h3"><a href="<?= e(SavedSearches::url($s)) ?>"><?= e($s['name']) ?></a></h2>
                    <?php if ($summary !== ''): ?><p class="muted"><?= e($summary) ?></p><?php endif; ?>
                    <div class="row-actions">
                        <form method="post" action="<?= e(url('/konto/wyszukiwania/' . $s['id'])) ?>" class="inline-form admin-inline">
                            <?= csrf_field() ?>
                            <label for="freq-<?= (int) $s['id'] ?>"><?= e(__('saved.frequency')) ?></label>
                            <select id="freq-<?= (int) $s['id'] ?>" name="frequency">
                                <?php foreach (['daily', 'weekly', 'off'] as $f): ?>
                                    <option value="<?= e($f) ?>" <?= $s['frequency'] === $f ? 'selected' : '' ?>><?= e(__('saved.freq_' . $f)) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="button button--small button--secondary"><?= e(__('form.save')) ?></button>
                        </form>
                        <form method="post" action="<?= e(url('/konto/wyszukiwania/' . $s['id'])) ?>" class="inline-form" data-confirm="<?= e(__('saved.delete_confirm')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <button type="submit" class="link-button link-button--danger"><?= e(__('saved.delete')) ?></button>
                        </form>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
