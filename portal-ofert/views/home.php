<?php
/**
 * @var array<int,array{slug:string,name:string}> $categories
 * @var array $latest
 * @var int $total
 * @var int $level
 */
use App\Core\View;

$tagline = (string) setting('portal.tagline', __('home.lead'));
?>
<section class="hero">
    <div class="container">
        <h1><?= e(setting('portal.name', __('app.default_name'))) ?></h1>
        <p class="hero__lead"><?= e($tagline) ?></p>
        <p class="hero__actions">
            <a class="button" href="<?= e(url('/oferty')) ?>"><?= e(__('home.browse', ['count' => $total])) ?></a>
            <a class="button button--secondary" href="<?= e(url(auth_user() ? '/dodaj-oferte' : '/rejestracja')) ?>"><?= e(__('term.listing_add')) ?></a>
        </p>
    </div>
</section>

<?php if ($categories !== []): ?>
    <section class="container section" aria-labelledby="kategorie">
        <h2 id="kategorie"><?= e(__('term.categories')) ?></h2>
        <ul class="tiles">
            <?php foreach ($categories as $c): ?>
                <li class="tile"><a href="<?= e(url('/oferty', ['kategoria' => $c['slug']])) ?>"><?= e($c['name']) ?></a></li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<section class="container section" aria-labelledby="najnowsze">
    <h2 id="najnowsze"><?= e(__('home.latest')) ?></h2>
    <?php if ($latest === []): ?>
        <p class="empty"><?= e(__('home.no_listings')) ?></p>
    <?php else: ?>
        <div class="cards">
            <?php foreach ($latest as $item): ?>
                <?= View::partial('listings/partials/card', ['item' => $item, 'level' => $level, 'headingTag' => 'h3']) ?>
            <?php endforeach; ?>
        </div>
        <p><a href="<?= e(url('/oferty')) ?>"><?= e(__('home.all_listings')) ?> →</a></p>
    <?php endif; ?>
</section>
