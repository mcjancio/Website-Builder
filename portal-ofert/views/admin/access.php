<?php
/** @var array $pending @var array $granted */
use App\Core\View;
use App\Listings\Display;
?>
<section class="container section admin">
    <?= View::partial('admin/partials/nav', ['active' => 'access']) ?>
    <h1><?= e(__('admin.access')) ?></h1>
    <p class="muted"><?= e(__('admin.access_help')) ?></p>

    <h2><?= e(__('admin.access_pending')) ?> <span class="muted">(<?= count($pending) ?>)</span></h2>
    <?php if ($pending === []): ?>
        <p class="empty"><?= e(__('admin.none')) ?></p>
    <?php else: ?>
        <ul class="request-list">
            <?php foreach ($pending as $p): ?>
                <li class="admin-box">
                    <p><strong><?= e($p['first_name'] . ' ' . $p['last_name']) ?></strong> <span class="muted"><?= e($p['email']) ?><?= $p['company'] ? ' · ' . e($p['company']) : '' ?></span></p>
                    <p><?= e(__('admin.access_for')) ?> <strong><?= e($p['title']) ?></strong> · <span class="muted"><?= e(Display::datetime($p['requested_at'])) ?></span></p>
                    <?php if ($p['message']): ?><blockquote class="message-box"><?= nl2br(e($p['message'])) ?></blockquote><?php endif; ?>
                    <div class="row-actions">
                        <form method="post" action="<?= e(url('/admin/dostepy/' . $p['id'])) ?>" class="inline-form admin-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="grant">
                            <label for="days-<?= (int) $p['id'] ?>"><?= e(__('admin.access_days')) ?></label>
                            <select id="days-<?= (int) $p['id'] ?>" name="days">
                                <option value="0"><?= e(__('admin.access_unlimited')) ?></option>
                                <?php foreach ([7, 30, 90, 180] as $d): ?><option value="<?= $d ?>"><?= e(__('admin.days', ['count' => $d])) ?></option><?php endforeach; ?>
                            </select>
                            <button type="submit" class="button button--small"><?= e(__('admin.grant')) ?></button>
                        </form>
                        <form method="post" action="<?= e(url('/admin/dostepy/' . $p['id'])) ?>" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="deny">
                            <button type="submit" class="link-button link-button--danger"><?= e(__('admin.deny')) ?></button>
                        </form>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <h2><?= e(__('admin.access_granted')) ?></h2>
    <?php if ($granted === []): ?>
        <p class="empty"><?= e(__('admin.none')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr>
                    <th scope="col"><?= e(__('admin.col_person')) ?></th>
                    <th scope="col"><?= e(__('term.listing')) ?></th>
                    <th scope="col"><?= e(__('admin.col_granted')) ?></th>
                    <th scope="col"><?= e(__('admin.col_expires')) ?></th>
                    <th scope="col"><?= e(__('admin.col_actions')) ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($granted as $g): ?>
                    <tr>
                        <td><?= e($g['first_name'] . ' ' . $g['last_name']) ?><br><span class="muted"><?= e($g['email']) ?></span></td>
                        <td><a href="<?= e(url('/oferta/' . $g['slug'])) ?>"><?= e($g['title']) ?></a></td>
                        <td><?= e(Display::datetime($g['decided_at'])) ?></td>
                        <td><?= e($g['expires_at'] ? Display::datetime($g['expires_at']) : __('admin.access_unlimited')) ?></td>
                        <td>
                            <form method="post" action="<?= e(url('/admin/dostepy/' . $g['id'])) ?>" class="inline-form" data-confirm="<?= e(__('admin.confirm_revoke')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="revoke">
                                <button type="submit" class="link-button link-button--danger"><?= e(__('admin.revoke')) ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
