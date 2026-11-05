<?php
/**
 * Główny layout. Zmienne: $content (HTML strony), $title (opcjonalny), $description (opcjonalny).
 * @var string $content
 */
$portalName = (string) setting('portal.name', __('app.default_name'));
$pageTitle = isset($title) && $title !== '' ? $title . ' — ' . $portalName : $portalName;
$metaDescription = $description ?? (string) setting('portal.tagline', __('home.lead'));
$menuPages = [];
try {
    $menuPages = \App\Core\DB::fetchAll('SELECT slug, title FROM pages WHERE in_menu = 1 ORDER BY menu_order, title');
} catch (\Throwable) {
    // Brak bazy nie może zablokować wyświetlenia strony błędu.
}
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <meta name="description" content="<?= e($metaDescription) ?>">
    <?php if (!empty($noindex)): ?><meta name="robots" content="noindex"><?php endif; ?>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('theme.css')) ?>">
    <script src="<?= e(asset('js/app.js')) ?>" defer></script>
</head>
<body>
<a class="skip-link" href="#tresc"><?= e(__('app.skip_to_content')) ?></a>

<header class="site-header">
    <div class="container site-header__inner">
        <a class="brand" href="<?= e(url('/')) ?>"><?= e($portalName) ?></a>
        <?php if ($menuPages !== []): ?>
            <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="menu-glowne"><?= e(__('app.menu')) ?></button>
            <nav id="menu-glowne" class="site-nav" aria-label="<?= e(__('app.main_nav')) ?>">
                <ul>
                    <?php foreach ($menuPages as $p): ?>
                        <li><a href="<?= e(url($p['slug'])) ?>"><?= e($p['title']) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
</header>

<main id="tresc" class="site-main" tabindex="-1">
    <?= $content ?>
</main>

<footer class="site-footer">
    <div class="container">
        <p>© <?= e(date('Y')) ?> <?= e($portalName) ?>. <?= e(__('app.footer_rights')) ?></p>
    </div>
</footer>
</body>
</html>
