<?php
/**
 * @var array $result   items, total, page, pages, applied
 * @var int $level
 * @var array $filterFields
 * @var array $sortOptions
 * @var array $categories
 * @var array $query
 * @var ?array $user
 */
use App\Core\View;

$pageUrl = static function (int $p) use ($query): string {
    $q = $query;
    unset($q['strona']);
    if ($p > 1) {
        $q['strona'] = $p;
    }
    return url('/oferty', $q);
};
$currentSort = is_scalar($query['sort'] ?? null) && isset($sortOptions[$query['sort']]) ? (string) $query['sort'] : 'najnowsze';
?>
<section class="container section listings">
    <div class="listings__head">
        <h1><?= e($title) ?></h1>
        <p class="listings__count" aria-live="polite"><?= e(__('listings.count', ['count' => $result['total']])) ?></p>
    </div>

    <div class="listings__layout">
        <aside class="listings__aside">
            <?= View::partial('listings/partials/filters', [
                'filterFields' => $filterFields, 'categories' => $categories, 'query' => $query,
                'applied' => $result['applied'], 'sortOptions' => $sortOptions,
            ]) ?>
            <?php if (!empty($memberFilters)): ?>
                <p class="hint listings__more-filters"><?= e(__('listings.more_filters_members')) ?> <a href="<?= e(url('/logowanie', ['next' => '/oferty'])) ?>"><?= e(__('nav.login')) ?></a></p>
            <?php endif; ?>
        </aside>

        <div class="listings__main">
            <?php if ($result['total'] > 1 && count($sortOptions) > 1): ?>
                <form method="get" action="<?= e(url('/oferty')) ?>" class="sort" data-sort-form>
                    <?php foreach ($query as $qk => $qv): if (in_array($qk, ['sort', 'strona'], true)) { continue; } ?>
                        <?php foreach ((array) $qv as $one): if (!is_scalar($one) || $one === '') { continue; } ?>
                            <input type="hidden" name="<?= e(is_array($qv) ? $qk . '[]' : $qk) ?>" value="<?= e((string) $one) ?>">
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    <label for="sort"><?= e(__('listings.sort')) ?></label>
                    <select id="sort" name="sort">
                        <?php foreach ($sortOptions as $key => $f): ?>
                            <?php $label = $f === null ? __('listings.sort_' . $key) : __(str_ends_with($key, '_rosnaco') ? 'listings.sort_asc' : 'listings.sort_desc', ['field' => $f['label']]); ?>
                            <option value="<?= e($key) ?>" <?= $key === $currentSort ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="button button--secondary sort__submit"><?= e(__('listings.apply')) ?></button>
                </form>
            <?php endif; ?>

            <?php if ($result['items'] === []): ?>
                <div class="empty">
                    <p><?= e(__('listings.empty')) ?></p>
                    <?php if ($result['applied'] !== []): ?><p><a href="<?= e(url('/oferty')) ?>"><?= e(__('listings.clear')) ?></a></p><?php endif; ?>
                </div>
            <?php else: ?>
                <div class="cards">
                    <?php foreach ($result['items'] as $item): ?>
                        <?= View::partial('listings/partials/card', ['item' => $item, 'level' => $level, 'headingTag' => 'h2']) ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($result['pages'] > 1): ?>
                <nav class="pagination" aria-label="<?= e(__('listings.pages')) ?>">
                    <?php if ($result['page'] > 1): ?><a href="<?= e($pageUrl($result['page'] - 1)) ?>" rel="prev">← <?= e(__('listings.prev')) ?></a><?php endif; ?>
                    <span><?= e(__('listings.page_of', ['page' => $result['page'], 'pages' => $result['pages']])) ?></span>
                    <?php if ($result['page'] < $result['pages']): ?><a href="<?= e($pageUrl($result['page'] + 1)) ?>" rel="next"><?= e(__('listings.next')) ?> →</a><?php endif; ?>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</section>
