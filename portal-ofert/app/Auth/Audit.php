<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\DB;
use App\Core\Logger;
use App\Core\Request;

/** Dziennik zdarzeń bezpieczeństwa (tabela audit_log). Błąd zapisu nie przerywa akcji użytkownika. */
final class Audit
{
    public static function log(string $action, ?string $entityType = null, ?int $entityId = null, array $details = [], ?Request $request = null, ?int $userId = null): void
    {
        try {
            DB::insert('audit_log', [
                'user_id' => $userId ?? Auth::id() ?? ($entityType === 'user' ? $entityId : null),
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'details' => $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE),
                'ip' => $request ? (inet_pton($request->ip()) ?: null) : null,
            ]);
        } catch (\Throwable $e) {
            Logger::error('Nie zapisano audit_log', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }
}
