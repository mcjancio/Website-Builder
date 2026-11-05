<?php
/** @var array $rows @var int $total @var int $page @var int $pages @var string $status @var string $q */
use App\Core\View;
use App\Listings\Display;
?>
<section class="container section admin">
    <?= View::partial('admin/partials/nav', ['active' => 'inquiries']) ?>
    <div class="section-head">
        <h1><?= e(__('admin.inquiries')) ?> <span class="muted">(<?= (int) $total ?>)</span></h1>
        <a class="button button--secondary" href="<?= e(url('/admin/zapytania.csv', array_filter(['status' => $status, 'q' => $q]))) ?>"><?= e(__('admin.export_csv')) ?></a>
    </div>

    <form method="get" action="<?= e(url('/admin/zapytania')) ?>" class="admin-filters">
        <div class="field">
            <label for="f-q"><?= e(__('admin.search')) ?></label>
            <input id="f-q" name="q" type="search" value="<?= e($q) ?>" placeholder="<?= e(__('admin.search_inquiries_ph')) ?>">
        </div>
        <div class="field">
            <label for="f-status"><?= e(__('admin.col_status')) ?></label>
            <select id="f-status" name="status">
                <option value=""><?= e(__('admin.all')) ?></option>
                <?php foreach (\App\Http\Controllers\Admin\InquiriesController::STATUSES as $s): ?>
                    <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(__('inquiry_status.' . $s)) ?></option>
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
                    <th scope="col"><?= e(__('term.listing')) ?></th>
                    <th scope="col"><?= e(__('admin.col_status')) ?></th>
                    <th scope="col"><?= e(__('admin.col_assignee')) ?></th>
                    <th scope="col"><?= e(__('admin.col_date')) ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><a href="<?= e(url('/admin/zapytania/' . $r['id'])) ?>"><?= e($r['name']) ?></a><br><span class="muted"><?= e($r['email']) ?></span></td>
                        <td><?= e($r['listing_title'] ?? __('admin.general_contact')) ?></td>
                        <td><span class="status status--inq-<?= e($r['status']) ?>"><?= e(__('inquiry_status.' . $r['status'])) ?></span></td>
                        <td><?= e($r['assignee'] ?? '—') ?></td>
                        <td><?= e(Display::datetime($r['created_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= View::partial('partials/pager', ['page' => $page, 'pages' => $pages, 'base' => '/admin/zapytania', 'query' => ['status' => $status, 'q' => $q]]) ?>
    <?php endif; ?>
</section>
