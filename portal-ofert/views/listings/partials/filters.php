<?php
/**
 * Panel filtrów — generowany z pól presetu (filterable + filter_ui). Formularz GET: wynik ma
 * zwykły adres URL, który można wkleić do kampanii, mailingu czy SMS-a.
 * @var array $filterFields
 * @var array $categories
 * @var array $query
 * @var array $applied
 * @var array $sortOptions
 */
$q = static fn(string $k): string => is_scalar($query[$k] ?? null) ? (string) $query[$k] : '';
$sel = static fn(string $k): array => array_map('strval', is_array($query[$k] ?? null) ? $query[$k] : (($query[$k] ?? '') !== '' && is_scalar($query[$k]) ? explode(',', (string) $query[$k]) : []));
$activeCount = count(array_diff_key($applied, ['sort' => 1]));
?>
<form method="get" action="<?= e(url('/oferty')) ?>" class="filters" role="search" aria-label="<?= e(__('listings.filters')) ?>">
    <details class="filters__details" <?= $activeCount > 0 ? 'open' : '' ?> data-filters-details>
        <summary class="filters__summary"><?= e(__('listings.filters')) ?><?= $activeCount > 0 ? ' (' . $activeCount . ')' : '' ?></summary>

        <div class="filters__body">
            <div class="field">
                <label for="flt-q"><?= e(__('listings.search')) ?></label>
                <input id="flt-q" name="q" type="search" value="<?= e($q('q')) ?>" maxlength="100" placeholder="<?= e(__('listings.search_placeholder')) ?>">
            </div>

            <?php if (count($categories) > 1): ?>
                <div class="field">
                    <label for="flt-kategoria"><?= e(__('term.categories')) ?></label>
                    <select id="flt-kategoria" name="kategoria">
                        <option value=""><?= e(__('listings.all')) ?></option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= e($c['slug']) ?>" <?= $q('kategoria') === $c['slug'] ? 'selected' : '' ?>><?= $c['parent_id'] ? '— ' : '' ?><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <?php foreach ($filterFields as $key => $f): ?>
                <?php switch ($f['filter_ui']):
                    case 'select': ?>
                        <div class="field">
                            <label for="flt-<?= e($key) ?>"><?= e($f['label']) ?></label>
                            <select id="flt-<?= e($key) ?>" name="<?= e($key) ?>">
                                <option value=""><?= e(__('listings.all')) ?></option>
                                <?php foreach ($f['options'] as $ok => $o): ?>
                                    <option value="<?= e($ok) ?>" <?= in_array((string) $ok, $sel($key), true) ? 'selected' : '' ?>><?= e($o['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php break;
                    case 'checkboxes': ?>
                        <fieldset class="checks filters__checks">
                            <legend><?= e($f['label']) ?></legend>
                            <?php foreach ($f['options'] as $ok => $o): ?>
                                <label class="check"><input type="checkbox" name="<?= e($key) ?>[]" value="<?= e($ok) ?>" <?= in_array((string) $ok, $sel($key), true) ? 'checked' : '' ?>> <span><?= e($o['label']) ?></span></label>
                            <?php endforeach; ?>
                        </fieldset>
                        <?php break;
                    case 'range':
                        $unit = match ($f['type']) { 'money' => 'zł', 'percent' => '%', default => '' };
                        $type = $f['type'] === 'date' ? 'date' : 'text'; ?>
                        <fieldset class="range">
                            <legend><?= e($f['label']) ?><?= $unit ? ' (' . e($unit) . ')' : '' ?></legend>
                            <div class="range__inputs">
                                <label><span class="visually-hidden"><?= e($f['label']) ?> — </span><?= e(__('listings.from')) ?>
                                    <input name="<?= e($key) ?>_od" type="<?= $type ?>" value="<?= e($q($key . '_od')) ?>" <?= $type === 'text' ? 'inputmode="decimal"' : '' ?>></label>
                                <label><span class="visually-hidden"><?= e($f['label']) ?> — </span><?= e(__('listings.to')) ?>
                                    <input name="<?= e($key) ?>_do" type="<?= $type ?>" value="<?= e($q($key . '_do')) ?>" <?= $type === 'text' ? 'inputmode="decimal"' : '' ?>></label>
                            </div>
                        </fieldset>
                        <?php break;
                    case 'toggle': ?>
                        <label class="check check--single"><input type="checkbox" name="<?= e($key) ?>" value="1" <?= $q($key) === '1' ? 'checked' : '' ?>> <span><?= e($f['label']) ?></span></label>
                        <?php break;
                endswitch; ?>
            <?php endforeach; ?>

            <?php if ($q('sort') !== ''): ?><input type="hidden" name="sort" value="<?= e($q('sort')) ?>"><?php endif; ?>
            <div class="filters__actions">
                <button type="submit" class="button"><?= e(__('listings.apply')) ?></button>
                <?php if ($activeCount > 0): ?><a href="<?= e(url('/oferty')) ?>"><?= e(__('listings.clear')) ?></a><?php endif; ?>
            </div>
        </div>
    </details>
</form>
