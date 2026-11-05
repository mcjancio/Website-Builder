<?php
/**
 * Wykres słupkowy 30 dni (wyświetlenia + zapytania) — czysty HTML/CSS, z tabelą danych
 * dla czytników ekranu (wykres jest dla nich ukryty). Wysokość słupka to klasa co 5% —
 * CSP nie pozwala na style inline.
 * @var array<int,array{day:string,views:int,inquiries:int}> $series
 */
$max = max(1, ...array_column($series, 'views'));
$sumViews = array_sum(array_column($series, 'views'));
$sumInq = array_sum(array_column($series, 'inquiries'));
?>
<figure class="chart">
    <figcaption class="chart__caption">
        <span><span class="chart__key chart__key--views" aria-hidden="true"></span><?= e(__('stats.views')) ?>: <strong><?= e(\App\Core\Str::number($sumViews)) ?></strong></span>
        <span><span class="chart__key chart__key--inq" aria-hidden="true"></span><?= e(__('stats.inquiries')) ?>: <strong><?= e(\App\Core\Str::number($sumInq)) ?></strong></span>
        <span class="muted"><?= e(__('stats.last30')) ?></span>
    </figcaption>
    <div class="chart__bars" aria-hidden="true">
        <?php foreach ($series as $p): ?>
            <div class="chart__col" title="<?= e(\App\Listings\Display::date($p['day']) . ': ' . $p['views'] . ' / ' . $p['inquiries']) ?>">
                <span class="chart__bar chart__bar--<?= (int) (round($p['views'] / $max * 20) * 5) ?>"></span>
                <?php if ($p['inquiries'] > 0): ?><span class="chart__dot"></span><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <details class="chart__table">
        <summary><?= e(__('stats.table')) ?></summary>
        <table>
            <thead><tr><th scope="col"><?= e(__('stats.day')) ?></th><th scope="col"><?= e(__('stats.views')) ?></th><th scope="col"><?= e(__('stats.inquiries')) ?></th></tr></thead>
            <tbody>
                <?php foreach (array_reverse($series) as $p): if ($p['views'] === 0 && $p['inquiries'] === 0) { continue; } ?>
                    <tr><td><?= e(\App\Listings\Display::date($p['day'])) ?></td><td><?= (int) $p['views'] ?></td><td><?= (int) $p['inquiries'] ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </details>
</figure>
