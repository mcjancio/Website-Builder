<?php

declare(strict_types=1);

namespace App\Listings;

use App\Core\DB;
use App\Core\Logger;
use App\Core\Request;

/**
 * Statystyki ofert: dzienne agregaty (bez zapisywania każdego wejścia) + którzy zalogowani
 * użytkownicy oglądali ofertę (cenne dla autora: kto jest zainteresowany).
 * Boty, autor i moderacja nie są liczeni. Błąd statystyk nigdy nie blokuje strony.
 */
final class Stats
{
    private const BOTS = '/bot|crawl|spider|slurp|facebookexternalhit|preview|monitor|uptime|headless|lighthouse|curl|wget|python|httpclient/i';

    public static function recordView(array $listing, ?array $user, Request $request): void
    {
        if (preg_match(self::BOTS, $request->userAgent()) || $request->userAgent() === '') {
            return;
        }
        if ($user !== null && ((int) $user['id'] === (int) $listing['user_id'] || in_array($user['role'], ['admin', 'moderator'], true))) {
            return;
        }
        try {
            self::bump((int) $listing['id'], $user === null ? 'views_public' : 'views_member');
            if ($user !== null) {
                DB::query(
                    'INSERT INTO listing_member_views (listing_id, user_id) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE views = views + 1, last_at = UTC_TIMESTAMP()',
                    [$listing['id'], $user['id']]
                );
            }
        } catch (\Throwable $e) {
            Logger::warning('Statystyki: błąd zapisu', ['error' => $e->getMessage()]);
        }
    }

    /** @param 'views_public'|'views_member'|'inquiries' $column */
    public static function bump(int $listingId, string $column): void
    {
        if (!in_array($column, ['views_public', 'views_member', 'inquiries'], true)) {
            return;
        }
        DB::query(
            "INSERT INTO listing_stats (listing_id, day, {$column}) VALUES (?, UTC_DATE(), 1)
             ON DUPLICATE KEY UPDATE {$column} = {$column} + 1",
            [$listingId]
        );
    }
}
