<?php
/** Nawigacja panelu. @var string $active */
$u = auth_user();
$isAdmin = ($u['role'] ?? '') === 'admin';
$items = [
    'dashboard' => ['/admin', __('admin.dashboard')],
    'moderation' => ['/admin/moderacja', __('admin.moderation')],
    'listings' => ['/admin/oferty', __('admin.listings')],
    'inquiries' => ['/admin/zapytania', __('admin.inquiries')],
    'access' => ['/admin/dostepy', __('admin.access')],
];
if ($isAdmin) {
    $items += [
        'users' => ['/admin/uzytkownicy', __('admin.users')],
        'fields' => ['/admin/kryteria', __('admin.fields')],
        'pages' => ['/admin/strony', __('admin.pages')],
        'settings' => ['/admin/ustawienia', __('admin.settings')],
        'audit' => ['/admin/dziennik', __('admin.audit')],
    ];
}
?>
<nav class="subnav" aria-label="<?= e(__('admin.nav_label')) ?>">
    <ul>
        <?php foreach ($items as $key => [$href, $label]): ?>
            <li><a href="<?= e(url($href)) ?>" <?= ($active ?? '') === $key ? 'aria-current="page"' : '' ?>><?= e($label) ?></a></li>
        <?php endforeach; ?>
    </ul>
</nav>
