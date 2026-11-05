<?php

declare(strict_types=1);

namespace App\Access;

use App\Auth\Audit;
use App\Core\DB;
use App\Core\Mailer;
use App\Core\Request;
use App\Listings\ListingService;

/**
 * Prośby o dostęp do treści poufnych (L3) — np. po podpisaniu NDA.
 * Nadaje admin/moderator (UI: etap 5; do tego czasu bin/access.php) albo autor,
 * jeśli oferta ma l3_self_grant.
 */
final class AccessService
{
    /** @return 'requested'|'already'|'granted' */
    public static function request(int $listingId, int $userId, string $message, ?Request $request = null): string
    {
        $existing = DB::fetch('SELECT id, status FROM access_grants WHERE listing_id = ? AND user_id = ?', [$listingId, $userId]);
        if ($existing !== null && $existing['status'] === 'granted') {
            return 'granted';
        }
        if ($existing !== null && $existing['status'] === 'requested') {
            return 'already';
        }
        $message = mb_substr(trim($message), 0, 1000);
        if ($existing !== null) {
            // Wcześniej odmówiono/odebrano — nowa prośba (z nowym uzasadnieniem).
            DB::query("UPDATE access_grants SET status = 'requested', message = ?, requested_at = UTC_TIMESTAMP(), decided_at = NULL, decided_by = NULL WHERE id = ?",
                [$message, $existing['id']]);
            $id = (int) $existing['id'];
        } else {
            $id = DB::insert('access_grants', ['listing_id' => $listingId, 'user_id' => $userId, 'status' => 'requested', 'message' => $message ?: null]);
        }
        Audit::log('access.request', 'listing', $listingId, ['grant' => $id], $request, $userId);

        $listing = ListingService::find($listingId);
        $requester = DB::fetch('SELECT u.first_name, u.last_name, u.email, c.name AS company FROM users u LEFT JOIN companies c ON c.id = u.company_id WHERE u.id = ?', [$userId]);
        $to = array_column(DB::fetchAll("SELECT email FROM users WHERE role IN ('admin', 'moderator') AND status = 'active'"), 'email');
        if ((int) $listing['l3_self_grant'] === 1) {
            $to[] = (string) DB::value('SELECT email FROM users WHERE id = ?', [$listing['user_id']]);
        }
        foreach (array_unique(array_filter($to)) as $email) {
            Mailer::queue($email, 'access-request', [
                'title' => $listing['title'], 'id' => $id, 'listingId' => $listingId,
                'name' => trim($requester['first_name'] . ' ' . $requester['last_name']), 'email' => $requester['email'],
                'company' => $requester['company'], 'message' => $message,
            ]);
        }
        return 'requested';
    }

    public static function decide(int $grantId, bool $grant, ?int $by, ?string $expiresAt = null, ?Request $request = null): void
    {
        $g = DB::fetch('SELECT * FROM access_grants WHERE id = ?', [$grantId]);
        if ($g === null) {
            throw new \RuntimeException("Brak prośby #{$grantId}");
        }
        DB::query('UPDATE access_grants SET status = ?, decided_by = ?, decided_at = UTC_TIMESTAMP(), expires_at = ? WHERE id = ?',
            [$grant ? 'granted' : 'denied', $by, $expiresAt, $grantId]);
        Gate::flush();
        Audit::log($grant ? 'access.grant' : 'access.deny', 'listing', (int) $g['listing_id'], ['user' => (int) $g['user_id']], $request, $by);

        $user = DB::fetch('SELECT email, first_name FROM users WHERE id = ?', [$g['user_id']]);
        $listing = ListingService::find((int) $g['listing_id']);
        Mailer::queue($user['email'], $grant ? 'access-granted' : 'access-denied', [
            'name' => $user['first_name'], 'title' => $listing['title'],
            'url' => rtrim((string) config('app.url'), '/') . url('/oferta/' . $listing['slug']),
        ]);
    }

    public static function revoke(int $grantId, ?int $by): void
    {
        DB::query("UPDATE access_grants SET status = 'revoked', decided_by = ?, decided_at = UTC_TIMESTAMP() WHERE id = ?", [$by, $grantId]);
        Gate::flush();
        Audit::log('access.revoke', 'access_grant', $grantId, [], null, $by);
    }

    public static function statusFor(int $listingId, int $userId): ?string
    {
        return DB::value('SELECT status FROM access_grants WHERE listing_id = ? AND user_id = ?', [$listingId, $userId]);
    }

    public static function pending(): array
    {
        return DB::fetchAll(
            "SELECT g.id, g.listing_id, g.message, g.requested_at, l.title, u.email, u.first_name, u.last_name, c.name AS company
             FROM access_grants g JOIN listings l ON l.id = g.listing_id JOIN users u ON u.id = g.user_id
             LEFT JOIN companies c ON c.id = u.company_id
             WHERE g.status = 'requested' ORDER BY g.requested_at"
        );
    }
}
