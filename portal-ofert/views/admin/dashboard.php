<?php
/**
 * @var array $counts @var array $series @var ?int $campaignUntil @var array $recentUsers
 */
use App\Core\View;
use App\Listings\Display;

$isAdmin = (auth_user()['role'] ?? '') === 'admin';
$tiles = [
    ['moderation', '/admin/moderacja', __('admin.tile_moderation')],
    ['access', '/admin/dostepy', __('admin.tile_access')],
    ['inquiries', '/admin/zapytania?status=new', __('admin.tile_inquiries')],
    ['published', '/admin/oferty?status=published', __('admin.tile_published')],
];
if ($isAdmin) {
    $tiles[] = ['users7', '/admin/uzytkownicy', __('admin.tile_users7')];
}
?>
<section class="container section admin">
    <?= View::partial('admin/partials/nav', ['active' => 'dashboard']) ?>
    <h1><?= e(__('admin.dashboard')) ?></h1>

    <ul class="stat-tiles">
        <?php foreach ($tiles as [$key, $href, $label]): ?>
            <li class="stat-tile<?= in_array($key, ['moderation', 'access', 'inquiries'], true) && $counts[$key] > 0 ? ' stat-tile--attention' : '' ?>">
                <a href="<?= e(url($href)) ?>">
                    <span class="stat-tile__value"><?= e(\App\Core\Str::number($counts[$key])) ?></span>
                    <span class="stat-tile__label"><?= e($label) ?></span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if ($counts['failedJobs'] > 0): ?>
        <p class="notice notice--warning" role="status"><?= e(__('admin.failed_jobs', ['count' => $counts['failedJobs']])) ?></p>
    <?php endif; ?>

    <h2><?= e(__('admin.traffic')) ?></h2>
    <?= View::partial('partials/chart', ['series' => $series]) ?>

    <?php if ($isAdmin): ?>
        <section class="admin-box" aria-labelledby="kampania-h">
            <h2 id="kampania-h" class="h3"><?= e(__('admin.campaign_title')) ?></h2>
            <p class="muted"><?= e(__('admin.campaign_help')) ?></p>
            <?php if ($campaignUntil !== null): ?>
                <p class="notice notice--warning"><?= e(__('admin.campaign_on', ['until' => Display::datetime(gmdate('Y-m-d H:i:s', $campaignUntil))])) ?></p>
                <form method="post" action="<?= e(url('/admin/tryb-kampanii')) ?>" class="inline-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="disable">
                    <button type="submit" class="button button--secondary"><?= e(__('admin.campaign_disable')) ?></button>
                </form>
            <?php else: ?>
                <form method="post" action="<?= e(url('/admin/tryb-kampanii')) ?>" class="inline-form admin-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="enable">
                    <label for="campaign-hours"><?= e(__('admin.campaign_hours')) ?></label>
                    <select id="campaign-hours" name="hours">
                        <?php foreach ([3, 6, 12, 24, 48, 72] as $h): ?>
                            <option value="<?= $h ?>" <?= $h === 6 ? 'selected' : '' ?>><?= $h ?> h</option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="button"><?= e(__('admin.campaign_enable')) ?></button>
                </form>
            <?php endif; ?>
        </section>

        <h2><?= e(__('admin.recent_users')) ?></h2>
        <?php if ($recentUsers === []): ?>
            <p class="empty"><?= e(__('admin.none')) ?></p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th scope="col"><?= e(__('admin.col_person')) ?></th><th scope="col"><?= e(__('register.company_name')) ?></th><th scope="col"><?= e(__('admin.col_registered')) ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($recentUsers as $u): ?>
                        <tr>
                            <td><a href="<?= e(url('/admin/uzytkownicy/' . $u['id'])) ?>"><?= e($u['first_name'] . ' ' . $u['last_name']) ?></a><br><span class="muted"><?= e($u['email']) ?></span></td>
                            <td><?= e($u['company'] ?? '—') ?></td>
                            <td><?= e(Display::datetime($u['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>
