<?php
/**
 * Kontrolka formularza dla pola z presetu (dowolny typ). Nazwy: f[klucz], f[klucz][amount|currency], f[klucz][].
 * @var array $field  definicja (Fields)
 * @var mixed $value  wartość znormalizowana (FieldInput) albo surowa z POST po błędzie
 * @var ?string $error
 * @var array $accessLevels  nazwy poziomów z presetu
 * @var bool $aiReview       wartość wpisana przez asystenta AI, jeszcze niesprawdzona przez autora
 */
$k = $field['key'];
$id = 'f-' . $k;
$name = 'f[' . $k . ']';
$req = $field['required'] ? ' <span class="req" aria-hidden="true">*</span>' : '';
$describedBy = trim(($field['help'] ? $id . '-help ' : '') . ($error ? $id . '-error' : ''));
$aria = ($describedBy !== '' ? ' aria-describedby="' . e($describedBy) . '"' : '') . ($error ? ' aria-invalid="true"' : '');
$level = (int) $field['access_level'];
$levelName = $accessLevels[$level]['name'] ?? __('access.level_' . $level);
$badge = '<span class="level-badge level-badge--' . $level . '" title="' . e(__('access.badge_title')) . '">' . e($levelName) . '</span>';
if (!empty($aiReview)) {
    $badge .= ' <span class="ai-badge" title="' . e(__('ai.badge_title')) . '">' . e(__('ai.badge')) . '</span>';
}
$scalar = is_scalar($value) ? (string) $value : '';
?>
<div class="field field--<?= e($field['type']) ?><?= !empty($aiReview) ? ' field--ai' : '' ?>">
<?php switch ($field['type']):
    case 'textarea': ?>
        <label for="<?= e($id) ?>"><?= e($field['label']) ?><?= $req ?> <?= $badge ?></label>
        <textarea id="<?= e($id) ?>" name="<?= e($name) ?>" rows="4" maxlength="1000"<?= $aria ?>><?= e($scalar) ?></textarea>
        <?php break;

    case 'url_list': ?>
        <label for="<?= e($id) ?>"><?= e($field['label']) ?><?= $req ?> <?= $badge ?></label>
        <textarea id="<?= e($id) ?>" name="<?= e($name) ?>" rows="3" placeholder="https://www.youtube.com/watch?v=…"<?= $aria ?>><?= e(is_array($value) ? implode("\n", $value) : $scalar) ?></textarea>
        <p class="hint"><?= e(__('field.url_list_hint')) ?></p>
        <?php break;

    case 'money':
        $amount = is_array($value) ? (string) ($value['amount'] ?? '') : '';
        $currency = is_array($value) ? (string) ($value['currency'] ?? '') : '';
        $currencies = (array) setting('portal.currencies', ['PLN']);
        $amountShown = $amount !== '' && is_numeric($amount) ? \App\Core\Str::number((float) $amount, fmod((float) $amount, 1.0) == 0.0 ? 0 : 2) : $amount; ?>
        <label for="<?= e($id) ?>"><?= e($field['label']) ?><?= $req ?> <?= $badge ?></label>
        <div class="money-input">
            <input id="<?= e($id) ?>" name="<?= e($name) ?>[amount]" value="<?= e($amountShown) ?>" inputmode="decimal" autocomplete="off" data-number<?= $aria ?>>
            <?php if (count($currencies) > 1): ?>
                <select name="<?= e($name) ?>[currency]" aria-label="<?= e(__('field.currency_label')) ?>">
                    <?php foreach ($currencies as $c): ?>
                        <option value="<?= e($c) ?>" <?= $c === ($currency ?: setting('portal.default_currency', 'PLN')) ? 'selected' : '' ?>><?= e($c) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <span class="money-input__currency"><?= e($currencies[0] ?? 'PLN') ?></span>
                <input type="hidden" name="<?= e($name) ?>[currency]" value="<?= e($currencies[0] ?? 'PLN') ?>">
            <?php endif; ?>
        </div>
        <?php break;

    case 'int':
    case 'decimal':
    case 'percent': ?>
        <label for="<?= e($id) ?>"><?= e($field['label']) ?><?= $field['type'] === 'percent' ? ' (%)' : '' ?><?= $req ?> <?= $badge ?></label>
        <input id="<?= e($id) ?>" name="<?= e($name) ?>" value="<?= e($scalar !== '' && is_numeric($scalar) ? str_replace('.', ',', $scalar) : $scalar) ?>"
               inputmode="<?= $field['type'] === 'int' ? 'numeric' : 'decimal' ?>" autocomplete="off" class="input--short"<?= $aria ?>>
        <?php break;

    case 'date': ?>
        <label for="<?= e($id) ?>"><?= e($field['label']) ?><?= $req ?> <?= $badge ?></label>
        <input id="<?= e($id) ?>" name="<?= e($name) ?>" type="date" value="<?= e($scalar) ?>" class="input--short"<?= $aria ?>>
        <?php break;

    case 'select':
    case 'region': ?>
        <label for="<?= e($id) ?>"><?= e($field['label']) ?><?= $req ?> <?= $badge ?></label>
        <select id="<?= e($id) ?>" name="<?= e($name) ?>"<?= $aria ?>>
            <option value=""><?= e(__('field.choose')) ?></option>
            <?php foreach ($field['options'] as $ok => $o): ?>
                <option value="<?= e($ok) ?>" <?= $scalar === (string) $ok ? 'selected' : '' ?>><?= e($o['label']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php break;

    case 'multiselect':
        $selected = is_array($value) ? array_map('strval', $value) : []; ?>
        <fieldset class="checks"<?= $aria ?>>
            <legend><?= e($field['label']) ?><?= $req ?> <?= $badge ?></legend>
            <?php foreach ($field['options'] as $ok => $o): ?>
                <label class="check"><input type="checkbox" name="<?= e($name) ?>[]" value="<?= e($ok) ?>" <?= in_array((string) $ok, $selected, true) ? 'checked' : '' ?>> <span><?= e($o['label']) ?></span></label>
            <?php endforeach; ?>
        </fieldset>
        <?php break;

    case 'bool': ?>
        <label class="check check--single">
            <input type="checkbox" id="<?= e($id) ?>" name="<?= e($name) ?>" value="1" <?= $value ? 'checked' : '' ?><?= $aria ?>>
            <span><?= e($field['label']) ?> <?= $badge ?></span>
        </label>
        <?php break;

    default: /* text, url */ ?>
        <label for="<?= e($id) ?>"><?= e($field['label']) ?><?= $req ?> <?= $badge ?></label>
        <input id="<?= e($id) ?>" name="<?= e($name) ?>" value="<?= e($scalar) ?>" <?= $field['type'] === 'url' ? 'type="url" placeholder="https://"' : 'maxlength="1000"' ?><?= $aria ?>>
<?php endswitch; ?>
    <?php if ($field['help']): ?><p class="hint" id="<?= e($id) ?>-help"><?= e($field['help']) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="field-error" id="<?= e($id) ?>-error"><?= e($error) ?></p><?php endif; ?>
</div>
