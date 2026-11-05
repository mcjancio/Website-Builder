<?php
/**
 * Moderacja jednej oferty: porównanie „było / jest”, pliki, autor, decyzja.
 * @var array $listing @var bool $isRevision @var ?array $revision @var bool $pending
 * @var array $diff @var array $images @var array $attachments @var ?array $author @var int $authorListings
 */
use App\Core\Str;
use App\Core\View;
use App\Listings\Display;

$changed = count(array_filter($diff, static fn($r) => $r['changed']));
// Wiersze zebrane w grupy (kolejność pierwszego wystąpienia) — pola jednej grupy mogą być rozrzucone w kolejności presetu.
$grouped = [];
foreach ($diff as $row) {
    $grouped[$row['group']][] = $row;
}
$stateLabel = static fn(string $s): string => match ($s) {
    'pending_add' => __('admin.file_added'),
    'pending_remove' => __('admin.file_removed'),
    default => '',
};
?>
<section class="container section admin">
    <?= View::partial('admin/partials/nav', ['active' => 'moderation']) ?>
    <p class="breadcrumbs"><a href="<?= e(url('/admin/moderacja')) ?>">← <?= e(__('admin.moderation')) ?></a></p>
    <h1><?= e($listing['title']) ?></h1>
    <p>
        <span class="status status--<?= e($listing['status']) ?>"><?= e(__('status.' . $listing['status'])) ?></span>
        <?php if ($isRevision && $revision): ?><span class="status status--pending"><?= e(__('admin.kind_revision')) ?> (<?= e(__('status.' . $revision['status'])) ?>)</span><?php endif; ?>
        <a href="<?= e(url('/moje-oferty/' . $listing['id'] . '/podglad')) ?>"><?= e(__('admin.open_preview')) ?></a>
    </p>

    <div class="moderation">
        <div class="moderation__main">
            <h2><?= e($isRevision ? __('admin.diff_title', ['count' => $changed]) : __('admin.content_title')) ?></h2>
            <?php if ($isRevision && $changed > 0): ?>
                <p class="field--check"><label><input type="checkbox" data-diff-only> <span><?= e(__('admin.diff_only_changed')) ?></span></label></p>
            <?php endif; ?>
            <div class="table-wrap">
                <table class="table diff">
                    <thead><tr>
                        <th scope="col"><?= e(__('admin.col_field')) ?></th>
                        <?php if ($isRevision): ?><th scope="col"><?= e(__('admin.before')) ?></th><?php endif; ?>
                        <th scope="col"><?= e($isRevision ? __('admin.after') : __('admin.value')) ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($grouped as $group => $rows): $groupChanged = array_filter($rows, static fn($r) => $r['changed']) !== []; ?>
                        <tr class="diff__group<?= $groupChanged ? '' : ' diff__row' ?>"><th scope="rowgroup" colspan="<?= $isRevision ? 3 : 2 ?>"><?= e((string) $group) ?></th></tr>
                        <?php foreach ($rows as $row): ?>
                        <tr class="<?= $isRevision && $row['changed'] ? 'diff__row--changed' : 'diff__row' ?>">
                            <th scope="row">
                                <?= e($row['label']) ?>
                                <span class="level-badge level-badge--<?= (int) $row['level'] ?>" title="<?= e(__('access.badge_title')) ?>"><?= e(__('access.level_' . (int) $row['level'])) ?></span>
                                <?php if ($isRevision && $row['changed']): ?><span class="visually-hidden"><?= e(__('admin.changed')) ?></span><?php endif; ?>
                            </th>
                            <?php if ($isRevision): ?><td class="diff__before"><?= nl2br(e($row['before'] !== '' ? $row['before'] : '—')) ?></td><?php endif; ?>
                            <td class="diff__after"><?= nl2br(e($row['after'] !== '' ? $row['after'] : '—')) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($images !== [] || $attachments !== []): ?>
                <h2><?= e(__('admin.files')) ?></h2>
                <ul class="media-grid">
                    <?php foreach ($images as $img): ?>
                        <li class="media-item<?= $img['state'] !== 'live' ? ' media-item--' . e($img['state']) : '' ?>">
                            <img src="<?= e(url('/media/' . $img['id'] . '/400')) ?>" alt="<?= e($img['alt'] ?? '') ?>" width="200" height="133" loading="lazy">
                            <?php if ($stateLabel($img['state']) !== ''): ?><span class="status status--pending"><?= e($stateLabel($img['state'])) ?></span><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($attachments !== []): ?>
                    <ul class="doc-list">
                        <?php foreach ($attachments as $a): ?>
                            <li>
                                <a href="<?= e(url('/plik/' . $a['id'])) ?>"><?= e($a['label'] ?: $a['original_name']) ?></a>
                                <span class="muted"><?= e(Str::bytes((int) $a['size_bytes'])) ?></span>
                                <span class="level-badge level-badge--<?= (int) $a['access_level'] ?>"><?= e(__('access.level_' . (int) $a['access_level'])) ?></span>
                                <?php if ($stateLabel($a['state']) !== ''): ?><span class="status status--pending"><?= e($stateLabel($a['state'])) ?></span><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <aside class="moderation__aside">
            <?php if ($author): ?>
                <section class="admin-box" aria-labelledby="autor-h">
                    <h2 id="autor-h" class="h3"><?= e(__('admin.col_author')) ?></h2>
                    <p><strong><?= e($author['first_name'] . ' ' . $author['last_name']) ?></strong><br>
                        <?= e($author['email']) ?><br><?= e($author['phone_e164']) ?></p>
                    <?php if ($author['company']): ?><p><?= e($author['company']) ?> <span class="muted">NIP <?= e($author['nip']) ?></span></p><?php endif; ?>
                    <p class="muted"><?= e(__('admin.author_since', ['date' => Display::datetime($author['created_at']), 'count' => $authorListings])) ?></p>
                    <?php if ((auth_user()['role'] ?? '') === 'admin'): ?>
                        <p><a href="<?= e(url('/admin/uzytkownicy/' . $author['id'])) ?>"><?= e(__('admin.user_details')) ?></a></p>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if ($pending): ?>
                <section class="admin-box" aria-labelledby="decyzja-h">
                    <h2 id="decyzja-h" class="h3"><?= e(__('admin.decision')) ?></h2>
                    <form method="post" action="<?= e(url('/admin/moderacja/' . $listing['id'])) ?>" class="form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="approve">
                        <button type="submit" class="button button--block"><?= e(__('admin.approve')) ?></button>
                    </form>
                    <form method="post" action="<?= e(url('/admin/moderacja/' . $listing['id'])) ?>" class="form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="reject">
                        <div class="field">
                            <label for="note"><?= e(__('admin.reject_note')) ?></label>
                            <textarea id="note" name="note" rows="4" maxlength="500" required minlength="5"></textarea>
                            <p class="hint"><?= e(__('admin.reject_note_hint')) ?></p>
                        </div>
                        <button type="submit" class="button button--secondary button--block"><?= e(__('admin.reject')) ?></button>
                    </form>
                </section>
            <?php else: ?>
                <p class="notice"><?= e(__('admin.not_pending')) ?></p>
            <?php endif; ?>
        </aside>
    </div>
</section>
