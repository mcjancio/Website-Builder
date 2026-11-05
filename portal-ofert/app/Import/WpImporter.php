<?php

declare(strict_types=1);

namespace App\Import;

use App\Auth\Auth;
use App\Core\DB;
use App\Core\Settings;
use App\Core\UploadedFile;
use App\Core\Validator;
use App\Listings\FieldInput;
use App\Listings\Fields;
use App\Listings\ListingService;
use App\Listings\MediaService;
use PDO;

/**
 * Import z dotychczasowej strony WordPress (wp_posts / wp_postmeta / taksonomie / wp_users + pliki uploads).
 * Co skąd trafia, opisuje plik mapowania JSON (docs/IMPORT-WP.md) — kod nie zna starych nazw pól.
 *
 * Zasady:
 *  - ponowne uruchomienie pomija rekordy już przeniesione (tabela import_map),
 *  - wartości przechodzą tę samą walidację co formularz (FieldInput) — złe trafiają do raportu,
 *  - konta dostają losowe hasło; użytkownicy ustawiają nowe linkiem z e-maila (--send-invites),
 *  - tryb próbny (dryRun) wykonuje wszystko w transakcji, którą na końcu wycofuje, i nie kopiuje plików.
 */
final class WpImporter
{
    private const SOURCE = 'wp';
    private const META_IGNORE = '/^_(edit_|wp_|elementor|yoast|oembed|wc_|transient|thumbnail_id|product_image_gallery|menu_|encloseme|pingme)/';

    /** @var array<string,mixed> */
    public array $report = [
        'users' => ['imported' => 0, 'existing' => 0, 'skipped' => []],
        'listings' => ['imported' => 0, 'existing' => 0, 'skipped' => []],
        'media' => ['images' => 0, 'attachments' => 0, 'missing' => [], 'rejected' => []],
        'values' => ['rejected' => [], 'unknown_options' => []],
        'unmapped_meta' => [],
        'unmapped_terms' => [],
        'redirects' => [],
    ];

    private string $p;

    /**
     * @param array<string,mixed> $map  plik mapowania
     */
    public function __construct(
        private readonly PDO $wp,
        string $prefix,
        private readonly array $map,
        private readonly string $uploadsDir,
        private readonly bool $dryRun = false,
        private readonly ?int $fallbackOwner = null,
    ) {
        if (!preg_match('/^[A-Za-z0-9_]{1,30}$/', $prefix)) {
            throw new \InvalidArgumentException('Nieprawidłowy prefiks tabel WordPressa.');
        }
        $this->p = $prefix;
    }

    // ================================================================== rozpoznanie

    /** Co jest w bazie WordPressa — do przygotowania pliku mapowania. */
    public function discover(?string $postType = null): array
    {
        $out = [
            'post_types' => $this->q("SELECT post_type, post_status, COUNT(*) AS n FROM {$this->p}posts WHERE post_type NOT IN ('revision','nav_menu_item','attachment','customize_changeset','oembed_cache','wp_global_styles') GROUP BY post_type, post_status ORDER BY n DESC"),
            'user_roles' => [],
            'user_meta' => $this->q("SELECT meta_key, COUNT(*) AS n, MAX(LEFT(meta_value, 60)) AS sample FROM {$this->p}usermeta WHERE meta_key NOT LIKE '%capabilities' AND meta_key NOT LIKE 'session_tokens' GROUP BY meta_key ORDER BY n DESC LIMIT 60"),
        ];
        foreach ($this->q("SELECT meta_value FROM {$this->p}usermeta WHERE meta_key = ?", [$this->p . 'capabilities']) as $r) {
            foreach (array_keys(self::unserialize($r['meta_value'])) as $role) {
                $out['user_roles'][$role] = ($out['user_roles'][$role] ?? 0) + 1;
            }
        }
        if ($postType !== null) {
            $out['meta'] = $this->q(
                "SELECT m.meta_key, COUNT(*) AS n, MAX(LEFT(m.meta_value, 80)) AS sample FROM {$this->p}postmeta m
                 JOIN {$this->p}posts p ON p.ID = m.post_id WHERE p.post_type = ? GROUP BY m.meta_key ORDER BY m.meta_key",
                [$postType]
            );
            $out['taxonomies'] = $this->q(
                "SELECT tt.taxonomy, t.slug, t.name, COUNT(*) AS n FROM {$this->p}term_relationships tr
                 JOIN {$this->p}term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                 JOIN {$this->p}terms t ON t.term_id = tt.term_id
                 JOIN {$this->p}posts p ON p.ID = tr.object_id WHERE p.post_type = ?
                 GROUP BY tt.taxonomy, t.slug, t.name ORDER BY tt.taxonomy, n DESC",
                [$postType]
            );
        }
        return $out;
    }

    // ================================================================== import

    public function run(): array
    {
        if ($this->dryRun) {
            DB::pdo()->beginTransaction();
        }
        try {
            $this->importUsers();
            $this->importListings();
        } finally {
            if ($this->dryRun && DB::pdo()->inTransaction()) {
                DB::pdo()->rollBack();
            }
        }
        Fields::flush();
        return $this->report;
    }

    private function importUsers(): void
    {
        $cfg = (array) ($this->map['users'] ?? []);
        if (($cfg['import'] ?? true) === false) {
            return;
        }
        $roles = (array) ($cfg['roles'] ?? []);
        $meta = (array) ($cfg['meta'] ?? []) + ['first_name' => 'first_name', 'last_name' => 'last_name'];
        foreach ($this->q("SELECT ID, user_email, user_registered, display_name FROM {$this->p}users ORDER BY ID") as $wu) {
            $wid = (int) $wu['ID'];
            if ($this->mapped('user', $wid) !== null) {
                $this->report['users']['existing']++;
                continue;
            }
            $um = $this->userMeta($wid);
            $userRoles = array_keys(self::unserialize($um[$this->p . 'capabilities'] ?? ''));
            if (array_intersect($userRoles, ['administrator']) !== [] && ($cfg['skip_admins'] ?? true)) {
                $this->report['users']['skipped'][] = "#{$wid} {$wu['user_email']}: administrator starej strony";
                continue;
            }
            if ($roles !== [] && array_intersect($userRoles, $roles) === []) {
                $this->report['users']['skipped'][] = "#{$wid} {$wu['user_email']}: rola " . (implode(', ', $userRoles) ?: 'brak') . ' poza listą users.roles';
                continue;
            }
            $email = mb_strtolower(trim((string) $wu['user_email']));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->report['users']['skipped'][] = "#{$wid}: niepoprawny e-mail „{$email}”";
                continue;
            }
            $existing = DB::value('SELECT id FROM users WHERE email = ?', [$email]);
            if ($existing !== null) {
                $this->remember('user', $wid, (int) $existing);
                $this->report['users']['existing']++;
                continue;
            }
            $get = static fn(string $k) => trim((string) ($um[$meta[$k] ?? ''] ?? ''));
            $first = $get('first_name');
            $last = $get('last_name');
            if ($first === '' && $last === '') {
                $parts = preg_split('/\s+/', trim((string) $wu['display_name']), 2) ?: [];
                $first = $parts[0] ?? '';
                $last = $parts[1] ?? '';
            }
            $phone = $get('phone') !== '' ? Validator::normalizePhone($get('phone')) : null;
            if ($get('phone') !== '' && $phone === null) {
                $this->report['users']['skipped'][] = "#{$wid} {$email}: telefon „{$get('phone')}” niepoprawny — konto bez telefonu";
            }
            if ($phone !== null && DB::value('SELECT 1 FROM users WHERE phone_e164 = ?', [$phone]) !== null) {
                $this->report['users']['skipped'][] = "#{$wid} {$email}: telefon {$phone} już użyty — konto bez telefonu";
                $phone = null;
            }
            $companyId = $this->company($get('nip'), $get('company_name'));
            $id = DB::insert('users', [
                'email' => $email,
                'password_hash' => Auth::hashPassword(bin2hex(random_bytes(24))), // nieznane — nowe hasło z linku
                'first_name' => mb_substr($first !== '' ? $first : '—', 0, 100),
                'last_name' => mb_substr($last, 0, 100),
                'phone_e164' => $phone,
                'company_id' => $companyId,
                'account_type' => $this->accountType($um, $userRoles),
                'email_verified_at' => gmdate('Y-m-d H:i:s'),
                'created_at' => $this->utc((string) $wu['user_registered'], true), // WP zapisuje datę rejestracji w UTC
            ]);
            $this->remember('user', $wid, $id);
            $this->report['users']['imported']++;
        }
    }

    private function importListings(): void
    {
        $type = (string) ($this->map['post_type'] ?? '');
        if ($type === '') {
            throw new \InvalidArgumentException('Brak „post_type” w pliku mapowania.');
        }
        $statuses = (array) ($this->map['statuses'] ?? ['publish' => 'published', 'draft' => 'draft', 'pending' => 'pending', 'private' => 'paused']);
        $posts = $this->q(
            "SELECT * FROM {$this->p}posts WHERE post_type = ? AND post_status IN (" . implode(',', array_fill(0, count($statuses), '?')) . ') ORDER BY ID',
            array_merge([$type], array_keys($statuses))
        );
        $used = $this->usedMetaKeys();
        $owner = $this->fallbackOwner ?? (int) DB::value("SELECT id FROM users WHERE role = 'admin' AND status = 'active' ORDER BY id LIMIT 1");
        $shortMax = (int) Settings::get('limits.short_desc_max', 300);

        foreach ($posts as $post) {
            $pid = (int) $post['ID'];
            $meta = $this->postMeta($pid);
            foreach ($meta as $k => $v) {
                if (!in_array($k, $used, true) && !preg_match(self::META_IGNORE, $k) && trim((string) $v) !== '') {
                    $this->report['unmapped_meta'][$k] ??= ['count' => 0, 'sample' => mb_substr((string) $v, 0, 80)];
                    $this->report['unmapped_meta'][$k]['count']++;
                }
            }
            if (($existing = $this->mapped('listing', $pid)) !== null) {
                $this->report['listings']['existing']++;
                $this->redirectFor($post, $existing);
                continue;
            }
            $label = "#{$pid} „" . mb_substr((string) $post['post_title'], 0, 60) . '”';
            $categoryId = $this->category($pid);
            if ($categoryId === null) {
                $this->report['listings']['skipped'][] = "{$label}: brak kategorii (category.map / category.default)";
                continue;
            }
            $userId = $this->mapped('user', (int) $post['post_author']) ?? $owner;
            if (!$userId) {
                $this->report['listings']['skipped'][] = "{$label}: brak autora (nie zaimportowano konta, a w portalu nie ma administratora)";
                continue;
            }

            $core = [];
            foreach ((array) ($this->map['core'] ?? ['title' => 'post_title', 'short_desc' => 'post_excerpt', 'full_desc_md' => 'post_content']) as $k => $src) {
                $raw = $this->source((string) $src, $post, $meta);
                $text = is_array($raw) ? implode(', ', $raw) : (string) $raw;
                $core[$k] = in_array($k, ['full_desc_md', 'confidential_md'], true) ? HtmlToMarkdown::convert($text) : trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
            $title = mb_substr($core['title'] ?? '', 0, 200);
            if ($title === '') {
                $this->report['listings']['skipped'][] = "{$label}: pusty tytuł";
                continue;
            }
            $short = $core['short_desc'] ?? '';
            if ($short === '') {
                $plain = trim((string) preg_replace('/\s+/', ' ', strip_tags(\App\Core\Markdown::toHtml($core['full_desc_md'] ?? ''))));
                $short = $plain !== '' ? $plain : $title;
            }
            if (mb_strlen($short) > $shortMax) {
                $short = rtrim(mb_substr($short, 0, $shortMax - 1)) . '…';
            }

            $id = ListingService::create($userId, $categoryId, $title, $short);
            $listing = ListingService::find($id);
            $fields = Fields::forCategory($categoryId);
            [$values, $errors] = FieldInput::parse($fields, $this->fieldValues($fields, $post, $meta, $label));
            foreach ($errors as $key => $err) {
                $this->report['values']['rejected'][] = "{$label} → {$key}: {$err}";
            }
            ListingService::save($listing, array_intersect_key($core, array_flip(['full_desc_md', 'confidential_md'])), $fields, $values);
            $this->media($id, $post, $meta, $label);

            $status = $statuses[$post['post_status']] ?? 'draft';
            $gmt = (string) $post['post_date_gmt'];
            $date = $gmt !== '' && !str_starts_with($gmt, '0000') ? $this->utc($gmt, true) : $this->utc((string) $post['post_date']);
            DB::update('listings', [
                'status' => $status,
                'published_at' => in_array($status, ['published', 'paused', 'sold'], true) ? $date : null,
                'created_at' => $date,
            ], ['id' => $id]);
            $this->remember('listing', $pid, $id, $this->oldPath($post));
            $this->redirectFor($post, $id);
            $this->report['listings']['imported']++;
        }
    }

    // ================================================================== pola

    /** Surowe wartości w formacie formularza (jak $_POST['f']) — walidację robi FieldInput. */
    private function fieldValues(array $fields, array $post, array $meta, string $label): array
    {
        $raw = [];
        foreach ((array) ($this->map['fields'] ?? []) as $key => $spec) {
            if (!isset($fields[$key])) {
                continue; // pole spoza tej kategorii
            }
            $f = $fields[$key];
            $spec = is_string($spec) ? ['from' => $spec] : (array) $spec;
            $val = $this->source((string) ($spec['from'] ?? ''), $post, $meta);
            $list = array_values(array_filter(array_map(static fn($x) => trim(html_entity_decode(strip_tags((string) $x), ENT_QUOTES, 'UTF-8')), is_array($val) ? $val : [$val]), static fn($x) => $x !== ''));
            if ($list === []) {
                continue;
            }
            $map = array_change_key_case((array) ($spec['values'] ?? []), CASE_LOWER);
            switch ($f['type']) {
                case 'select': case 'region': case 'multiselect':
                    $keys = [];
                    foreach ($f['type'] === 'multiselect' ? $list : [$list[0]] as $one) {
                        $k = $this->optionKey($f, $one, $map);
                        if ($k === null) {
                            $this->report['values']['unknown_options'][$key . ' = ' . $one] = ($this->report['values']['unknown_options'][$key . ' = ' . $one] ?? 0) + 1;
                            continue;
                        }
                        $keys[] = $k;
                    }
                    if ($keys !== []) {
                        $raw[$key] = $f['type'] === 'multiselect' ? $keys : $keys[0];
                    }
                    break;
                case 'money':
                    $amount = $map[mb_strtolower($list[0])] ?? $list[0];
                    $currency = strtoupper((string) ($spec['currency'] ?? 'PLN'));
                    if (!empty($spec['currency_from'])) {
                        $c = $this->source((string) $spec['currency_from'], $post, $meta);
                        $currency = strtoupper(trim((string) (is_array($c) ? ($c[0] ?? '') : $c))) ?: $currency;
                    }
                    $raw[$key] = ['amount' => self::number((string) $amount), 'currency' => $currency];
                    break;
                case 'bool':
                    $raw[$key] = in_array(mb_strtolower($list[0]), ['1', 'yes', 'tak', 'on', 'true', 'y'], true) ? '1' : '';
                    break;
                case 'date':
                    $t = strtotime(str_replace('.', '-', $list[0]));
                    $raw[$key] = $t !== false ? gmdate('Y-m-d', $t) : $list[0];
                    break;
                case 'url_list':
                    $raw[$key] = implode("\n", $list);
                    break;
                case 'int': case 'decimal': case 'percent':
                    $raw[$key] = self::number((string) ($map[mb_strtolower($list[0])] ?? $list[0]));
                    break;
                default:
                    $raw[$key] = (string) ($map[mb_strtolower($list[0])] ?? implode(', ', $list));
            }
        }
        return $raw;
    }

    private function optionKey(array $f, string $value, array $map): ?string
    {
        $v = $map[mb_strtolower($value)] ?? $value;
        if (isset($f['options'][$v])) {
            return $v;
        }
        $norm = static fn(string $s) => \App\Core\Str::slug($s, 100);
        foreach ($f['options'] as $key => $o) {
            if ($norm((string) $o['label']) === $norm((string) $v) || $norm((string) $key) === $norm((string) $v)) {
                return (string) $key;
            }
        }
        return null;
    }

    /**
     * Źródło wartości: post_title | post_content | post_excerpt | post_date | meta:klucz | taxonomy:nazwa
     * @return string|array<int,string>|null
     */
    private function source(string $src, array $post, array $meta): string|array|null
    {
        if (str_starts_with($src, 'meta:')) {
            $v = $meta[substr($src, 5)] ?? null;
            if (is_string($v) && preg_match('/^a:\d+:\{/', $v)) {
                return array_values(array_map('strval', array_filter(self::unserialize($v), 'is_scalar')));
            }
            return $v;
        }
        if (str_starts_with($src, 'taxonomy:')) {
            return array_column($this->terms((int) $post['ID'], substr($src, 9)), 'name');
        }
        return isset($post[$src]) && is_scalar($post[$src]) ? (string) $post[$src] : null;
    }

    // ================================================================== pliki

    private function media(int $listingId, array $post, array $meta, string $label): void
    {
        $cfg = (array) ($this->map['images'] ?? ['featured' => true]);
        $ids = [];
        if ($cfg['featured'] ?? true) {
            $ids[] = (int) ($meta['_thumbnail_id'] ?? 0);
        }
        if (!empty($cfg['gallery_meta'])) {
            $ids = array_merge($ids, array_map('intval', explode(',', (string) ($meta[$cfg['gallery_meta']] ?? ''))));
        }
        $paths = [];
        foreach (array_unique(array_filter($ids)) as $aid) {
            if (($p = $this->attachmentPath($aid)) !== null) {
                $paths[$p] = true;
            }
        }
        if (!empty($cfg['from_content'])) {
            preg_match_all('#/wp-content/uploads/([^"\'\s?)]+\.(?:jpe?g|png|webp))#i', (string) $post['post_content'], $m);
            foreach ($m[1] as $rel) {
                $paths[$this->original(rawurldecode($rel))] = true;
            }
        }
        $listing = ListingService::find($listingId);
        foreach (array_keys($paths) as $rel) {
            $file = $this->uploadsDir . '/' . ltrim($rel, '/');
            if (!is_file($file)) {
                $this->report['media']['missing'][] = "{$label}: {$rel}";
                continue;
            }
            if ($this->dryRun) {
                continue;
            }
            $r = MediaService::addImage($listing, UploadedFile::local($file));
            if ($r['ok']) {
                $this->report['media']['images']++;
            } else {
                $this->report['media']['rejected'][] = "{$label}: {$rel} — {$r['error']}";
            }
        }

        foreach ((array) ($this->map['attachments'] ?? []) as $spec) {
            $val = $this->source((string) ($spec['from'] ?? ''), $post, $meta);
            foreach (is_array($val) ? $val : [$val] as $one) {
                $one = trim((string) $one);
                if ($one === '') {
                    continue;
                }
                $rel = ctype_digit($one) ? $this->attachmentPath((int) $one) : (preg_match('#/wp-content/uploads/(.+)$#', $one, $mm) ? rawurldecode($mm[1]) : null);
                if ($rel === null) {
                    $this->report['media']['missing'][] = "{$label}: dokument „{$one}” — nie znaleziono w bibliotece mediów";
                    continue;
                }
                $file = $this->uploadsDir . '/' . $rel;
                if (!is_file($file)) {
                    $this->report['media']['missing'][] = "{$label}: {$rel}";
                    continue;
                }
                if ($this->dryRun) {
                    continue;
                }
                $r = MediaService::addAttachment($listing, UploadedFile::local($file), (string) ($spec['label'] ?? ''), isset($spec['access_level']) ? (int) $spec['access_level'] : null);
                $r['ok'] ? $this->report['media']['attachments']++ : $this->report['media']['rejected'][] = "{$label}: {$rel} — {$r['error']}";
            }
        }
    }

    private function attachmentPath(int $attachmentId): ?string
    {
        $v = $this->wpValue("SELECT meta_value FROM {$this->p}postmeta WHERE post_id = ? AND meta_key = '_wp_attached_file' LIMIT 1", [$attachmentId]);
        return $v !== null && $v !== '' ? (string) $v : null;
    }

    /** obraz-300x200.jpg → obraz.jpg, jeśli oryginał istnieje. */
    private function original(string $rel): string
    {
        $orig = (string) preg_replace('/-\d+x\d+(\.[a-z]+)$/i', '$1', $rel);
        return $orig !== $rel && is_file($this->uploadsDir . '/' . $orig) ? $orig : $rel;
    }

    // ================================================================== przekierowania

    private function oldPath(array $post): string
    {
        $pattern = (string) (($this->map['redirects']['paths'] ?? ['/{slug}/'])[0] ?? '/{slug}/');
        return str_replace('{slug}', rawurldecode((string) $post['post_name']), $pattern);
    }

    private function redirectFor(array $post, int $listingId): void
    {
        $slug = (string) DB::value('SELECT slug FROM listings WHERE id = ?', [$listingId]);
        if ($slug === '' || (string) $post['post_name'] === '') {
            return;
        }
        $target = '/oferta/' . $slug;
        foreach ((array) ($this->map['redirects']['paths'] ?? ['/{slug}/']) as $pattern) {
            $path = str_replace('{slug}', rawurldecode((string) $post['post_name']), (string) $pattern);
            $this->report['redirects'][] = ['type' => 'path', 'from' => $path, 'to' => $target];
        }
        foreach ((array) ($this->map['redirects']['query'] ?? ['p']) as $param) {
            $value = $param === 'p' || $param === 'page_id' ? (string) $post['ID'] : rawurldecode((string) $post['post_name']);
            $this->report['redirects'][] = ['type' => 'query', 'param' => (string) $param, 'value' => $value, 'to' => $target];
        }
    }

    /** Reguły do public/.htaccess (przed regułą front controllera) albo do .htaccess starej domeny. */
    public static function htaccess(array $redirects, string $base = ''): string
    {
        $lines = [
            '# Przekierowania 301 ze starej strony (WordPress) — wygenerowane przez bin/import-wp.php ' . gmdate('Y-m-d'),
            '# Wklej do public/.htaccess ZARAZ PO „RewriteEngine On”. Na starej domenie dodaj pełny adres nowej przed /oferta/.',
        ];
        $seen = [];
        foreach ($redirects as $r) {
            $to = $base . $r['to'];
            if ($r['type'] === 'path') {
                $from = trim($r['from'], '/');
                if ($from === '' || isset($seen['p' . $from])) {
                    continue;
                }
                $seen['p' . $from] = true;
                $lines[] = 'RewriteRule ^' . preg_quote($from, null) . '/?$ ' . $to . ' [R=301,L,NE]';
            } else {
                $key = $r['param'] . '=' . $r['value'];
                if (isset($seen['q' . $key])) {
                    continue;
                }
                $seen['q' . $key] = true;
                $lines[] = 'RewriteCond %{QUERY_STRING} (^|&)' . preg_quote($r['param'], null) . '=' . preg_quote(rawurlencode($r['value']), null) . '(&|$)';
                $lines[] = 'RewriteRule ^$ ' . $to . '? [R=301,L,NE]';
            }
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * E-mail „portal ma nową wersję — ustaw hasło” do przeniesionych kont, które jeszcze się nie zalogowały
     * i nie dostały zaproszenia. Link ważny $days dni. Zwraca liczbę wysłanych.
     */
    public static function sendInvites(int $limit = 200, int $days = 14): int
    {
        $rows = DB::fetchAll(
            "SELECT u.id, u.email, u.first_name FROM import_map m JOIN users u ON u.id = m.target_id
             WHERE m.source = ? AND m.entity = 'user' AND u.status = 'active' AND u.last_login_at IS NULL
               AND NOT EXISTS (SELECT 1 FROM audit_log a WHERE a.action = 'import.invite' AND a.entity_type = 'user' AND a.entity_id = u.id)
             ORDER BY u.id LIMIT " . max(1, $limit),
            [self::SOURCE]
        );
        $base = rtrim((string) config('app.url'), '/');
        foreach ($rows as $u) {
            $t = \App\Auth\Tokens::create((int) $u['id'], 'password_reset', $days * 86400);
            \App\Core\Mailer::queue((string) $u['email'], 'account-migrated', [
                'name' => $u['first_name'],
                'url' => $base . url('/reset-hasla/' . $t['selector'] . '/' . $t['validator']),
                'days' => $days,
                'portal' => (string) Settings::get('portal.name', __('app.default_name')),
            ]);
            \App\Auth\Audit::log('import.invite', 'user', (int) $u['id']);
        }
        return count($rows);
    }

    // ================================================================== konta pomocniczo

    private function company(string $nip, string $name): ?int
    {
        $nip = (string) preg_replace('/\D/', '', $nip);
        if ($nip !== '' && Validator::nipValid($nip)) {
            $id = DB::value('SELECT id FROM companies WHERE nip = ?', [$nip]);
            return $id !== null ? (int) $id : DB::insert('companies', ['nip' => $nip, 'name' => mb_substr($name !== '' ? $name : 'NIP ' . $nip, 0, 255), 'source' => 'manual']);
        }
        return $name !== '' ? DB::insert('companies', ['name' => mb_substr($name, 0, 255), 'source' => 'manual']) : null;
    }

    private function accountType(array $um, array $roles): ?string
    {
        $cfg = (array) ($this->map['users']['account_type'] ?? []);
        $types = array_column((array) Settings::get('account_types', []), 'key');
        if ($types === []) {
            return null;
        }
        $raw = !empty($cfg['from']) ? (str_starts_with((string) $cfg['from'], 'role')
            ? ($roles[0] ?? '')
            : (string) ($um[preg_replace('/^meta:/', '', (string) $cfg['from'])] ?? '')) : '';
        $map = array_change_key_case((array) ($cfg['values'] ?? []), CASE_LOWER);
        $v = $map[mb_strtolower($raw)] ?? $raw;
        return in_array($v, $types, true) ? $v : (in_array($cfg['default'] ?? null, $types, true) ? $cfg['default'] : null);
    }

    private function category(int $postId): ?int
    {
        $cfg = (array) ($this->map['category'] ?? []);
        $slug = null;
        if (!empty($cfg['taxonomy'])) {
            foreach ($this->terms($postId, (string) $cfg['taxonomy']) as $t) {
                $candidate = $cfg['map'][$t['slug']] ?? $cfg['map'][$t['name']] ?? null;
                if ($candidate !== null) {
                    $slug = $candidate;
                    break;
                }
                $this->report['unmapped_terms'][$cfg['taxonomy'] . ': ' . $t['slug']] = ($this->report['unmapped_terms'][$cfg['taxonomy'] . ': ' . $t['slug']] ?? 0) + 1;
            }
        }
        $slug ??= $cfg['default'] ?? null;
        $id = $slug !== null ? DB::value('SELECT id FROM categories WHERE slug = ?', [$slug]) : null;
        return $id !== null ? (int) $id : null;
    }

    // ================================================================== dostęp do WP

    private function usedMetaKeys(): array
    {
        $keys = [];
        $collect = static function ($v) use (&$keys): void {
            if (is_string($v) && str_starts_with($v, 'meta:')) {
                $keys[] = substr($v, 5);
            }
        };
        $map = $this->map;
        array_walk_recursive($map, static function ($v, $k) use ($collect, &$keys): void {
            $collect($v);
            if ($k === 'gallery_meta' && is_string($v)) {
                $keys[] = $v;
            }
        });
        return $keys;
    }

    private function terms(int $postId, string $taxonomy): array
    {
        return $this->q(
            "SELECT t.slug, t.name FROM {$this->p}term_relationships tr
             JOIN {$this->p}term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             JOIN {$this->p}terms t ON t.term_id = tt.term_id WHERE tr.object_id = ? AND tt.taxonomy = ? ORDER BY tr.term_order, t.name",
            [$postId, $taxonomy]
        );
    }

    private function postMeta(int $postId): array
    {
        $out = [];
        foreach ($this->q("SELECT meta_key, meta_value FROM {$this->p}postmeta WHERE post_id = ? ORDER BY meta_id", [$postId]) as $r) {
            $out[$r['meta_key']] = $r['meta_value'];
        }
        return $out;
    }

    private function userMeta(int $userId): array
    {
        $out = [];
        foreach ($this->q("SELECT meta_key, meta_value FROM {$this->p}usermeta WHERE user_id = ?", [$userId]) as $r) {
            $out[$r['meta_key']] = $r['meta_value'];
        }
        return $out;
    }

    private function q(string $sql, array $params = []): array
    {
        $st = $this->wp->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    private function wpValue(string $sql, array $params): mixed
    {
        $st = $this->wp->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    private function mapped(string $entity, int|string $sourceId): ?int
    {
        $v = DB::value('SELECT target_id FROM import_map WHERE source = ? AND entity = ? AND source_id = ?', [self::SOURCE, $entity, $sourceId]);
        return $v !== null ? (int) $v : null;
    }

    private function remember(string $entity, int|string $sourceId, int $targetId, ?string $oldPath = null): void
    {
        DB::query('INSERT IGNORE INTO import_map (source, entity, source_id, target_id, old_path) VALUES (?, ?, ?, ?, ?)', [self::SOURCE, $entity, $sourceId, $targetId, $oldPath]);
    }

    /** Data z WP (czas lokalny lub GMT) → UTC dla bazy portalu. */
    private function utc(string $date, bool $isGmt = false): string
    {
        if ($date === '' || str_starts_with($date, '0000')) {
            return gmdate('Y-m-d H:i:s');
        }
        $tz = new \DateTimeZone($isGmt ? 'UTC' : (string) config('app.timezone', 'Europe/Warsaw'));
        return (new \DateTimeImmutable($date, $tz))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** „2 500 000 zł” → „2 500 000”, „18,5%” → „18,5”; tekst bez cyfr zostaje — odrzuci go walidacja i trafi do raportu. */
    private static function number(string $v): string
    {
        $clean = trim((string) preg_replace('/[^\d,.\-\s]/u', '', $v));
        return preg_match('/\d/', $clean) ? $clean : $v;
    }

    /** Bezpieczne odczytanie zserializowanych danych WordPressa (bez tworzenia obiektów). */
    public static function unserialize(string $value): array
    {
        if ($value === '' || !preg_match('/^a:\d+:\{/', $value)) {
            return [];
        }
        $v = @unserialize($value, ['allowed_classes' => false]);
        return is_array($v) ? $v : [];
    }
}
