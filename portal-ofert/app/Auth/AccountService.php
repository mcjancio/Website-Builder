<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\DB;
use App\Core\Request;
use App\Listings\MediaService;

/** Operacje na koncie: profil, hasło, urządzenia, usunięcie konta (RODO). */
final class AccountService
{
    public static function changePassword(int $userId, string $new, ?Request $request = null): void
    {
        DB::update('users', ['password_hash' => Auth::hashPassword($new)], ['id' => $userId]);
        Tokens::revokeAll($userId, 'remember');
        Tokens::revokeAll($userId, 'password_reset');
        Audit::log('password.change', 'user', $userId, [], $request, $userId);
    }

    /** Urządzenia z aktywnym „Zapamiętaj mnie”. */
    public static function devices(int $userId): array
    {
        $rows = DB::fetchAll(
            "SELECT selector, user_agent, created_at, expires_at FROM user_tokens
             WHERE user_id = ? AND type = 'remember' AND expires_at > UTC_TIMESTAMP() ORDER BY created_at DESC",
            [$userId]
        );
        foreach ($rows as &$r) {
            $r['label'] = self::deviceLabel((string) $r['user_agent']);
        }
        return $rows;
    }

    /** Czytelny opis urządzenia z User-Agenta, np. „Chrome · Windows”. */
    public static function deviceLabel(string $ua): string
    {
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') || str_contains($ua, 'Chromium/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => __('security.device_unknown'),
        };
        $os = match (true) {
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };
        return $os ? $browser . ' · ' . $os : $browser;
    }

    public static function logoutEverywhere(int $userId, ?Request $request = null): void
    {
        Tokens::revokeAll($userId, 'remember');
        Audit::log('devices.revoke_all', 'user', $userId, [], $request, $userId);
    }

    /**
     * Usunięcie konta na żądanie (RODO art. 17): dane osobowe są anonimizowane, szkice usuwane,
     * opublikowane oferty archiwizowane. Rejestr zgód zostaje (dowód rozliczalności), ale bez e-maila.
     */
    public static function delete(int $userId, ?Request $request = null): void
    {
        DB::transaction(static function () use ($userId): void {
            foreach (DB::fetchAll('SELECT * FROM listings WHERE user_id = ?', [$userId]) as $l) {
                if ($l['published_at'] === null) {
                    MediaService::deleteAllFiles((int) $l['id']);
                    DB::query('DELETE FROM listings WHERE id = ?', [$l['id']]);
                } else {
                    DB::query("UPDATE listings SET status = 'archived' WHERE id = ?", [$l['id']]);
                }
            }
            $anon = 'usuniety-' . $userId . '@usuniete.invalid';
            DB::update('users', [
                'email' => $anon,
                'password_hash' => Auth::hashPassword(bin2hex(random_bytes(16))),
                'first_name' => 'Konto',
                'last_name' => 'usunięte',
                'phone_e164' => '+0' . $userId,
                'status' => 'deleted',
                'totp_secret' => null,
                'totp_enabled_at' => null,
                'recovery_codes' => null,
                'preferences' => null,
                'company_id' => null,
            ], ['id' => $userId]);
            DB::query("UPDATE inquiries SET name = 'Usunięte', email = ?, phone_e164 = NULL, message = '[treść usunięta na żądanie użytkownika]' WHERE user_id = ?", [$anon, $userId]);
            DB::query('UPDATE consents SET email = ? WHERE user_id = ?', [$anon, $userId]);
            foreach (['user_tokens', 'favorites', 'saved_searches', 'access_grants', 'listing_member_views'] as $t) {
                DB::query("DELETE FROM {$t} WHERE user_id = ?", [$userId]);
            }
        });
        Audit::log('user.deleted', 'user', $userId, [], $request, $userId);
    }
}
