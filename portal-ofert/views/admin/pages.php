<?php
/** @var array $pages */
use App\Core\View;
use App\Listings\Display;
?>
<section class="container section admin">
    <?= View::partial('admin/partials/nav', ['active' => 'pages']) ?>
    <div class="section-head">
        <h1><?= e(__('admin.pages')) ?></h1>
        <a class="button" href="<?= e(url('/admin/strony/nowa')) ?>"><?= e(__('pages.new')) ?></a>
    </div>
    <p class="muted"><?= e(__('pages.help')) ?></p>
    <?php if ($pages === []): ?>
        <p class="empty"><?= e(__('admin.none')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr>
                    <th scope="col"><?= e(__('pages.title_label')) ?></th>
                    <th scope="col"><?= e(__('pages.address')) ?></th>
                    <th scope="col"><?= e(__('pages.in_menu')) ?></th>
                    <th scope="col" class="num"><?= e(__('pages.version')) ?></th>
                    <th scope="col"><?= e(__('admin.col_updated')) ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($pages as $p): ?>
                    <tr>
                        <td><a href="<?= e(url('/admin/strony/' . $p['id'])) ?>"><?= e($p['title']) ?></a></td>
                        <td><a href="<?= e(url('/' . $p['slug'])) ?>">/<?= e($p['slug']) ?></a></td>
                        <td><?= e($p['in_menu'] ? __('admin.yes') : __('admin.no')) ?></td>
                        <td class="num"><?= (int) $p['version'] ?></td>
                        <td><?= e(Display::datetime($p['updated_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
