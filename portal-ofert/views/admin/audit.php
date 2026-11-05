<?php
/** @var array $rows @var int $total @var int $page @var int $pages @var string $action @var string $email */
use App\Core\View;
use App\Listings\Display;
?>
<section class="container section admin">
    <?= View::partial('admin/partials/nav', ['active' => 'audit']) ?>
    <h1><?= e(__('admin.audit')) ?> <span class="muted">(<?= (int) $total ?>)</span></h1>
    <p class="muted"><?= e(__('admin.audit_help')) ?></p>

    <form method="get" action="<?= e(url('/admin/dziennik')) ?>" class="admin-filters">
        <div class="field">
            <label for="f-action"><?= e(__('admin.col_event')) ?></label>
            <input id="f-action" name="action" value="<?= e($action) ?>" placeholder="login., listing., access." pattern="[a-z0-9_.]*">
        </div>
        <div class="field">
            <label for="f-email"><?= e(__('register.email')) ?></label>
            <input id="f-email" name="email" type="search" value="<?= e($email) ?>">
        </div>
        <button type="submit" class="button button--secondary"><?= e(__('admin.filter')) ?></button>
    </form>

    <?php if ($rows === []): ?>
        <p class="empty"><?= e(__('admin.none')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--compact">
                <thead><tr>
                    <th scope="col"><?= e(__('admin.col_date')) ?></th>
                    <th scope="col"><?= e(__('admin.col_event')) ?></th>
                    <th scope="col"><?= e(__('admin.col_person')) ?></th>
                    <th scope="col"><?= e(__('admin.col_object')) ?></th>
                    <th scope="col"><?= e(__('admin.col_details')) ?></th>
                    <th scope="col">IP</th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= e(Display::datetime($r['created_at'])) ?></td>
                        <td><code><?= e($r['action']) ?></code></td>
                        <td><?= e($r['email'] ?? '—') ?></td>
                        <td><?= $r['entity_type'] ? e($r['entity_type'] . ' #' . $r['entity_id']) : '—' ?></td>
                        <td class="audit-details"><?= $r['details'] && $r['details'] !== '[]' ? '<code>' . e(mb_strimwidth((string) $r['details'], 0, 160, '…')) . '</code>' : '' ?></td>
                        <td><?= $r['ip'] !== null ? e((string) @inet_ntop($r['ip'])) : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= View::partial('partials/pager', ['page' => $page, 'pages' => $pages, 'base' => '/admin/dziennik', 'query' => ['action' => $action, 'email' => $email]]) ?>
    <?php endif; ?>
</section>
