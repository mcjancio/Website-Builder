<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Dzienny raport problemów dla administratorów — wysyłany TYLKO, gdy jest o czym pisać:
 * błędy z logów (zgrupowane po treści), nieudane zadania kolejki, brak świeżej kopii zapasowej.
 * Wołany przez worker raz na dobę (oraz ręcznie: bin/error-report.php).
 */
final class ErrorReport
{
    public const BACKUP_MAX_AGE_HOURS = 36;

    /**
     * @return array{errors:array<int,array{message:string,count:int,last:string,context:string}>,failed_jobs:int,backup_age_h:?int,backup_stale:bool}
     */
    public static function collect(int $since): array
    {
        $groups = [];
        foreach (glob(APP_ROOT . '/storage/logs/app-*.log') ?: [] as $file) {
            if (filemtime($file) < $since) {
                continue;
            }
            $fh = fopen($file, 'rb');
            while ($fh && ($line = fgets($fh)) !== false) {
                $row = json_decode($line, true);
                if (!is_array($row) || !in_array($row['level'] ?? '', ['error', 'critical'], true)) {
                    continue;
                }
                $t = strtotime((string) ($row['time'] ?? ''));
                if ($t === false || $t < $since) {
                    continue;
                }
                $msg = mb_substr((string) ($row['message'] ?? ''), 0, 300);
                $g = $groups[$msg] ?? ['message' => $msg, 'count' => 0, 'last' => '', 'context' => ''];
                $g['count']++;
                if ((string) $row['time'] >= $g['last']) {
                    $g['last'] = (string) $row['time'];
                    $g['context'] = mb_substr((string) json_encode($row['context'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 500);
                }
                $groups[$msg] = $g;
            }
            $fh && fclose($fh);
        }
        usort($groups, static fn($a, $b) => $b['count'] <=> $a['count']);

        $latest = Backup::latest();
        $age = $latest !== null ? (int) floor((time() - $latest) / 3600) : null;
        return [
            'errors' => array_slice($groups, 0, 30),
            'failed_jobs' => Queue::failedCount(),
            'backup_age_h' => $age,
            'backup_stale' => $age === null || $age > self::BACKUP_MAX_AGE_HOURS,
        ];
    }

    public static function hasProblems(array $r): bool
    {
        return $r['errors'] !== [] || $r['failed_jobs'] > 0 || $r['backup_stale'];
    }

    /** Kolejkuje e-mail do administratorów, gdy są problemy. Zwraca liczbę adresatów. */
    public static function send(int $since): int
    {
        $r = self::collect($since);
        if (!self::hasProblems($r)) {
            return 0;
        }
        $admins = array_column(DB::fetchAll("SELECT email FROM users WHERE role = 'admin' AND status = 'active'"), 'email');
        foreach ($admins as $email) {
            Mailer::queue($email, 'error-report', $r + ['portal' => (string) config('app.url')]);
        }
        return count($admins);
    }
}
