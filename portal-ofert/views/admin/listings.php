<?php
/** @var array $rows @var int $total @var int $page @var int $pages @var string $status @var string $q */
use App\Core\View;
use App\Listings\Display;

$back = '/admin/oferty' . (($qs = http_build_query(array_filter(['status' => $status, 'q' => $q, 'strona' => $page > 1 ? $page : null]))) ? '?' . $qs : '');
$actions = [
    'published' => ['pause', 'sold', 'archive'],
    'paused' => ['resume', 'sold', 'archive'],
    'sold' => ['archive'],
    'archived' => ['restore'],
];
?>
<section class="container section admin">
    <?= View::partial('admin/partials/nav', ['active' => 'listings']) ?>
    <h1><?= e(__('admin.listings')) ?> <span class="muted">(<?= (int) $total ?>)</span></h1>

    <form method="get" action="<?= e(url('/admin/oferty')) ?>" class="admin-filters">
        <div class="field">
            <label for="f-q"><?= e(__('admin.search')) ?></label>
            <input id="f-q" name="q" type="search" value="<?= e($q) ?>" placeholder="<?= e(__('admin.search_listings_ph')) ?>">
        </div>
        <div class="field">
            <label for="f-status"><?= e(__('admin.col_status')) ?></label>
            <select id="f-status" name="status">
                <option value=""><?= e(__('admin.all')) ?></option>
                <?php foreach (['draft', 'pending', 'published', 'paused', 'sold', 'archived', 'rejected'] as $s): ?>
                    <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(__('status.' . $s)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="button button--secondary"><?= e(__('admin.filter')) ?></button>
    </form>

    <?php if ($rows === []): ?>
        <p class="empty"><?= e(__('admin.none')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr>
                    <th scope="col"><?= e(__('term.listing')) ?></th>
                    <th scope="col"><?= e(__('admin.col_status')) ?></th>
                    <th scope="col"><?= e(__('admin.col_author')) ?></th>
                    <th scope="col" class="num"><?= e(__('stats.views')) ?></th>
                    <th scope="col" class="num"><?= e(__('stats.inquiries')) ?></th>
                    <th scope="col"><?= e(__('admin.col_actions')) ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td>
                            <?php if (in_array($r['status'], ['published', 'sold'], true)): ?>
                                <a href="<?= e(url('/oferta/' . $r['slug'])) ?>"><?= e($r['title']) ?></a>
                            <?php else: ?>
                                <a href="<?= e(url('/moje-oferty/' . $r['id'] . '/podglad')) ?>"><?= e($r['title']) ?></a>
                            <?php endif; ?>
                            <?php if ($r['is_featured']): ?><span class="badge badge--ok"><?= e(__('admin.featured')) ?></span><?php endif; ?>
                            <br><span class="muted">#<?= (int) $r['id'] ?> · <?= e($r['category']) ?> · <?= e(Display::datetime($r['updated_at'])) ?></span>
                        </td>
                        <td><span class="status status--<?= e($r['status']) ?>"><?= e(__('status.' . $r['status'])) ?></span></td>
                        <td><?= e($r['email']) ?></td>
                        <td class="num"><?= (int) $r['views'] ?></td>
                        <td class="num"><?= (int) $r['inquiries'] ?></td>
                        <td>
                            <div class="row-actions">
                                <?php if ($r['status'] === 'pending'): ?>
                                    <a href="<?= e(url('/admin/moderacja/' . $r['id'])) ?>"><?= e(__('admin.moderate')) ?></a>
                                <?php endif; ?>
                                <?php foreach ($actions[$r['status']] ?? [] as $a): ?>
                                    <form method="post" action="<?= e(url('/admin/oferty/' . $r['id'])) ?>" class="inline-form"<?= in_array($a, ['archive', 'sold'], true) ? ' data-confirm="' . e(__('admin.confirm_' . $a)) . '"' : '' ?>>
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="<?= e($a) ?>">
                                        <input type="hidden" name="back" value="<?= e($back) ?>">
                                        <button type="submit" class="link-button"><?= e(__('admin.action_' . $a)) ?></button>
                                    </form>
                                <?php endforeach; ?>
                                <?php if ($r['status'] === 'published'): ?>
                                    <form method="post" action="<?= e(url('/admin/oferty/' . $r['id'])) ?>" class="inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="<?= $r['is_featured'] ? 'unfeature' : 'feature' ?>">
                                        <input type="hidden" name="back" value="<?= e($back) ?>">
                                        <button type="submit" class="link-button"><?= e(__($r['is_featured'] ? 'admin.action_unfeature' : 'admin.action_feature')) ?></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= View::partial('partials/pager', ['page' => $page, 'pages' => $pages, 'base' => '/admin/oferty', 'query' => ['status' => $status, 'q' => $q]]) ?>
    <?php endif; ?>
</section>
