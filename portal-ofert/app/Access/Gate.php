<?php

declare(strict_types=1);

namespace App\Access;

use App\Core\DB;
use App\Http\Middleware\RequireVerifiedEmail;

/**
 * JEDYNE miejsce, które decyduje, kto co widzi (KONCEPCJA §3).
 *   L1 — publiczne: każdy, gdy oferta jest opublikowana
 *   L2 — zalogowani z aktywnym kontem (potwierdzony e-mail lub okres łaski)
 *   L3 — po nadaniu dostępu do konkretnej oferty (access_grants: granted, niewygasły)
 * Autor oferty, admin i moderator widzą wszystko — także nieopublikowane.
 * Widoki i kontrolery NIGDY nie sprawdzają tego same.
 */
final class Gate
{
    public const PUBLIC = 1;
    public const MEMBERS = 2;
    public const GRANTED = 3;

    /** @var array<string,bool> */
    private static array $grantCache = [];

    public static function isStaff(?array $user): bool
    {
        return $user !== null && in_array($user['role'] ?? '', ['admin', 'moderator'], true);
    }

    public static function isOwner(?array $user, array $listing): bool
    {
        return $user !== null && (int) $user['id'] === (int) $listing['user_id'];
    }

    /** Czy użytkownik może w ogóle otworzyć ofertę (status). */
    public static function canView(?array $user, array $listing): bool
    {
        if ($listing['status'] === 'published' || in_array($listing['status'], ['sold', 'paused'], true)) {
            return true;
        }
        return self::isOwner($user, $listing) || self::isStaff($user);
    }

    public static function canEdit(?array $user, array $listing): bool
    {
        return self::isOwner($user, $listing) && !in_array($listing['status'], ['archived'], true);
    }

    /** Czy użytkownik widzi treść danego poziomu w tej ofercie. */
    public static function canSee(?array $user, array $listing, int $level): bool
    {
        if (!self::canView($user, $listing)) {
            return false;
        }
        if (self::isOwner($user, $listing) || self::isStaff($user)) {
            return true;
        }
        return match (true) {
            $level <= self::PUBLIC => true,
            $level === self::MEMBERS => $user !== null && RequireVerifiedEmail::isVerifiedOrInGrace($user),
            default => $user !== null && self::hasGrant((int) $user['id'], (int) $listing['id']),
        };
    }

    /** Najwyższy poziom dostępny dla użytkownika (do filtrowania pól i plików jednym porównaniem). */
    public static function level(?array $user, array $listing): int
    {
        foreach ([self::GRANTED, self::MEMBERS, self::PUBLIC] as $level) {
            if (self::canSee($user, $listing, $level)) {
                return $level;
            }
        }
        return 0;
    }

    public static function hasGrant(int $userId, int $listingId): bool
    {
        $key = $userId . ':' . $listingId;
        return self::$grantCache[$key] ??= DB::value(
            "SELECT 1 FROM access_grants WHERE user_id = ? AND listing_id = ? AND status = 'granted'
               AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())",
            [$userId, $listingId]
        ) !== null;
    }

    public static function flush(): void
    {
        self::$grantCache = [];
    }
}
