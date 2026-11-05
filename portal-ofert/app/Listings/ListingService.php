<?php

declare(strict_types=1);

namespace App\Listings;

use App\Auth\Audit;
use App\Core\DB;
use App\Core\Mailer;
use App\Core\Request;
use App\Core\Settings;
use App\Core\Str;

/**
 * Cykl życia oferty (KONCEPCJA §5.3):
 *   draft → pending → published → (paused | sold | archived), a także rejected → (poprawki) → pending
 *
 * Oferta jeszcze nieopublikowana: edycja zmienia ją bezpośrednio (a oferta „pending” wraca do szkicu,
 * żeby moderator nie zatwierdził jej w trakcie poprawek).
 * Oferta OPUBLIKOWANA: edycja trafia do rewizji (listing_revisions) — publiczna wersja zostaje bez zmian,
 * dopóki moderator nie zatwierdzi rewizji. Nowe zdjęcia/pliki czekają w stanie pending_add.
 */
final class ListingService
{
    public const CORE = ['title', 'short_desc', 'full_desc_md', 'confidential_md'];
    private const LIVE_STATUSES = ['published', 'paused', 'sold'];

    public static function find(int $id): ?array
    {
        return DB::fetch('SELECT * FROM listings WHERE id = ?', [$id]);
    }

    public static function isLive(array $listing): bool
    {
        return in_array($listing['status'], self::LIVE_STATUSES, true) || $listing['published_at'] !== null && $listing['status'] !== 'archived';
    }

    public static function create(int $userId, int $categoryId, string $title, string $shortDesc): int
    {
        return DB::transaction(static function () use ($userId, $categoryId, $title, $shortDesc): int {
            $id = DB::insert('listings', [
                'user_id' => $userId,
                'category_id' => $categoryId,
                'slug' => 'tmp-' . bin2hex(random_bytes(8)),
                'title' => $title,
                'short_desc' => $shortDesc,
                'status' => 'draft',
            ]);
            DB::update('listings', ['slug' => Str::slug($title) . '-' . $id], ['id' => $id]);
            return $id;
        });
    }

    /**
     * Stan do edycji: dla opublikowanej oferty — z otwartej rewizji (jeśli jest), inaczej z oferty.
     * @return array{core:array,values:array,category_id:int,revision:?array,attachments_meta:array}
     */
    public static function editable(array $listing): array
    {
        $core = array_intersect_key($listing, array_flip(self::CORE));
        $state = [
            'core' => $core,
            'category_id' => (int) $listing['category_id'],
            'values' => ValueStore::load((int) $listing['id']),
            'attachments_meta' => [],
            'revision' => null,
        ];
        if (self::isLive($listing) && ($rev = self::openRevision((int) $listing['id'])) !== null) {
            $p = json_decode((string) $rev['payload'], true) ?: [];
            $state['core'] = array_merge($state['core'], (array) ($p['core'] ?? []));
            $state['values'] = (array) ($p['values'] ?? $state['values']);
            $state['attachments_meta'] = (array) ($p['attachments_meta'] ?? []);
            $state['revision'] = $rev;
        }
        return $state;
    }

    /**
     * Zapis szkicu (podstawy, opis, pola). Zwraca informację, czy oferta wróciła z moderacji do szkicu.
     * @param array<string,mixed>|null $values  null = nie zmieniaj pól
     * @param array<string,array>|null $fields  definicje pól, których dotyczą $values
     */
    public static function save(array $listing, array $core, ?array $fields = null, ?array $values = null, ?array $attachmentsMeta = null): string
    {
        $id = (int) $listing['id'];
        $core = array_intersect_key($core, array_flip(self::CORE));

        if (self::isLive($listing)) {
            $state = self::editable($listing);
            $payloadValues = $state['values'];
            if ($fields !== null && $values !== null) {
                foreach (array_keys($fields) as $key) {
                    unset($payloadValues[$key]);
                }
                $payloadValues = array_merge($payloadValues, $values);
            }
            $payload = [
                'core' => array_merge($state['core'], $core),
                'values' => $payloadValues,
                'attachments_meta' => array_replace($state['attachments_meta'], $attachmentsMeta ?? []),
            ];
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if ($state['revision'] !== null) {
                // Poprawki w rewizji czekającej na moderację cofają ją do szkicu.
                DB::query("UPDATE listing_revisions SET payload = ?, status = 'draft', submitted_at = NULL WHERE id = ?", [$json, $state['revision']['id']]);
                return $state['revision']['status'] === 'pending' ? 'revision_back_to_draft' : 'revision_saved';
            }
            DB::insert('listing_revisions', ['listing_id' => $id, 'user_id' => $listing['user_id'], 'payload' => $json, 'status' => 'draft']);
            return 'revision_saved';
        }

        DB::transaction(static function () use ($id, $listing, $core, $fields, $values, $attachmentsMeta): void {
            $update = $core;
            if (isset($core['title']) && $core['title'] !== $listing['title']) {
                $update['slug'] = Str::slug($core['title']) . '-' . $id;
            }
            if ($listing['status'] === 'pending') {
                $update['status'] = 'draft';
                $update['submitted_at'] = null;
            }
            if ($update !== []) {
                DB::update('listings', $update, ['id' => $id]);
            }
            if ($fields !== null && $values !== null) {
                ValueStore::save($id, $fields, $values);
                self::syncLocation($id, $values, $fields);
            }
            foreach ($attachmentsMeta ?? [] as $attId => $meta) {
                DB::query('UPDATE attachments SET label = ?, access_level = ? WHERE id = ? AND listing_id = ?',
                    [$meta['label'], $meta['access_level'], (int) $attId, $id]);
            }
        });
        return $listing['status'] === 'pending' ? 'back_to_draft' : 'saved';
    }

    /**
     * Wysłanie do moderacji. Zwraca listę braków (pusta = wysłano).
     * @return array<string,string>
     */
    public static function submit(array $listing, ?Request $request = null): array
    {
        $state = self::editable($listing);
        $fields = Fields::forCategory($state['category_id']);
        $missing = FieldInput::missingRequired($fields, $state['values']);
        foreach (['title' => __('listing.title'), 'short_desc' => __('listing.short_desc')] as $k => $label) {
            if (trim((string) ($state['core'][$k] ?? '')) === '') {
                $missing = [$k => $label] + $missing;
            }
        }
        if ($missing !== []) {
            return $missing;
        }

        $id = (int) $listing['id'];
        if (self::isLive($listing)) {
            if ($state['revision'] === null) {
                // Brak zmian do zgłoszenia — tworzymy rewizję z bieżącego stanu (np. same nowe zdjęcia).
                self::save($listing, []);
                $state = self::editable($listing);
            }
            DB::query("UPDATE listing_revisions SET status = 'pending', submitted_at = UTC_TIMESTAMP() WHERE id = ?", [$state['revision']['id']]);
            $kind = 'revision';
        } else {
            DB::query("UPDATE listings SET status = 'pending', submitted_at = UTC_TIMESTAMP(), moderation_note = NULL WHERE id = ?", [$id]);
            $kind = 'new';
        }
        Audit::log('listing.submit', 'listing', $id, ['kind' => $kind], $request);
        self::notifyModerators($id, $kind);
        return [];
    }

    // ---------------------------------------------------------------- moderacja (UI w etapie 5)

    /** Zatwierdza nową ofertę albo oczekującą rewizję. */
    public static function approve(int $listingId, ?int $moderatorId, ?Request $request = null): string
    {
        $listing = self::find($listingId);
        if ($listing === null) {
            throw new \RuntimeException("Brak oferty #{$listingId}");
        }
        $rev = self::isLive($listing) ? DB::fetch("SELECT * FROM listing_revisions WHERE listing_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1", [$listingId]) : null;

        if ($rev !== null) {
            DB::transaction(function () use ($listing, $rev, $moderatorId): void {
                $p = json_decode((string) $rev['payload'], true) ?: [];
                $core = array_intersect_key((array) ($p['core'] ?? []), array_flip(self::CORE));
                if (isset($core['title'])) {
                    $core['slug'] = Str::slug($core['title']) . '-' . $listing['id'];
                }
                if ($core !== []) {
                    DB::update('listings', $core, ['id' => $listing['id']]);
                }
                $fields = Fields::forCategory((int) $listing['category_id']);
                $values = (array) ($p['values'] ?? []);
                ValueStore::save((int) $listing['id'], $fields, $values);
                self::syncLocation((int) $listing['id'], $values, $fields);
                foreach ((array) ($p['attachments_meta'] ?? []) as $attId => $meta) {
                    DB::query('UPDATE attachments SET label = ?, access_level = ? WHERE id = ? AND listing_id = ?',
                        [$meta['label'], $meta['access_level'], (int) $attId, $listing['id']]);
                }
                MediaService::applyPending((int) $listing['id']);
                DB::query("UPDATE listing_revisions SET status = 'approved', reviewer_id = ?, reviewed_at = UTC_TIMESTAMP() WHERE id = ?", [$moderatorId, $rev['id']]);
            });
            $kind = 'revision';
        } elseif ($listing['status'] === 'pending') {
            DB::transaction(static function () use ($listingId): void {
                DB::query("UPDATE listings SET status = 'published', published_at = COALESCE(published_at, UTC_TIMESTAMP()), moderation_note = NULL WHERE id = ?", [$listingId]);
                MediaService::applyPending($listingId);
            });
            $kind = 'new';
        } else {
            throw new \RuntimeException("Oferta #{$listingId} nie czeka na moderację.");
        }
        Audit::log('listing.approve', 'listing', $listingId, ['kind' => $kind], $request, $moderatorId);
        self::notifyAuthor($listing, 'listing-approved', null, $kind);
        return $kind;
    }

    public static function reject(int $listingId, ?int $moderatorId, string $note, ?Request $request = null): string
    {
        $listing = self::find($listingId);
        if ($listing === null) {
            throw new \RuntimeException("Brak oferty #{$listingId}");
        }
        $note = mb_substr(trim($note), 0, 500);
        $rev = self::isLive($listing) ? DB::fetch("SELECT id FROM listing_revisions WHERE listing_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1", [$listingId]) : null;
        if ($rev !== null) {
            DB::query("UPDATE listing_revisions SET status = 'rejected', reviewer_id = ?, review_note = ?, reviewed_at = UTC_TIMESTAMP() WHERE id = ?", [$moderatorId, $note, $rev['id']]);
            MediaService::discardPending($listingId);
            $kind = 'revision';
        } elseif ($listing['status'] === 'pending') {
            DB::query("UPDATE listings SET status = 'rejected', moderation_note = ? WHERE id = ?", [$note, $listingId]);
            $kind = 'new';
        } else {
            throw new \RuntimeException("Oferta #{$listingId} nie czeka na moderację.");
        }
        Audit::log('listing.reject', 'listing', $listingId, ['kind' => $kind, 'note' => $note], $request, $moderatorId);
        self::notifyAuthor($listing, 'listing-rejected', $note, $kind);
        return $kind;
    }

    /** Akcje statusu: autor (własne) i moderacja. @return bool czy zmieniono */
    /**
     * @param bool $byStaff  zmiana z panelu (moderacja). Ofertę wstrzymaną przez moderację autor nie wznowi sam.
     */
    public static function changeStatus(array $listing, string $action, ?int $by, ?Request $request = null, bool $byStaff = false): bool
    {
        $map = [
            'pause' => [['published'], 'paused'],
            'resume' => [['paused'], 'published'],
            'sold' => [['published', 'paused'], 'sold'],
            'archive' => [['published', 'paused', 'sold', 'rejected', 'draft'], 'archived'],
            'restore' => [['archived'], 'paused'],
        ];
        if (!isset($map[$action]) || !in_array($listing['status'], $map[$action][0], true)) {
            return false;
        }
        if (!$byStaff && !empty($listing['paused_by_staff']) && in_array($action, ['resume', 'sold'], true)) {
            return false;
        }
        $data = ['status' => $map[$action][1]];
        if ($byStaff) {
            $data['paused_by_staff'] = (int) ($action === 'pause' || ($action === 'restore'));
        }
        DB::update('listings', $data, ['id' => $listing['id']]);
        Audit::log('listing.' . $action, 'listing', (int) $listing['id'], ['from' => $listing['status']], $request, $by);
        return true;
    }

    public static function setFeatured(int $listingId, bool $featured, ?int $by): void
    {
        DB::update('listings', ['is_featured' => (int) $featured], ['id' => $listingId]);
        Audit::log($featured ? 'listing.feature' : 'listing.unfeature', 'listing', $listingId, [], null, $by);
    }

    /**
     * Porównanie „było / jest” dla moderatora: opublikowana wersja kontra rewizja.
     * Dla nowej oferty „było” jest puste. Wartości sformatowane jak na stronie oferty.
     * @return array<int,array{group:string,label:string,before:string,after:string,changed:bool,level:int}>
     */
    public static function diff(array $listing): array
    {
        $live = [
            'core' => array_intersect_key($listing, array_flip(self::CORE)),
            'values' => ValueStore::load((int) $listing['id']),
        ];
        $rev = self::isLive($listing) ? DB::fetch("SELECT payload FROM listing_revisions WHERE listing_id = ? AND status IN ('pending', 'draft') ORDER BY id DESC LIMIT 1", [$listing['id']]) : null;
        $isNew = $rev === null;
        $next = $live;
        if (!$isNew) {
            $p = json_decode((string) $rev['payload'], true) ?: [];
            $next = ['core' => array_merge($live['core'], (array) ($p['core'] ?? [])), 'values' => (array) ($p['values'] ?? [])];
        }
        $rows = [];
        $labels = ['title' => __('listing.title'), 'short_desc' => __('listing.short_desc'), 'full_desc_md' => __('listing.full_desc'), 'confidential_md' => __('listing.confidential')];
        $levels = ['title' => 1, 'short_desc' => 1, 'full_desc_md' => 2, 'confidential_md' => 3];
        foreach (self::CORE as $k) {
            $before = $isNew ? '' : (string) ($live['core'][$k] ?? '');
            $after = (string) ($next['core'][$k] ?? '');
            $rows[] = ['group' => __('diff.basics'), 'label' => $labels[$k], 'before' => $before, 'after' => $after, 'changed' => $before !== $after, 'level' => $levels[$k]];
        }
        foreach (Fields::forCategory((int) $listing['category_id']) as $key => $f) {
            $b = !$isNew && array_key_exists($key, $live['values']) ? Display::value($f, $live['values'][$key]) : '';
            $a = array_key_exists($key, $next['values']) ? Display::value($f, $next['values'][$key]) : '';
            if ($a === '' && $b === '') {
                continue;
            }
            $rows[] = ['group' => $f['group_name'], 'label' => $f['label'], 'before' => $b, 'after' => $a, 'changed' => $a !== $b, 'level' => $f['access_level']];
        }
        return $rows;
    }

    /** Kolejka moderacji: nowe oferty i rewizje, najstarsze pierwsze. */
    public static function pendingQueue(): array
    {
        return DB::fetchAll(
            "SELECT l.id, l.title, 'new' AS kind, l.submitted_at, u.email FROM listings l JOIN users u ON u.id = l.user_id WHERE l.status = 'pending'
             UNION ALL
             SELECT l.id, l.title, 'revision', r.submitted_at, u.email FROM listing_revisions r JOIN listings l ON l.id = r.listing_id
               JOIN users u ON u.id = r.user_id WHERE r.status = 'pending'
             ORDER BY submitted_at"
        );
    }

    public static function openRevision(int $listingId): ?array
    {
        return DB::fetch("SELECT * FROM listing_revisions WHERE listing_id = ? AND status IN ('draft', 'pending') ORDER BY id DESC LIMIT 1", [$listingId]);
    }

    /** Usunięcie szkicu (oferta nigdy nieopublikowana) razem z plikami. */
    public static function deleteDraft(array $listing): bool
    {
        if (!in_array($listing['status'], ['draft', 'rejected'], true) || $listing['published_at'] !== null) {
            return false;
        }
        MediaService::deleteAllFiles((int) $listing['id']);
        DB::query('DELETE FROM listings WHERE id = ?', [$listing['id']]);
        return true;
    }

    /** Pola o kluczach „region” i „city” zasilają kolumny listings (filtry, SEO) — umowa presetu. */
    private static function syncLocation(int $id, array $values, array $fields): void
    {
        // Kolumny region/city są pokazywane wszystkim (kafelki, nagłówek oferty) — kopiujemy je tylko,
        // gdy pole jest publiczne. Inaczej wartość zostaje wyłącznie w polu o swoim poziomie dostępu.
        $public = static fn(string $k) => isset($fields[$k], $values[$k]) && (int) $fields[$k]['access_level'] === 1;
        $update = [];
        if (isset($fields['region'])) {
            $update['region'] = $public('region') ? (string) $values['region'] : null;
        }
        if (isset($fields['city'])) {
            $update['city'] = $public('city') ? mb_substr((string) $values['city'], 0, 120) : null;
        }
        if ($update !== []) {
            DB::update('listings', $update, ['id' => $id]);
        }
    }

    private static function notifyModerators(int $listingId, string $kind): void
    {
        $listing = self::find($listingId);
        $base = rtrim((string) config('app.url'), '/');
        foreach (DB::fetchAll("SELECT email FROM users WHERE role IN ('admin', 'moderator') AND status = 'active'") as $m) {
            Mailer::queue($m['email'], 'moderation-pending', [
                'title' => $listing['title'],
                'kind' => $kind,
                'url' => $base . url('/moje-oferty/' . $listingId . '/podglad'),
                'id' => $listingId,
            ]);
        }
    }

    private static function notifyAuthor(array $listing, string $template, ?string $note, string $kind): void
    {
        $author = DB::fetch('SELECT email, first_name FROM users WHERE id = ?', [$listing['user_id']]);
        if ($author === null) {
            return;
        }
        $base = rtrim((string) config('app.url'), '/');
        Mailer::queue($author['email'], $template, [
            'name' => $author['first_name'],
            'title' => $listing['title'],
            'note' => $note,
            'kind' => $kind,
            'url' => $base . url('/moje-oferty/' . $listing['id'] . '/edycja'),
            'listingTerm' => __('term.listing'),
        ]);
    }
}
