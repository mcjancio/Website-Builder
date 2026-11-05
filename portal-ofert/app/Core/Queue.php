<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Kolejka zadań w tabeli `jobs`, obsługiwana przez bin/worker.php (cron co minutę).
 * E-maile, SMS-y, GetResponse, AI — wszystko idzie tędy, żeby awaria zewnętrznej
 * usługi nie blokowała użytkownika. Nieudane zadanie wraca z opóźnieniem 1/5/15/60 min.
 */
final class Queue
{
    /** Opóźnienia ponowień w sekundach (indeks = numer nieudanej próby - 1). */
    public const BACKOFF = [60, 300, 900, 3600];

    /** Zadanie zarezerwowane dłużej niż tyle sekund uznajemy za porzucone (worker padł). */
    public const STALE_AFTER = 600;

    public static function push(string $type, array $payload = [], int $delaySeconds = 0, int $maxAttempts = 5): int
    {
        DB::query(
            'INSERT INTO jobs (type, payload, max_attempts, available_at)
             VALUES (?, ?, ?, UTC_TIMESTAMP() + INTERVAL ? SECOND)',
            [$type, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $maxAttempts, $delaySeconds]
        );
        return (int) DB::pdo()->lastInsertId();
    }

    /**
     * Pobiera jedno zadanie gotowe do wykonania i oznacza je jako zarezerwowane.
     * SKIP LOCKED pozwala bezpiecznie uruchomić kilka workerów naraz.
     *
     * @return array{id:int,type:string,payload:array,attempts:int,max_attempts:int}|null
     */
    public static function reserve(): ?array
    {
        return DB::transaction(static function (): ?array {
            $job = DB::fetch(
                'SELECT id, type, payload, attempts, max_attempts FROM jobs
                 WHERE done_at IS NULL
                   AND attempts < max_attempts
                   AND available_at <= UTC_TIMESTAMP()
                   AND (reserved_at IS NULL OR reserved_at < UTC_TIMESTAMP() - INTERVAL ? SECOND)
                 ORDER BY available_at, id
                 LIMIT 1
                 FOR UPDATE SKIP LOCKED',
                [self::STALE_AFTER]
            );
            if ($job === null) {
                return null;
            }
            DB::query('UPDATE jobs SET reserved_at = UTC_TIMESTAMP(), attempts = attempts + 1 WHERE id = ?', [$job['id']]);
            return [
                'id' => (int) $job['id'],
                'type' => (string) $job['type'],
                'payload' => json_decode((string) $job['payload'], true) ?: [],
                'attempts' => (int) $job['attempts'] + 1,
                'max_attempts' => (int) $job['max_attempts'],
            ];
        });
    }

    public static function complete(int $id): void
    {
        DB::query('UPDATE jobs SET done_at = UTC_TIMESTAMP(), reserved_at = NULL, last_error = NULL WHERE id = ?', [$id]);
    }

    /** Zwraca zadanie do kolejki z opóźnieniem albo (po ostatniej próbie) zostawia jako nieudane. */
    public static function fail(int $id, string $error, int $attempts): void
    {
        $delay = self::BACKOFF[min($attempts - 1, count(self::BACKOFF) - 1)] ?? 3600;
        DB::query(
            'UPDATE jobs SET reserved_at = NULL, last_error = ?, available_at = UTC_TIMESTAMP() + INTERVAL ? SECOND WHERE id = ?',
            [mb_substr($error, 0, 5000), $delay, $id]
        );
    }

    public static function pendingCount(): int
    {
        return (int) DB::value('SELECT COUNT(*) FROM jobs WHERE done_at IS NULL AND attempts < max_attempts');
    }

    /** Zadania, które wyczerpały próby — widoczne w /health i w panelu admina. */
    /** Zadania, które wyczerpały próby (opcjonalnie: tylko od podanego momentu — do dziennego raportu). */
    public static function failedCount(?int $since = null): int
    {
        if ($since !== null) {
            return (int) DB::value('SELECT COUNT(*) FROM jobs WHERE done_at IS NULL AND attempts >= max_attempts AND reserved_at >= ?', [gmdate('Y-m-d H:i:s', $since)]);
        }
        return (int) DB::value('SELECT COUNT(*) FROM jobs WHERE done_at IS NULL AND attempts >= max_attempts');
    }

    /** Najstarsze oczekujące zadanie (sekundy) — gdy rośnie, cron workera nie działa. */
    public static function oldestPendingAge(): int
    {
        $age = DB::value(
            'SELECT TIMESTAMPDIFF(SECOND, MIN(available_at), UTC_TIMESTAMP()) FROM jobs
             WHERE done_at IS NULL AND attempts < max_attempts AND available_at <= UTC_TIMESTAMP()'
        );
        return max(0, (int) ($age ?? 0));
    }

    /** Sprzątanie: wykonane zadania starsze niż $days dni. */
    public static function purgeDone(int $days = 30): int
    {
        return DB::query(
            'DELETE FROM jobs WHERE done_at IS NOT NULL AND done_at < UTC_TIMESTAMP() - INTERVAL ? DAY',
            [$days]
        )->rowCount();
    }
}
