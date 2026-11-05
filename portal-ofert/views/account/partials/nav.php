<?php
/** Nawigacja „Moje konto”. @var string $active */
$u = auth_user();
$items = [
    'account' => ['/konto', __('nav.account')],
    'mine' => ['/moje-oferty', __('mine.title')],
    'favorites' => ['/konto/obserwowane', __('favorites.title')],
    'searches' => ['/konto/wyszukiwania', __('saved.title')],
    'access' => ['/konto/dostepy', __('myaccess.title')],
    'profile' => ['/konto/profil', __('profile.title')],
    'security' => ['/konto/bezpieczenstwo', __('security.title')],
];
if (in_array($u['role'] ?? '', ['admin', 'moderator'], true)) {
    $items['admin'] = ['/admin', __('admin.panel')];
}
?>
<nav class="subnav" aria-label="<?= e(__('nav.account')) ?>">
    <ul>
        <?php foreach ($items as $key => [$href, $label]): ?>
            <li><a href="<?= e(url($href)) ?>" <?= ($active ?? '') === $key ? 'aria-current="page"' : '' ?>><?= e($label) ?></a></li>
        <?php endforeach; ?>
    </ul>
</nav>
