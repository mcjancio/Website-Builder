<?php

declare(strict_types=1);

namespace App\Listings;

use App\Core\DB;

/** Obserwowane oferty użytkownika. */
final class Favorites
{
    /** @return bool stan po przełączeniu (true = obserwowana) */
    public static function toggle(int $userId, int $listingId): bool
    {
        if (self::has($userId, $listingId)) {
            DB::query('DELETE FROM favorites WHERE user_id = ? AND listing_id = ?', [$userId, $listingId]);
            return false;
        }
        DB::query('INSERT IGNORE INTO favorites (user_id, listing_id) VALUES (?, ?)', [$userId, $listingId]);
        return true;
    }

    public static function has(int $userId, int $listingId): bool
    {
        return DB::value('SELECT 1 FROM favorites WHERE user_id = ? AND listing_id = ?', [$userId, $listingId]) !== null;
    }

    /** Obserwowane, które użytkownik nadal może zobaczyć (opublikowane lub zakończone). */
    public static function forUser(int $userId): array
    {
        return DB::fetchAll(
            "SELECT l.id, l.slug, l.title, l.short_desc, l.status, l.city, c.name AS category_name, f.created_at AS added_at,
                    (SELECT m.id FROM media m WHERE m.listing_id = l.id AND m.access_level = 1 AND m.state IN ('live','pending_remove') ORDER BY m.is_cover DESC, m.sort_order LIMIT 1) AS cover_id
             FROM favorites f JOIN listings l ON l.id = f.listing_id JOIN categories c ON c.id = l.category_id
             WHERE f.user_id = ? AND l.status IN ('published', 'sold') ORDER BY f.created_at DESC",
            [$userId]
        );
    }
}
