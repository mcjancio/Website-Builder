<?php
/** @var array $row @var array $staff */
use App\Core\View;
use App\Listings\Display;

$utm = $row['utm'] ? (json_decode((string) $row['utm'], true) ?: []) : [];
?>
<section class="container section admin">
    <?= View::partial('admin/partials/nav', ['active' => 'inquiries']) ?>
    <p class="breadcrumbs"><a href="<?= e(url('/admin/zapytania')) ?>">← <?= e(__('admin.inquiries')) ?></a></p>
    <h1><?= e(__('admin.inquiry')) ?> #<?= (int) $row['id'] ?></h1>

    <div class="moderation">
        <div class="moderation__main">
            <dl class="data-list">
                <dt><?= e(__('inquiry.name')) ?></dt><dd><?= e($row['name']) ?></dd>
                <dt><?= e(__('register.email')) ?></dt><dd><a href="mailto:<?= e($row['email']) ?>"><?= e($row['email']) ?></a></dd>
                <?php if ($row['phone_e164']): ?>
                    <dt><?= e(__('inquiry.phone')) ?></dt><dd><a href="tel:<?= e($row['phone_e164']) ?>"><?= e($row['phone_e164']) ?></a></dd>
                <?php endif; ?>
                <dt><?= e(__('term.listing')) ?></dt>
                <dd><?php if ($row['listing_slug']): ?><a href="<?= e(url('/oferta/' . $row['listing_slug'])) ?>"><?= e($row['listing_title']) ?></a><?php else: ?><?= e(__('admin.general_contact')) ?><?php endif; ?></dd>
                <dt><?= e(__('admin.col_date')) ?></dt><dd><?= e(Display::datetime($row['created_at'])) ?></dd>
                <dt><?= e(__('admin.account')) ?></dt><dd><?= e($row['account_email'] ?? __('admin.guest')) ?></dd>
                <?php if ($utm !== []): ?>
                    <dt><?= e(__('admin.source')) ?></dt><dd><?= e(implode(' · ', array_map(static fn($k, $v) => $k . ': ' . (is_scalar($v) ? $v : ''), array_keys($utm), $utm))) ?></dd>
                <?php endif; ?>
            </dl>
            <h2 class="h3"><?= e(__('inquiry.message')) ?></h2>
            <div class="message-box"><?= nl2br(e($row['message'])) ?></div>
        </div>

        <aside class="moderation__aside">
            <section class="admin-box" aria-labelledby="obsluga-h">
                <h2 id="obsluga-h" class="h3"><?= e(__('admin.handling')) ?></h2>
                <form method="post" action="<?= e(url('/admin/zapytania/' . $row['id'])) ?>" class="form">
                    <?= csrf_field() ?>
                    <div class="field">
                        <label for="status"><?= e(__('admin.col_status')) ?></label>
                        <select id="status" name="status">
                            <?php foreach (\App\Http\Controllers\Admin\InquiriesController::STATUSES as $s): ?>
                                <option value="<?= e($s) ?>" <?= $row['status'] === $s ? 'selected' : '' ?>><?= e(__('inquiry_status.' . $s)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="assigned_to"><?= e(__('admin.col_assignee')) ?></label>
                        <select id="assigned_to" name="assigned_to">
                            <option value="">—</option>
                            <?php foreach ($staff as $s): ?>
                                <option value="<?= (int) $s['id'] ?>" <?= (int) $row['assigned_to'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['first_name'] . ' ' . $s['last_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="admin_note"><?= e(__('admin.note')) ?></label>
                        <textarea id="admin_note" name="admin_note" rows="5" maxlength="5000"><?= e($row['admin_note'] ?? '') ?></textarea>
                        <p class="hint"><?= e(__('admin.note_hint')) ?></p>
                    </div>
                    <button type="submit" class="button button--block"><?= e(__('form.save')) ?></button>
                </form>
            </section>
        </aside>
    </div>
</section>
