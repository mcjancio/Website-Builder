<?php
/** @var array $queue */
use App\Core\View;
use App\Listings\Display;
?>
<section class="container section admin">
    <?= View::partial('admin/partials/nav', ['active' => 'moderation']) ?>
    <h1><?= e(__('admin.moderation')) ?></h1>
    <?php if ($queue === []): ?>
        <p class="empty"><?= e(__('admin.queue_empty')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr>
                    <th scope="col"><?= e(__('term.listing')) ?></th>
                    <th scope="col"><?= e(__('admin.col_kind')) ?></th>
                    <th scope="col"><?= e(__('admin.col_author')) ?></th>
                    <th scope="col"><?= e(__('admin.col_submitted')) ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($queue as $q): ?>
                    <tr>
                        <td><a href="<?= e(url('/admin/moderacja/' . $q['id'])) ?>"><?= e($q['title']) ?></a></td>
                        <td><span class="status status--pending"><?= e(__('admin.kind_' . $q['kind'])) ?></span></td>
                        <td><?= e($q['email']) ?></td>
                        <td><?= e(Display::datetime($q['submitted_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
