<?php
/**
 * Asystent AI w kreatorze: krok 2 — „wypełnij z PDF”, krok 3 — „popraw opis”.
 * Osobne formularze (poza głównym formularzem kroku). Wynik przychodzi w tle — JS odpytuje stan i odświeża stronę.
 * @var array $listing @var int $step @var array $ai @var array $attachments
 */
use App\Core\Str;

$base = url('/moje-oferty/' . $listing['id']);
$task = $step === 2 ? $ai['lastExtract'] : $ai['lastRewrite'];
$pending = $task !== null && in_array($task['status'], ['queued', 'running'], true);
$pdfs = array_values(array_filter($attachments, static fn($a) => $a['mime'] === 'application/pdf'));
?>
<section class="ai-box" id="asystent" aria-labelledby="ai-h">
    <h2 id="ai-h" class="h3"><span aria-hidden="true">✦</span> <?= e(__($step === 2 ? 'ai.extract_title' : 'ai.rewrite_title')) ?></h2>

    <?php if ($pending): ?>
        <p class="ai-box__status" role="status" data-ai-poll="<?= e($base . '/ai/' . $task['id']) ?>"><span class="spinner" aria-hidden="true"></span> <?= e(__('ai.working')) ?></p>
        <p class="hint"><a href="<?= e($base . '/edycja/' . $step . '#asystent') ?>"><?= e(__('ai.refresh')) ?></a></p>
    <?php else: ?>
        <?php if ($task !== null && $task['status'] === 'failed'): ?>
            <p class="notice notice--warning"><?= e($task['error'] ?: __('ai.err_api')) ?></p>
        <?php endif; ?>

        <?php if ($step === 2): ?>
            <?php if ($task !== null && $task['status'] === 'applied' && is_array($task['result'])): ?>
                <div class="notice">
                    <p><strong><?= e(__('ai.extract_done', ['count' => count($ai['review'])])) ?></strong></p>
                    <?php if (!empty($task['result']['notes'])): ?><p><?= e((string) $task['result']['notes']) ?></p><?php endif; ?>
                    <?php if (!empty($task['result']['short_desc'])): ?>
                        <p class="hint"><?= e(__('ai.short_proposal')) ?> „<?= e((string) $task['result']['short_desc']) ?>”</p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <p class="hint"><?= e(__('ai.extract_help')) ?></p>
            <?php endif; ?>
            <form method="post" action="<?= e($base . '/ai/z-pdf') ?>" enctype="multipart/form-data" class="ai-box__form" data-loading-form>
                <?= csrf_field() ?>
                <?php if ($pdfs !== []): ?>
                    <div class="field">
                        <label for="ai-att"><?= e(__('ai.pick_pdf')) ?></label>
                        <select id="ai-att" name="attachment_id">
                            <option value=""><?= e(__('ai.new_pdf')) ?></option>
                            <?php foreach ($pdfs as $p): ?>
                                <option value="<?= (int) $p['id'] ?>"><?= e(($p['label'] ?: $p['original_name']) . ' (' . Str::bytes((int) $p['size_bytes']) . ')') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="field">
                    <label for="ai-pdf"><?= e(__('ai.upload_pdf')) ?></label>
                    <input id="ai-pdf" name="pdf" type="file" accept="application/pdf,.pdf">
                </div>
                <button type="submit" class="button button--secondary" data-loading-text="<?= e(__('form.sending')) ?>"><?= e(__('ai.extract_button')) ?></button>
                <p class="hint"><?= e(__('ai.disclaimer')) ?></p>
            </form>
        <?php else: ?>
            <?php if ($task !== null && $task['status'] === 'done' && is_array($task['result'])): ?>
                <div class="ai-proposal">
                    <h3 class="h3"><?= e(__('ai.proposal')) ?></h3>
                    <p><strong><?= e(__('listing.short_desc')) ?>:</strong> <?= e((string) $task['result']['short_desc']) ?> <span class="muted">(<?= mb_strlen((string) $task['result']['short_desc']) ?>)</span></p>
                    <div class="prose ai-proposal__text"><?= \App\Core\Markdown::toHtml((string) $task['result']['full_desc_md']) ?></div>
                    <div class="row-actions">
                        <?php foreach (['all' => 'ai.use_all', 'full' => 'ai.use_full', 'short' => 'ai.use_short'] as $part => $label): ?>
                            <form method="post" action="<?= e($base . '/ai/' . $task['id'] . '/zastosuj') ?>" class="inline-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="part" value="<?= e($part) ?>">
                                <button type="submit" class="button button--small<?= $part === 'all' ? '' : ' button--secondary' ?>"><?= e(__($label)) ?></button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <p class="hint"><?= e(__('ai.rewrite_help')) ?></p>
            <?php endif; ?>
            <form method="post" action="<?= e($base . '/ai/opis') ?>" class="ai-box__form" data-loading-form>
                <?= csrf_field() ?>
                <div class="field">
                    <label for="ai-notes"><?= e(__('ai.notes')) ?></label>
                    <textarea id="ai-notes" name="notes" rows="5" maxlength="20000" minlength="30" required><?= e((string) ($task['input'] ?? '')) ?></textarea>
                </div>
                <button type="submit" class="button button--secondary" data-loading-text="<?= e(__('form.sending')) ?>"><?= e(__('ai.rewrite_button')) ?></button>
                <p class="hint"><?= e(__('ai.disclaimer')) ?></p>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</section>
