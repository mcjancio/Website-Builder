<?php

declare(strict_types=1);

namespace App\Listings;

use App\Core\DB;
use App\Core\Logger;
use App\Core\Mailer;

/**
 * Zapisane wyszukiwania z powiadomieniem e-mail o NOWYCH ofertach (codziennie / co tydzień).
 * Zapisujemy te same parametry co w adresie /oferty — alert pokazuje dokładnie to, co lista.
 */
final class SavedSearches
{
    public const MAX_PER_USER = 20;
    private const PARAM_SKIP = ['strona', 'sort', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'fbclid', 'gclid'];

    /** @return int|null ID albo null przy przekroczonym limicie */
    public static function save(int $userId, array $query, string $name, string $frequency = 'weekly'): ?int
    {
        if ((int) DB::value('SELECT COUNT(*) FROM saved_searches WHERE user_id = ?', [$userId]) >= self::MAX_PER_USER) {
            return null;
        }
        $query = self::clean($query);
        return DB::insert('saved_searches', [
            'user_id' => $userId,
            'name' => mb_substr(trim($name) !== '' ? trim($name) : __('saved.default_name'), 0, 150),
            'query' => json_encode($query, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'frequency' => in_array($frequency, ['off', 'daily', 'weekly'], true) ? $frequency : 'weekly',
            'last_sent_at' => gmdate('Y-m-d H:i:s'), // alert tylko o ofertach NOWSZYCH niż moment zapisu
        ]);
    }

    public static function forUser(int $userId): array
    {
        return DB::fetchAll('SELECT * FROM saved_searches WHERE user_id = ? ORDER BY created_at DESC', [$userId]);
    }

    public static function update(int $userId, int $id, string $frequency): void
    {
        if (in_array($frequency, ['off', 'daily', 'weekly'], true)) {
            DB::query('UPDATE saved_searches SET frequency = ? WHERE id = ? AND user_id = ?', [$frequency, $id, $userId]);
        }
    }

    public static function delete(int $userId, int $id): void
    {
        DB::query('DELETE FROM saved_searches WHERE id = ? AND user_id = ?', [$id, $userId]);
    }

    /** Adres listy dla zapisanego wyszukiwania. */
    public static function url(array $row): string
    {
        return url('/oferty', json_decode((string) $row['query'], true) ?: []);
    }

    /** Krótki opis kryteriów, np. „fraza: hotel · Kategoria: Mieszkaniowe · Województwo: mazowieckie”. */
    public static function summary(array $row): string
    {
        $query = json_decode((string) $row['query'], true) ?: [];
        $fields = Fields::all();
        $parts = [];
        foreach ($query as $k => $v) {
            $list = array_map('strval', (array) $v);
            if ($k === 'q') {
                $parts[] = __('saved.phrase', ['q' => implode(' ', $list)]);
            } elseif ($k === 'kategoria') {
                $parts[] = __('saved.category') . ': ' . ((string) \App\Core\DB::value('SELECT name FROM categories WHERE slug = ?', [$list[0] ?? '']) ?: $list[0] ?? '');
            } elseif (preg_match('/^(.+)_(od|do)$/', (string) $k, $m) && isset($fields[$m[1]])) {
                $parts[] = $fields[$m[1]]['label'] . ' ' . __('listings.' . ($m[2] === 'od' ? 'from' : 'to')) . ' ' . ($list[0] ?? '');
            } elseif (isset($fields[$k])) {
                $f = $fields[$k];
                $labels = $f['filter_ui'] === 'toggle' ? [__('saved.yes')] : array_map(static fn($o) => $f['options'][$o]['label'] ?? $o, $list);
                $parts[] = $f['label'] . ': ' . implode(', ', $labels);
            }
        }
        return implode(' · ', $parts);
    }

    /**
     * Wysyłka alertów (wołane przez worker z crona). Zwraca liczbę wysłanych e-maili.
     * Dzienne: co 24 h, tygodniowe: co 7 dni; tylko gdy są nowe oferty.
     */
    public static function sendDue(int $limit = 200): int
    {
        $due = DB::fetchAll(
            "SELECT s.*, u.email, u.first_name, u.email_verified_at, u.created_at AS user_created, u.role
             FROM saved_searches s JOIN users u ON u.id = s.user_id AND u.status = 'active'
             WHERE (s.frequency = 'daily' AND s.last_sent_at <= UTC_TIMESTAMP() - INTERVAL 1 DAY)
                OR (s.frequency = 'weekly' AND s.last_sent_at <= UTC_TIMESTAMP() - INTERVAL 7 DAY)
             ORDER BY s.last_sent_at LIMIT ?",
            [$limit]
        );
        $sent = 0;
        $base = rtrim((string) config('app.url'), '/');
        foreach ($due as $s) {
            $user = ['id' => $s['user_id'], 'email_verified_at' => $s['email_verified_at'], 'created_at' => $s['user_created'], 'role' => $s['role']];
            $level = ListingQuery::levelFor($user);
            $query = json_decode((string) $s['query'], true) ?: [];
            try {
                $result = ListingQuery::search($query, $level, 10, (string) $s['last_sent_at']);
            } catch (\Throwable $e) {
                Logger::warning('Alert: błąd wyszukiwania', ['saved_search' => $s['id'], 'error' => $e->getMessage()]);
                continue;
            }
            DB::query('UPDATE saved_searches SET last_sent_at = UTC_TIMESTAMP() WHERE id = ?', [$s['id']]);
            if ($result['total'] === 0) {
                continue;
            }
            Mailer::queue((string) $s['email'], 'saved-search-alert', [
                'name' => $s['first_name'],
                'search' => $s['name'],
                'total' => $result['total'],
                'items' => array_map(static fn($i) => ['title' => $i['title'], 'short' => $i['short_desc'], 'url' => $base . url('/oferta/' . $i['slug'])], $result['items']),
                'url' => $base . self::url($s),
                'manage' => $base . url('/konto/wyszukiwania'),
            ]);
            $sent++;
        }
        return $sent;
    }

    /** Tylko parametry wyszukiwania (bez strony, sortowania i znaczników kampanii), wartości skalarne lub listy. */
    private static function clean(array $query): array
    {
        $out = [];
        foreach ($query as $k => $v) {
            if (!is_string($k) || in_array($k, self::PARAM_SKIP, true) || !preg_match('/^[a-z0-9_]{1,80}$/', $k)) {
                continue;
            }
            if (is_array($v)) {
                $v = array_values(array_filter(array_map(static fn($x) => is_scalar($x) ? mb_substr((string) $x, 0, 100) : null, $v), static fn($x) => $x !== null && $x !== ''));
                if ($v !== []) {
                    $out[$k] = array_slice($v, 0, 30);
                }
            } elseif (is_scalar($v) && (string) $v !== '') {
                $out[$k] = mb_substr((string) $v, 0, 100);
            }
        }
        return $out;
    }
}
