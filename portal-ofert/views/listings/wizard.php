<?php
/**
 * @var ?array $listing
 * @var array $state       core, values, category_id, revision, attachments_meta
 * @var int $step
 * @var array $errors
 * @var array $old         POST po błędzie walidacji
 * @var array $fields
 * @var array $categories
 * @var array $images
 * @var array $attachments
 * @var bool $isLive
 * @var array $missing
 * @var array $limits
 * @var array $accessLevels
 * @var array $attachmentTypes
 * @var ?array $ai          asystent AI: extract, rewrite (włączone?), review (pola do sprawdzenia), ostatnie zlecenia
 */
use App\Core\Str;

$core = $state['core'];
$val = static fn(string $k) => $old[$k] ?? $core[$k] ?? '';
$base = $listing ? url('/moje-oferty/' . $listing['id']) : '';
$action = $listing ? $base . '/edycja/' . $step : url('/dodaj-oferte');
$stepNames = [1 => __('wizard.step1'), 2 => __('wizard.step2'), 3 => __('wizard.step3'), 4 => __('wizard.step4')];
$titleMax = (int) ($limits['title_max'] ?? 120);
$shortMax = (int) ($limits['short_desc_max'] ?? 300);
$levelName = static fn(int $l) => $accessLevels[$l]['name'] ?? __('access.level_' . $l);
?>
<section class="container section wizard">
    <p class="wizard__back"><a href="<?= e(url('/moje-oferty')) ?>">← <?= e(__('mine.title')) ?></a></p>
    <h1><?= e($listing ? $core['title'] : __('term.listing_add')) ?></h1>

    <?php if ($isLive): ?>
        <p class="notice"><?= e(__($state['revision'] && $state['revision']['status'] === 'pending' ? 'wizard.live_pending' : 'wizard.live_info')) ?></p>
    <?php elseif ($listing && $listing['status'] === 'pending'): ?>
        <p class="notice"><?= e(__('wizard.pending_info')) ?></p>
    <?php elseif ($listing && $listing['status'] === 'rejected'): ?>
        <div class="notice notice--warning"><p><strong><?= e(__('wizard.rejected_info')) ?></strong></p><?php if ($listing['moderation_note']): ?><p><?= e($listing['moderation_note']) ?></p><?php endif; ?></div>
    <?php endif; ?>

    <nav class="steps-nav" aria-label="<?= e(__('wizard.steps_label')) ?>">
        <ol>
            <?php foreach ($stepNames as $n => $label): ?>
                <li class="<?= $n === $step ? 'is-current' : ($n < $step ? 'is-done' : '') ?>">
                    <?php if ($listing && $n !== $step): ?>
                        <a href="<?= e($base . '/edycja/' . $n) ?>"><span class="steps-nav__n"><?= $n ?></span> <?= e($label) ?></a>
                    <?php else: ?>
                        <span <?= $n === $step ? 'aria-current="step"' : '' ?>><span class="steps-nav__n"><?= $n ?></span> <?= e($label) ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    </nav>

    <?php if ($errors !== []): ?>
        <div class="form-summary" role="alert" tabindex="-1" data-focus><p><?= e(__('form.fix_errors', ['count' => count($errors)])) ?></p></div>
    <?php endif; ?>

    <?php if ($listing && $ai && (($step === 2 && $ai['extract']) || ($step === 3 && $ai['rewrite']))): ?>
        <?= \App\Core\View::partial('listings/partials/ai-box', ['listing' => $listing, 'step' => $step, 'ai' => $ai, 'attachments' => $attachments]) ?>
    <?php endif; ?>
    <?php if ($listing && $ai && $ai['review'] !== [] && in_array($step, [2, 3], true)): ?>
        <p class="notice notice--ai" role="status"><?= e(__('ai.review_notice')) ?></p>
    <?php endif; ?>

    <form method="post" action="<?= e($action) ?>" class="form" novalidate <?= $listing ? 'data-autosave' : '' ?> data-loading-form>
        <?= csrf_field() ?>

    <?php if ($step === 1): ?>
        <?php if (!$isLive): ?>
            <fieldset class="field">
                <legend><?= e(__('term.categories')) ?> <span class="req" aria-hidden="true">*</span></legend>
                <div class="choice-cards">
                    <?php $current = (string) ($old['category_id'] ?? $state['category_id'] ?? ''); ?>
                    <?php foreach ($categories as $c): ?>
                        <label class="choice-card<?= $c['parent_id'] ? ' choice-card--child' : '' ?>">
                            <input type="radio" name="category_id" value="<?= (int) $c['id'] ?>" <?= $current === (string) $c['id'] ? 'checked' : '' ?> required>
                            <span class="choice-card__label"><?= e($c['name']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <?= field_error($errors, 'category_id') ?>
                <?php if ($listing): ?><p class="hint"><?= e(__('wizard.category_hint')) ?></p><?php endif; ?>
            </fieldset>
        <?php endif; ?>

        <div class="field">
            <label for="title"><?= e(__('listing.title')) ?> <span class="req" aria-hidden="true">*</span> <span class="level-badge level-badge--1"><?= e($levelName(1)) ?></span></label>
            <input id="title" name="title" value="<?= e($val('title')) ?>" required maxlength="<?= $titleMax ?>" data-counter<?= field_aria($errors, 'title') ?>>
            <?= field_error($errors, 'title') ?>
        </div>
        <div class="field">
            <label for="short_desc"><?= e(__('listing.short_desc_with_max', ['max' => $shortMax])) ?> <span class="req" aria-hidden="true">*</span> <span class="level-badge level-badge--1"><?= e($levelName(1)) ?></span></label>
            <textarea id="short_desc" name="short_desc" rows="4" maxlength="<?= $shortMax ?>" data-counter
                      aria-describedby="short_desc-hint<?= isset($errors['short_desc']) ? ' short_desc-error' : '' ?>"><?= e($val('short_desc')) ?></textarea>
            <p class="hint" id="short_desc-hint"><?= e(__('listing.short_desc_hint')) ?></p>
            <?= field_error($errors, 'short_desc') ?>
        </div>

    <?php elseif ($step === 2): ?>
        <p class="hint"><?= e(__('wizard.fields_hint')) ?></p>
        <?php foreach (\App\Listings\Fields::grouped($fields) as $group => $groupFields): ?>
            <fieldset class="field-group">
                <legend><?= e($group) ?></legend>
                <?php foreach ($groupFields as $key => $f): ?>
                    <?= \App\Core\View::partial('listings/partials/field', [
                        'field' => $f,
                        'value' => array_key_exists('f', $old) ? ($old['f'][$key] ?? null) : ($state['values'][$key] ?? null),
                        'error' => $errors[$key] ?? null,
                        'accessLevels' => $accessLevels,
                        'aiReview' => $ai !== null && in_array($key, $ai['review'], true),
                    ]) ?>
                <?php endforeach; ?>
            </fieldset>
        <?php endforeach; ?>

    <?php elseif ($step === 3): ?>
        <div class="field">
            <label for="full_desc_md"><?= e(__('listing.full_desc')) ?> <span class="level-badge level-badge--2"><?= e($levelName(2)) ?></span><?php if ($ai !== null && in_array('full_desc_md', $ai['review'], true)): ?> <span class="ai-badge"><?= e(__('ai.badge')) ?></span><?php endif; ?></label>
            <textarea id="full_desc_md" name="full_desc_md" rows="12" maxlength="20000" data-md-preview="<?= e(url('/podglad-markdown')) ?>" data-md-show="<?= e(__('wizard.md_preview')) ?>" data-md-hide="<?= e(__('wizard.md_edit')) ?>"
                      aria-describedby="full_desc-hint"><?= e($val('full_desc_md')) ?></textarea>
            <p class="hint" id="full_desc-hint"><?= e(__('listing.markdown_hint')) ?></p>
        </div>
        <div class="field">
            <label for="confidential_md"><?= e(__('listing.confidential')) ?> <span class="level-badge level-badge--3"><?= e($levelName(3)) ?></span></label>
            <textarea id="confidential_md" name="confidential_md" rows="6" maxlength="20000" aria-describedby="confidential-hint"><?= e($val('confidential_md')) ?></textarea>
            <p class="hint" id="confidential-hint"><?= e(__('listing.confidential_hint')) ?></p>
        </div>

    <?php elseif ($step === 4): ?>
        <?php if ($attachments !== []): ?>
            <h2 id="dokumenty" class="h3"><?= e(__('wizard.documents_title')) ?></h2>
            <ul class="file-list">
                <?php foreach ($attachments as $i => $a):
                    $meta = $state['attachments_meta'][$a['id']] ?? ['label' => $a['label'], 'access_level' => (int) $a['access_level']]; ?>
                    <li class="file-item<?= $a['state'] !== 'live' ? ' is-' . e($a['state']) : '' ?>">
                        <div class="file-item__head">
                            <a href="<?= e(url('/plik/' . $a['id'])) ?>" target="_blank" rel="noopener"><?= e($a['original_name']) ?></a>
                            <span class="muted"><?= e(Str::bytes((int) $a['size_bytes'])) ?></span>
                            <?php if ($a['state'] !== 'live'): ?><span class="badge"><?= e(__('media.state_' . $a['state'])) ?></span><?php endif; ?>
                        </div>
                        <div class="field-row">
                            <div class="field">
                                <label for="att-<?= (int) $a['id'] ?>-label"><?= e(__('wizard.doc_label')) ?></label>
                                <input id="att-<?= (int) $a['id'] ?>-label" name="att[<?= (int) $a['id'] ?>][label]" value="<?= e($meta['label']) ?>" maxlength="200">
                            </div>
                            <div class="field">
                                <label for="att-<?= (int) $a['id'] ?>-level"><?= e(__('wizard.doc_level')) ?></label>
                                <select id="att-<?= (int) $a['id'] ?>-level" name="att[<?= (int) $a['id'] ?>][access_level]">
                                    <?php foreach ([1, 2, 3] as $l): ?>
                                        <option value="<?= $l ?>" <?= (int) $meta['access_level'] === $l ? 'selected' : '' ?>><?= e($levelName($l)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    <?php endif; ?>

        <div class="wizard__actions">
            <?php if ($listing && $step > 1): ?>
                <button type="submit" name="go" value="prev" class="button button--secondary">← <?= e(__('wizard.prev')) ?></button>
            <?php endif; ?>
            <?php if ($step < 4): ?>
                <button type="submit" name="go" value="next" class="button" data-loading-text="<?= e(__('form.saving')) ?>"><?= e(__($listing ? 'wizard.save_next' : 'wizard.create')) ?> →</button>
            <?php elseif ($attachments !== []): ?>
                <button type="submit" name="go" value="stay" class="button button--secondary"><?= e(__('wizard.save')) ?></button>
            <?php endif; ?>
            <span class="autosave-status muted" aria-live="polite" data-autosave-status></span>
        </div>
    </form>

    <?php if ($listing && $step === 3): ?>
        <section class="upload-section" aria-labelledby="zdjecia">
            <h2 id="zdjecia" class="h3"><?= e(__('wizard.images_title')) ?></h2>
            <p class="hint"><?= e(__('wizard.images_hint', ['max' => (int) ($limits['images_max'] ?? 20), 'mb' => (int) ($limits['image_max_mb'] ?? 10)])) ?></p>
            <?php if ($images !== []): ?>
                <ul class="media-grid">
                    <?php foreach ($images as $i => $m): ?>
                        <li class="media-item<?= $m['state'] !== 'live' ? ' is-' . e($m['state']) : '' ?><?= (int) $m['is_cover'] ? ' is-cover' : '' ?>">
                            <img src="<?= e(url('/media/' . $m['id'] . '/400')) ?>" alt="<?= e($m['alt'] ?? '') ?>" width="200" height="<?= (int) round(200 * ((int) $m['height'] ?: 3) / ((int) $m['width'] ?: 4)) ?>" loading="lazy">
                            <?php if ((int) $m['is_cover']): ?><span class="badge badge--ok"><?= e(__('media.cover')) ?></span><?php endif; ?>
                            <?php if ($m['state'] !== 'live'): ?><span class="badge"><?= e(__('media.state_' . $m['state'])) ?></span><?php endif; ?>
                            <form method="post" action="<?= e($base . '/zdjecia/' . $m['id']) ?>" class="media-item__actions">
                                <?= csrf_field() ?>
                                <?php if (!(int) $m['is_cover']): ?><button name="action" value="okladka" class="link-button"><?= e(__('media.set_cover')) ?></button><?php endif; ?>
                                <?php if ($i > 0): ?><button name="action" value="w-gore" class="link-button" aria-label="<?= e(__('media.move_up')) ?>">←</button><?php endif; ?>
                                <?php if ($i < count($images) - 1): ?><button name="action" value="w-dol" class="link-button" aria-label="<?= e(__('media.move_down')) ?>">→</button><?php endif; ?>
                                <button name="action" value="usun" class="link-button link-button--danger"><?= e(__($m['state'] === 'pending_remove' ? 'media.undo_remove' : 'media.remove')) ?></button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <form method="post" action="<?= e($base . '/zdjecia') ?>" enctype="multipart/form-data" class="dropzone" data-upload data-text-progress="<?= e(__('upload.progress')) ?>" data-text-done="<?= e(__('upload.done')) ?>">
                <?= csrf_field() ?>
                <label for="images" class="dropzone__label"><?= e(__('wizard.images_pick')) ?></label>
                <input id="images" name="images[]" type="file" accept="image/jpeg,image/png,image/webp" multiple>
                <button type="submit" class="button button--secondary"><?= e(__('wizard.upload')) ?></button>
                <progress class="dropzone__progress" max="100" value="0" hidden></progress>
                <p class="dropzone__status" aria-live="polite"></p>
            </form>
        </section>
    <?php endif; ?>

    <?php if ($listing && $step === 4): ?>
        <section class="upload-section" aria-labelledby="dodaj-dokumenty">
            <h2 id="dodaj-dokumenty" class="h3"><?= e(__('wizard.documents_add')) ?></h2>
            <p class="hint"><?= e(__('wizard.documents_hint', ['max' => (int) ($limits['attachments_max'] ?? 25), 'mb' => (int) ($limits['attachment_max_mb'] ?? 25), 'types' => strtoupper(implode(', ', array_unique($attachmentTypes)))])) ?></p>
            <form method="post" action="<?= e($base . '/dokumenty') ?>" enctype="multipart/form-data" class="dropzone" data-upload data-text-progress="<?= e(__('upload.progress')) ?>" data-text-done="<?= e(__('upload.done')) ?>">
                <?= csrf_field() ?>
                <div class="field-row">
                    <div class="field">
                        <label for="doc-label"><?= e(__('wizard.doc_label')) ?> <span class="muted">(<?= e(__('form.optional')) ?>)</span></label>
                        <input id="doc-label" name="label" maxlength="200" placeholder="<?= e(__('wizard.doc_label_placeholder')) ?>">
                    </div>
                    <div class="field">
                        <label for="doc-level"><?= e(__('wizard.doc_level')) ?></label>
                        <select id="doc-level" name="access_level">
                            <?php foreach ([3, 2, 1] as $l): ?>
                                <option value="<?= $l ?>" <?= $l === (int) ($limits['attachment_default_level'] ?? 3) ? 'selected' : '' ?>><?= e($levelName($l)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <label for="files" class="dropzone__label"><?= e(__('wizard.documents_pick')) ?></label>
                <input id="files" name="files[]" type="file" accept="<?= e(implode(',', array_map(static fn($ext) => '.' . $ext, array_unique($attachmentTypes)))) ?>" multiple>
                <button type="submit" class="button button--secondary"><?= e(__('wizard.upload')) ?></button>
                <progress class="dropzone__progress" max="100" value="0" hidden></progress>
                <p class="dropzone__status" aria-live="polite"></p>
            </form>
            <?php if ($attachments !== []): ?>
                <ul class="file-actions">
                    <?php foreach ($attachments as $i => $a): ?>
                        <li>
                            <form method="post" action="<?= e($base . '/dokumenty/' . $a['id']) ?>" class="inline-form">
                                <?= csrf_field() ?>
                                <span><?= e($a['original_name']) ?></span>
                                <?php if ($i > 0): ?><button name="action" value="w-gore" class="link-button" aria-label="<?= e(__('media.move_up')) ?>">↑</button><?php endif; ?>
                                <?php if ($i < count($attachments) - 1): ?><button name="action" value="w-dol" class="link-button" aria-label="<?= e(__('media.move_down')) ?>">↓</button><?php endif; ?>
                                <button name="action" value="usun" class="link-button link-button--danger"><?= e(__($a['state'] === 'pending_remove' ? 'media.undo_remove' : 'media.remove')) ?></button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="submit-box" aria-labelledby="wyslij">
            <h2 id="wyslij" class="h3"><?= e(__($isLive ? 'wizard.submit_revision_title' : 'wizard.submit_title')) ?></h2>
            <?php if ($missing !== []): ?>
                <p><?= e(__('wizard.missing_list')) ?></p>
                <ul class="missing-list">
                    <?php foreach ($missing as $key => $label):
                        $fixStep = in_array($key, ['title', 'short_desc'], true) ? 1 : 2; ?>
                        <li><a href="<?= e($base . '/edycja/' . $fixStep) ?>#<?= e(in_array($key, ['title', 'short_desc'], true) ? $key : 'f-' . $key) ?>"><?= e($label) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p><?= e(__($isLive ? 'wizard.submit_revision_text' : 'wizard.submit_text')) ?></p>
            <?php endif; ?>
            <form method="post" action="<?= e($base . '/wyslij') ?>">
                <?= csrf_field() ?>
                <button type="submit" class="button" <?= $missing !== [] ? 'aria-disabled="true"' : '' ?>><?= e(__('wizard.submit')) ?></button>
                <a class="button button--secondary" href="<?= e($base . '/podglad') ?>"><?= e(__('wizard.preview')) ?></a>
            </form>
        </section>
    <?php endif; ?>
</section>
