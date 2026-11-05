<?php
/** @var array $rows @var int $total @var int $page @var int $pages @var string $q @var string $role */
use App\Core\View;
use App\Listings\Display;
?>
<section class="container section admin">
    <?= View::partial('admin/partials/nav', ['active' => 'users']) ?>
    <div class="section-head">
        <h1><?= e(__('admin.users')) ?> <span class="muted">(<?= (int) $total ?>)</span></h1>
        <a class="button button--secondary" href="<?= e(url('/admin/uzytkownicy.csv', array_filter(['q' => $q, 'role' => $role]))) ?>"><?= e(__('admin.export_csv')) ?></a>
    </div>

    <form method="get" action="<?= e(url('/admin/uzytkownicy')) ?>" class="admin-filters">
        <div class="field">
            <label for="f-q"><?= e(__('admin.search')) ?></label>
            <input id="f-q" name="q" type="search" value="<?= e($q) ?>" placeholder="<?= e(__('admin.search_users_ph')) ?>">
        </div>
        <div class="field">
            <label for="f-role"><?= e(__('admin.col_role')) ?></label>
            <select id="f-role" name="role">
                <option value=""><?= e(__('admin.all')) ?></option>
                <?php foreach (['member', 'moderator', 'admin'] as $r): ?>
                    <option value="<?= e($r) ?>" <?= $role === $r ? 'selected' : '' ?>><?= e(__('role.' . $r)) ?></option>
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
                    <th scope="col"><?= e(__('admin.col_person')) ?></th>
                    <th scope="col"><?= e(__('register.company_name')) ?></th>
                    <th scope="col"><?= e(__('admin.col_role')) ?></th>
                    <th scope="col" class="num"><?= e(__('term.listings')) ?></th>
                    <th scope="col"><?= e(__('admin.col_registered')) ?></th>
                    <th scope="col"><?= e(__('admin.col_last_login')) ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $u): ?>
                    <tr>
                        <td>
                            <a href="<?= e(url('/admin/uzytkownicy/' . $u['id'])) ?>"><?= e($u['first_name'] . ' ' . $u['last_name']) ?></a>
                            <?php if ($u['status'] === 'blocked'): ?><span class="status status--rejected"><?= e(__('user_status.blocked')) ?></span><?php endif; ?>
                            <br><span class="muted"><?= e($u['email']) ?></span>
                            <?php if (!$u['email_verified_at']): ?><span class="badge"><?= e(__('account.unverified')) ?></span><?php endif; ?>
                        </td>
                        <td><?= e($u['company'] ?? '—') ?></td>
                        <td><?= e(__('role.' . $u['role'])) ?></td>
                        <td class="num"><?= (int) $u['listings'] ?></td>
                        <td><?= e(Display::datetime($u['created_at'])) ?></td>
                        <td><?= e(Display::datetime($u['last_login_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= View::partial('partials/pager', ['page' => $page, 'pages' => $pages, 'base' => '/admin/uzytkownicy', 'query' => ['q' => $q, 'role' => $role]]) ?>
    <?php endif; ?>
</section>
