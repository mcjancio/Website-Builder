<?php
/** @var array $u @var array $listings @var array $consents @var array $events @var bool $isSelf */
use App\Core\View;
use App\Listings\Display;

$types = array_column((array) setting('account_types', []), 'label', 'key');
?>
<section class="container section admin">
    <?= View::partial('admin/partials/nav', ['active' => 'users']) ?>
    <p class="breadcrumbs"><a href="<?= e(url('/admin/uzytkownicy')) ?>">← <?= e(__('admin.users')) ?></a></p>
    <h1><?= e($u['first_name'] . ' ' . $u['last_name']) ?>
        <?php if ($u['status'] !== 'active'): ?><span class="status status--rejected"><?= e(__('user_status.' . $u['status'])) ?></span><?php endif; ?>
    </h1>

    <div class="moderation">
        <div class="moderation__main">
            <dl class="data-list">
                <dt><?= e(__('register.email')) ?></dt>
                <dd><?= e($u['email']) ?> <?= $u['email_verified_at'] ? '<span class="badge badge--ok">' . e(__('account.verified')) . '</span>' : '<span class="badge">' . e(__('account.unverified')) . '</span>' ?></dd>
                <dt><?= e(__('register.phone')) ?></dt>
                <dd><?= e($u['phone_e164']) ?> <?= $u['phone_verified_at'] ? '<span class="badge badge--ok">' . e(__('account.verified')) . '</span>' : '' ?></dd>
                <?php if ($u['account_type']): ?>
                    <dt><?= e(__('register.account_type')) ?></dt><dd><?= e($types[$u['account_type']] ?? $u['account_type']) ?></dd>
                <?php endif; ?>
                <?php if ($u['company']): ?>
                    <dt><?= e(__('register.company_name')) ?></dt>
                    <dd><?= e($u['company']) ?> <span class="muted">NIP <?= e($u['nip']) ?><?= $u['company_city'] ? ' · ' . e($u['company_city']) : '' ?> · <?= e(__('admin.company_source_' . ($u['company_source'] === 'manual' ? 'manual' : 'registry'))) ?></span></dd>
                <?php endif; ?>
                <dt><?= e(__('admin.col_role')) ?></dt><dd><?= e(__('role.' . $u['role'])) ?></dd>
                <dt><?= e(__('twofa.short')) ?></dt><dd><?= e($u['totp_enabled_at'] ? __('twofa.status_on') : __('twofa.status_off')) ?></dd>
                <dt><?= e(__('admin.col_registered')) ?></dt><dd><?= e(Display::datetime($u['created_at'])) ?></dd>
                <dt><?= e(__('admin.col_last_login')) ?></dt><dd><?= e(Display::datetime($u['last_login_at'])) ?></dd>
            </dl>

            <h2 class="h3"><?= e(__('term.listings')) ?></h2>
            <?php if ($listings === []): ?>
                <p class="empty"><?= e(__('admin.none')) ?></p>
            <?php else: ?>
                <ul class="plain-list">
                    <?php foreach ($listings as $l): ?>
                        <li><a href="<?= e(url('/admin/oferty', ['q' => (string) $l['id']])) ?>"><?= e($l['title']) ?></a> <span class="status status--<?= e($l['status']) ?>"><?= e(__('status.' . $l['status'])) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <h2 class="h3"><?= e(__('admin.consents')) ?></h2>
            <?php if ($consents === []): ?>
                <p class="empty"><?= e(__('admin.none')) ?></p>
            <?php else: ?>
                <ul class="plain-list">
                    <?php foreach ($consents as $c): ?>
                        <li><?= e($c['type']) ?><?= $c['page_version'] ? ' (v' . (int) $c['page_version'] . ')' : '' ?>: <strong><?= e($c['granted'] ? __('admin.yes') : __('admin.no')) ?></strong> <span class="muted"><?= e(Display::datetime($c['created_at'])) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <h2 class="h3"><?= e(__('admin.events')) ?></h2>
            <?php if ($events === []): ?>
                <p class="empty"><?= e(__('admin.none')) ?></p>
            <?php else: ?>
                <ul class="plain-list">
                    <?php foreach ($events as $ev): ?>
                        <li><code><?= e($ev['action']) ?></code> <span class="muted"><?= e(Display::datetime($ev['created_at'])) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <aside class="moderation__aside">
            <?php if ($isSelf): ?>
                <p class="notice"><?= e(__('admin.not_on_self')) ?></p>
            <?php elseif ($u['status'] !== 'deleted'): ?>
                <section class="admin-box" aria-labelledby="akcje-h">
                    <h2 id="akcje-h" class="h3"><?= e(__('admin.col_actions')) ?></h2>
                    <form method="post" action="<?= e(url('/admin/uzytkownicy/' . $u['id'])) ?>" class="form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="role">
                        <div class="field">
                            <label for="role"><?= e(__('admin.col_role')) ?></label>
                            <select id="role" name="role">
                                <?php foreach (['member', 'moderator', 'admin'] as $r): ?>
                                    <option value="<?= e($r) ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= e(__('role.' . $r)) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="hint"><?= e(__('admin.role_hint')) ?></p>
                        </div>
                        <button type="submit" class="button button--secondary button--block"><?= e(__('admin.change_role')) ?></button>
                    </form>
                    <form method="post" action="<?= e(url('/admin/uzytkownicy/' . $u['id'])) ?>" class="form" data-confirm="<?= e(__($u['status'] === 'blocked' ? 'admin.confirm_unblock' : 'admin.confirm_block')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="<?= $u['status'] === 'blocked' ? 'unblock' : 'block' ?>">
                        <button type="submit" class="button button--secondary button--block"><?= e(__($u['status'] === 'blocked' ? 'admin.unblock' : 'admin.block')) ?></button>
                    </form>
                    <?php if ($u['totp_enabled_at']): ?>
                        <form method="post" action="<?= e(url('/admin/uzytkownicy/' . $u['id'])) ?>" class="form" data-confirm="<?= e(__('admin.confirm_reset2fa')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="reset2fa">
                            <button type="submit" class="link-button link-button--danger"><?= e(__('admin.reset2fa')) ?></button>
                        </form>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </aside>
    </div>
</section>
