<?php
/**
 * Strona oferty. Treść filtrowana WYŁĄCZNIE w kontrolerze przez Gate (tu tylko prezentacja).
 * @var array $listing
 * @var int $level          poziom dostępu użytkownika do tej oferty (0–3)
 * @var array $values
 * @var array $groups       grupa => pola widoczne dla użytkownika
 * @var int $hiddenCount    ile wypełnionych pól jest ukrytych
 * @var array $videos
 * @var array $images
 * @var array $attachments
 * @var ?string $fullDesc
 * @var bool $hasFullDesc
 * @var ?string $confidential
 * @var bool $hasConfidential
 * @var ?array $category
 * @var ?array $user
 * @var bool $isOwner
 * @var ?string $accessStatus
 * @var array $errors
 * @var array $old
 */
use App\Core\Str;
use App\Listings\Display;

$here = url('/oferta/' . $listing['slug']);
$loginUrl = url('/logowanie', ['next' => '/oferta/' . $listing['slug']]);
$registerUrl = url('/rejestracja');
$levels = (array) setting('access_levels', []);
$levelName = static fn(int $l) => $levels[$l]['name'] ?? __('access.level_' . $l);
$lockedNotice = (string) setting('portal.locked_content_notice', __('access.locked_default'));
$lockedHtml = str_replace(
    ['{login}', '{register}'],
    ['<a href="' . e($loginUrl) . '">' . e(__('nav.login')) . '</a>', '<a href="' . e($registerUrl) . '">' . e(__('nav.register')) . '</a>'],
    e($lockedNotice)
);
$visibleDocs = array_values(array_filter($attachments, static fn($a) => (int) $a['access_level'] <= $level));
$lockedDocs = count($attachments) - count($visibleDocs);
?>
<article class="listing">
    <div class="container">
        <nav class="breadcrumbs" aria-label="<?= e(__('listing.breadcrumbs')) ?>">
            <ol>
                <li><a href="<?= e(url('/oferty')) ?>"><?= e(__('term.listings')) ?></a></li>
                <?php if ($category['parent_slug']): ?><li><a href="<?= e(url('/oferty', ['kategoria' => $category['parent_slug']])) ?>"><?= e($category['parent_name']) ?></a></li><?php endif; ?>
                <li><a href="<?= e(url('/oferty', ['kategoria' => $category['slug']])) ?>"><?= e($category['name']) ?></a></li>
            </ol>
        </nav>

        <?php if ($listing['status'] !== 'published'): ?>
            <p class="notice notice--warning"><?= e(__('listing.not_public', ['status' => __('status.' . $listing['status'])])) ?></p>
        <?php endif; ?>
        <?php if ($isOwner): ?>
            <p class="notice"><?= e(__('listing.you_are_owner')) ?> <a href="<?= e(url('/moje-oferty/' . $listing['id'] . '/edycja')) ?>"><?= e(__('mine.edit')) ?></a></p>
        <?php endif; ?>

        <header class="listing__head">
            <h1><?= e($listing['title']) ?></h1>
            <p class="listing__meta"><?= e($category['name']) ?><?= $listing['city'] ? ' · ' . e($listing['city']) : '' ?></p>
        </header>
    </div>

    <?php if ($images !== []): ?>
        <div class="container">
            <div class="gallery" data-gallery>
                <?php foreach ($images as $i => $m): ?>
                    <a class="gallery__item<?= $i === 0 ? ' gallery__item--main' : '' ?>" href="<?= e(url('/media/' . $m['id'] . '/1600')) ?>" target="_blank" rel="noopener">
                        <img src="<?= e(url('/media/' . $m['id'] . ($i === 0 ? '/960' : '/400'))) ?>"
                             <?= $i === 0 ? 'srcset="' . e(url('/media/' . $m['id'] . '/960')) . ' 960w, ' . e(url('/media/' . $m['id'] . '/1600')) . ' 1600w" sizes="(max-width: 60rem) 100vw, 60rem" fetchpriority="high"' : 'loading="lazy"' ?>
                             alt="<?= e($m['alt'] ?: $listing['title']) ?>" width="<?= $i === 0 ? 960 : 400 ?>"
                             height="<?= (int) round(($i === 0 ? 960 : 400) * ((int) $m['height'] ?: 2) / ((int) $m['width'] ?: 3)) ?>" decoding="async">
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="container listing__layout">
        <div class="listing__main">
            <p class="lead"><?= e($listing['short_desc']) ?></p>

            <?php if ($level < 2 && ($hiddenCount > 0 || $hasFullDesc)): ?>
                <div class="locked" role="note">
                    <p class="locked__title"><?= e($hiddenCount > 0 ? __('access.more_for_members', ['count' => $hiddenCount]) : __('access.desc_for_members')) ?></p>
                    <p><?= $lockedHtml ?></p>
                </div>
            <?php endif; ?>

            <?php foreach ($groups as $group => $fields): ?>
                <section class="facts" aria-labelledby="g-<?= e(md5($group)) ?>">
                    <h2 id="g-<?= e(md5($group)) ?>" class="h3"><?= e($group) ?></h2>
                    <dl class="data-list">
                        <?php foreach ($fields as $key => $f): ?>
                            <dt><?= e($f['label']) ?></dt>
                            <dd>
                                <?php if ($f['type'] === 'url'): ?>
                                    <a href="<?= e($values[$key]) ?>" rel="noopener nofollow ugc" target="_blank"><?= e($values[$key]) ?></a>
                                <?php else: ?>
                                    <?= e(Display::value($f, $values[$key])) ?>
                                <?php endif; ?>
                            </dd>
                        <?php endforeach; ?>
                    </dl>
                </section>
            <?php endforeach; ?>

            <?php if ($fullDesc !== null && trim(strip_tags($fullDesc)) !== ''): ?>
                <section class="prose listing__desc">
                    <h2 class="h3"><?= e(__('listing.full_desc')) ?></h2>
                    <?= $fullDesc ?>
                </section>
            <?php endif; ?>

            <?php if ($videos !== []): ?>
                <section class="videos">
                    <h2 class="h3"><?= e($videos[0]['label']) ?></h2>
                    <?php foreach ($videos as $v): ?>
                        <?php if ($v['embed']): ?>
                            <div class="video"><iframe src="<?= e($v['embed']) ?>" title="<?= e($v['label']) ?>" loading="lazy"
                                allow="accelerometer; encrypted-media; picture-in-picture; fullscreen" referrerpolicy="strict-origin-when-cross-origin"></iframe></div>
                        <?php else: ?>
                            <p><a href="<?= e($v['url']) ?>" rel="noopener nofollow ugc" target="_blank"><?= e($v['url']) ?></a></p>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>

            <?php if ($confidential !== null && trim(strip_tags($confidential)) !== ''): ?>
                <section class="prose confidential">
                    <h2 class="h3"><?= e(__('listing.confidential')) ?> <span class="level-badge level-badge--3"><?= e($levelName(3)) ?></span></h2>
                    <?= $confidential ?>
                </section>
            <?php endif; ?>

            <?php if ($attachments !== []): ?>
                <section class="documents" id="dokumenty">
                    <h2 class="h3"><?= e(__('wizard.documents_title')) ?></h2>
                    <?php if ($visibleDocs !== []): ?>
                        <ul class="doc-list">
                            <?php foreach ($visibleDocs as $a): ?>
                                <li><a href="<?= e(url('/plik/' . $a['id'])) ?>"><?= e($a['label']) ?></a> <span class="muted"><?= e(strtoupper(pathinfo($a['stored_path'], PATHINFO_EXTENSION))) ?>, <?= e(Str::bytes((int) $a['size_bytes'])) ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if ($lockedDocs > 0): ?>
                        <p class="muted"><?= e(__($level < 2 ? 'access.docs_locked_guest' : 'access.docs_locked_member', ['count' => $lockedDocs])) ?></p>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if ($level < 3 && ($hasConfidential || $lockedDocs > 0)): ?>
                <section class="access-box" id="dostep" aria-labelledby="dostep-h">
                    <h2 id="dostep-h" class="h3"><?= e(__('access.box_title')) ?></h2>
                    <p><?= e(__('access.box_text', ['level' => $levelName(3)])) ?></p>
                    <?php if ($user === null): ?>
                        <p><?= $lockedHtml ?></p>
                    <?php elseif ($accessStatus === 'requested'): ?>
                        <p class="notice"><?= e(__('access.status_requested')) ?></p>
                    <?php elseif ($accessStatus === 'denied'): ?>
                        <p class="notice notice--warning"><?= e(__('access.status_denied')) ?></p>
                    <?php else: ?>
                        <form method="post" action="<?= e(url('/oferta/' . $listing['id'] . '/dostep')) ?>" class="form">
                            <?= csrf_field() ?>
                            <div class="field">
                                <label for="access-message"><?= e(__('access.message')) ?> <span class="muted">(<?= e(__('form.optional')) ?>)</span></label>
                                <textarea id="access-message" name="message" rows="3" maxlength="1000"></textarea>
                            </div>
                            <button type="submit" class="button button--secondary"><?= e(__('access.request')) ?></button>
                        </form>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </div>

        <aside class="listing__aside">
            <?php if (!$isOwner): ?>
                <section class="inquiry" id="zapytanie" aria-labelledby="zapytanie-h">
                    <h2 id="zapytanie-h" class="h3"><?= e(__('inquiry.title')) ?></h2>
                    <?php if ($errors !== []): ?>
                        <div class="form-summary" role="alert" tabindex="-1" data-focus><p><?= e(__('form.fix_errors', ['count' => count($errors)])) ?></p></div>
                    <?php endif; ?>
                    <form method="post" action="<?= e(url('/oferta/' . $listing['id'] . '/zapytanie')) ?>" class="form" novalidate data-loading-form>
                        <?= csrf_field() ?>
                        <div class="hp" aria-hidden="true"><label for="website">Website</label><input id="website" name="website" tabindex="-1" autocomplete="off"></div>
                        <div class="field">
                            <label for="inq-name"><?= e(__('inquiry.name')) ?> <span class="req" aria-hidden="true">*</span></label>
                            <input id="inq-name" name="name" value="<?= e($old['name'] ?? '') ?>" required autocomplete="name" maxlength="150"<?= field_aria($errors, 'name') ?>>
                            <?= field_error($errors, 'name') ?>
                        </div>
                        <div class="field">
                            <label for="inq-email"><?= e(__('register.email')) ?> <span class="req" aria-hidden="true">*</span></label>
                            <input id="inq-email" name="email" type="email" value="<?= e($old['email'] ?? '') ?>" required autocomplete="email"<?= field_aria($errors, 'email') ?>>
                            <?= field_error($errors, 'email') ?>
                        </div>
                        <div class="field">
                            <label for="inq-phone"><?= e(__('inquiry.phone')) ?> <span class="muted">(<?= e(__('form.optional')) ?>)</span></label>
                            <input id="inq-phone" name="phone" type="tel" value="<?= e($old['phone'] ?? '') ?>" autocomplete="tel" inputmode="tel"<?= field_aria($errors, 'phone') ?>>
                            <?= field_error($errors, 'phone') ?>
                        </div>
                        <div class="field">
                            <label for="inq-message"><?= e(__('inquiry.message')) ?> <span class="req" aria-hidden="true">*</span></label>
                            <textarea id="inq-message" name="message" rows="5" required maxlength="3000"<?= field_aria($errors, 'message') ?>><?= e($old['message'] ?? __('inquiry.message_default', ['title' => $listing['title']])) ?></textarea>
                            <?= field_error($errors, 'message') ?>
                        </div>
                        <div class="field field--check">
                            <label>
                                <input type="checkbox" name="consent" value="1" required <?= !empty($old['consent']) ? 'checked' : '' ?><?= field_aria($errors, 'consent') ?>>
                                <span><?= e(__('inquiry.consent')) ?> <span class="req" aria-hidden="true">*</span></span>
                            </label>
                            <?= field_error($errors, 'consent') ?>
                        </div>
                        <button type="submit" class="button button--block" data-loading-text="<?= e(__('form.sending')) ?>"><?= e(__('inquiry.submit')) ?></button>
                    </form>
                </section>
            <?php endif; ?>
            <?php $consultUrl = (string) setting('portal.cta.consultation_url', ''); ?>
            <?php if ($consultUrl !== '' && preg_match('#^https?://#', $consultUrl)): ?>
                <p class="consult"><a class="button button--secondary button--block" href="<?= e($consultUrl) ?>" rel="noopener" target="_blank"><?= e((string) setting('portal.cta.consultation_label', __('listing.consultation'))) ?></a></p>
            <?php endif; ?>
        </aside>
    </div>
</article>
<script type="application/ld+json"><?= json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => array_values(array_filter([
        ['@type' => 'ListItem', 'position' => 1, 'name' => __('term.listings'), 'item' => rtrim((string) config('app.url'), '/') . url('/oferty')],
        ['@type' => 'ListItem', 'position' => 2, 'name' => $category['name'], 'item' => rtrim((string) config('app.url'), '/') . url('/oferty', ['kategoria' => $category['slug']])],
        ['@type' => 'ListItem', 'position' => 3, 'name' => $listing['title']],
    ])),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
