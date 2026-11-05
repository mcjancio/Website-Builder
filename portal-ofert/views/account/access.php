<?php
/** @var array $items */
use App\Core\View;
use App\Listings\Display;
?>
<section class="container section">
    <?= View::partial('account/partials/nav', ['active' => 'access']) ?>
    <h1><?= e(__('myaccess.title')) ?></h1>
    <p class="muted"><?= e(__('myaccess.help')) ?></p>
    <?php if ($items === []): ?>
        <p class="empty"><?= e(__('myaccess.empty')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr>
                    <th scope="col"><?= e(__('term.listing')) ?></th>
                    <th scope="col"><?= e(__('admin.col_status')) ?></th>
                    <th scope="col"><?= e(__('myaccess.requested')) ?></th>
                    <th scope="col"><?= e(__('admin.col_expires')) ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($items as $g): ?>
                    <tr>
                        <td>
                            <?php if (in_array($g['listing_status'], ['published', 'sold'], true)): ?>
                                <a href="<?= e(url('/oferta/' . $g['slug'])) ?>"><?= e($g['title']) ?></a>
                            <?php else: ?>
                                <?= e($g['title']) ?> <span class="muted">(<?= e(__('myaccess.unavailable')) ?>)</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="status status--grant-<?= e($g['status']) ?>"><?= e(__('grant_status.' . $g['status'])) ?></span></td>
                        <td><?= e(Display::datetime($g['requested_at'])) ?></td>
                        <td><?= e($g['status'] === 'granted' ? ($g['expires_at'] ? Display::datetime($g['expires_at']) : __('admin.access_unlimited')) : '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
