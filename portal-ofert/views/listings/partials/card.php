<?php
/**
 * Kafelek oferty na liście. Pokazuje tylko pola show_in_card dostępne dla użytkownika (Display::cardValues).
 * @var array $item  wiersz z ListingQuery::search (z 'values')
 * @var int $level
 * @var string $headingTag
 */
use App\Listings\Display;

$href = url('/oferta/' . $item['slug']);
$facts = Display::cardValues($item['values'], $level);
$tag = $headingTag ?? 'h2';
?>
<article class="card<?= (int) $item['is_featured'] ? ' card--featured' : '' ?>">
    <a class="card__media" href="<?= e($href) ?>" tabindex="-1" aria-hidden="true">
        <?php if ($item['cover_id']): ?>
            <img src="<?= e(url('/media/' . $item['cover_id'] . '/400')) ?>"
                 srcset="<?= e(url('/media/' . $item['cover_id'] . '/400')) ?> 400w, <?= e(url('/media/' . $item['cover_id'] . '/960')) ?> 960w"
                 sizes="(max-width: 40rem) 100vw, 22rem" alt="" width="400" height="267" loading="lazy" decoding="async">
        <?php else: ?>
            <span class="card__placeholder"><?= e(mb_substr($item['category_name'], 0, 1)) ?></span>
        <?php endif; ?>
    </a>
    <div class="card__body">
        <p class="card__meta"><?= e($item['category_name']) ?><?= $item['city'] ? ' · ' . e($item['city']) : '' ?></p>
        <<?= $tag ?> class="card__title"><a href="<?= e($href) ?>"><?= e($item['title']) ?></a></<?= $tag ?>>
        <p class="card__desc"><?= e($item['short_desc']) ?></p>
        <?php if ($facts !== []): ?>
            <dl class="card__facts">
                <?php foreach (array_slice($facts, 0, 6, true) as $label => $value): ?>
                    <div><dt><?= e($label) ?></dt><dd><?= e($value) ?></dd></div>
                <?php endforeach; ?>
            </dl>
        <?php endif; ?>
    </div>
</article>
