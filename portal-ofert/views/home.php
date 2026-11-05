<?php
/** @var array<int,array{slug:string,name:string}> $categories */
$tagline = (string) setting('portal.tagline', __('home.lead'));
?>
<section class="hero">
    <div class="container">
        <h1><?= e(setting('portal.name', __('app.default_name'))) ?></h1>
        <p class="hero__lead"><?= e($tagline) ?></p>
        <p class="notice"><?= e(__('home.coming_soon')) ?></p>
    </div>
</section>

<section class="container section" aria-labelledby="kategorie">
    <h2 id="kategorie"><?= e(__('term.categories')) ?></h2>
    <?php if ($categories === []): ?>
        <p class="muted"><?= e(__('home.no_categories')) ?></p>
    <?php else: ?>
        <ul class="tiles">
            <?php foreach ($categories as $c): ?>
                <li class="tile"><?= e($c['name']) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
