<?php
/**
 * Podgląd dla autora i moderacji: WSZYSTKIE poziomy dostępu, z oznaczeniem, kto co zobaczy.
 * Publiczna strona oferty (z filtrowaniem przez Gate) — etap 4.
 * @var array $listing
 * @var array $state
 * @var array $groups
 * @var string $description
 * @var string $confidential
 * @var array $images
 * @var array $attachments
 * @var ?string $category
 * @var bool $isStaff
 */
use App\Core\Str;
use App\Listings\Fields;

$levels = (array) setting('access_levels', []);
$levelName = static fn(int $l) => $levels[$l]['name'] ?? __('access.level_' . $l);
$badge = static fn(int $l) => '<span class="level-badge level-badge--' . $l . '">' . e($levelName($l)) . '</span>';

$format = static function (array $f, mixed $v): string {
    return match ($f['type']) {
        'money' => Str::number((float) $v['amount'], fmod((float) $v['amount'], 1.0) == 0.0 ? 0 : 2) . ' ' . $v['currency'],
        'decimal' => Str::number((float) $v, str_contains((string) $v, '.') ? 2 : 0),
        'percent' => Str::number((float) $v, str_contains((string) $v, '.') ? 1 : 0) . ' %',
        'int' => Str::number((int) $v),
        'bool' => __('common.yes'),
        'date' => (string) $v,
        'select', 'region' => $f['options'][$v]['label'] ?? (string) $v,
        'multiselect' => implode(', ', array_map(static fn($k) => $f['options'][$k]['label'] ?? $k, (array) $v)),
        'url_list' => implode("\n", (array) $v),
        default => (string) $v,
    };
};
?>
<article class="container section listing-preview">
    <p class="wizard__back"><a href="<?= e(url($isStaff && !\App\Access\Gate::isOwner(auth_user(), $listing) ? '/konto' : '/moje-oferty/' . $listing['id'] . '/edycja/4')) ?>">← <?= e(__('preview.back')) ?></a></p>
    <p class="notice"><?= e(__('preview.info')) ?></p>
    <p class="muted"><?= e((string) $category) ?> · <span class="status status--<?= e($listing['status']) ?>"><?= e(__('status.' . $listing['status'])) ?></span></p>
    <h1><?= e($state['core']['title']) ?> <?= $badge(1) ?></h1>

    <?php if ($images !== []): ?>
        <div class="preview-gallery">
            <?php foreach ($images as $m): ?>
                <figure class="<?= $m['state'] !== 'live' ? 'is-' . e($m['state']) : '' ?>">
                    <img src="<?= e(url('/media/' . $m['id'] . '/960')) ?>" alt="<?= e($m['alt'] ?? '') ?>" loading="lazy" width="480" height="<?= (int) round(480 * ((int) $m['height'] ?: 3) / ((int) $m['width'] ?: 4)) ?>">
                    <?php if ($m['state'] !== 'live'): ?><figcaption class="badge"><?= e(__('media.state_' . $m['state'])) ?></figcaption><?php endif; ?>
                </figure>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <p class="lead"><?= e($state['core']['short_desc']) ?></p>

    <?php foreach ($groups as $group => $fields):
        $rows = array_filter($fields, static fn($f) => array_key_exists($f['key'], $state['values']));
        if ($rows === []) { continue; } ?>
        <h2 class="h3"><?= e($group) ?></h2>
        <dl class="data-list">
            <?php foreach ($rows as $key => $f): ?>
                <dt><?= e($f['label']) ?> <?= $badge((int) $f['access_level']) ?></dt>
                <dd>
                    <?php if ($f['type'] === 'url_list' || $f['type'] === 'url'): ?>
                        <?php foreach ((array) $state['values'][$key] as $u): ?><a href="<?= e($u) ?>" rel="noopener nofollow" target="_blank"><?= e($u) ?></a><br><?php endforeach; ?>
                    <?php else: ?>
                        <?= e($format($f, $state['values'][$key])) ?>
                    <?php endif; ?>
                </dd>
            <?php endforeach; ?>
        </dl>
    <?php endforeach; ?>

    <?php if (trim(strip_tags($description)) !== ''): ?>
        <h2 class="h3"><?= e(__('listing.full_desc')) ?> <?= $badge(2) ?></h2>
        <div class="prose"><?= $description ?></div>
    <?php endif; ?>
    <?php if (trim(strip_tags($confidential)) !== ''): ?>
        <h2 class="h3"><?= e(__('listing.confidential')) ?> <?= $badge(3) ?></h2>
        <div class="prose"><?= $confidential ?></div>
    <?php endif; ?>

    <?php if ($attachments !== []): ?>
        <h2 class="h3"><?= e(__('wizard.documents_title')) ?></h2>
        <ul class="file-list">
            <?php foreach ($attachments as $a):
                $lvl = (int) ($state['attachments_meta'][$a['id']]['access_level'] ?? $a['access_level']); ?>
                <li><a href="<?= e(url('/plik/' . $a['id'])) ?>"><?= e($state['attachments_meta'][$a['id']]['label'] ?? $a['label']) ?></a>
                    <span class="muted"><?= e(Str::bytes((int) $a['size_bytes'])) ?></span> <?= $badge($lvl) ?>
                    <?php if ($a['state'] !== 'live'): ?><span class="badge"><?= e(__('media.state_' . $a['state'])) ?></span><?php endif; ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($isStaff): ?>
        <p class="notice"><?= e(__('preview.staff_cli', ['id' => $listing['id']])) ?></p>
    <?php endif; ?>
</article>
