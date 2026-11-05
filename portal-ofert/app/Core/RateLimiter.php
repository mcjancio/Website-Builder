<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Limit zdarzeń w oknie czasowym (tabela rate_limits), np.:
 *   if (!RateLimiter::attempt("login:ip:{$ip}", 10, 900)) { … 429 … }
 * Jeden atomowy INSERT … ON DUPLICATE KEY UPDATE — bez wyścigów między żądaniami.
 */
final class RateLimiter
{
    /** Rejestruje próbę. Zwraca false, gdy limit w bieżącym oknie został przekroczony. */
    public static function attempt(string $bucket, int $max, int $windowSeconds): bool
    {
        return self::hit($bucket, $windowSeconds) <= $max;
    }

    /** Rejestruje próbę i zwraca liczbę prób w bieżącym oknie. */
    public static function hit(string $bucket, int $windowSeconds): int
    {
        $bucket = self::key($bucket);
        // Kolejność przypisań ma znaczenie: `hits` liczone na starym window_start.
        DB::query(
            'INSERT INTO rate_limits (bucket, hits, window_start) VALUES (?, 1, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
               hits = IF(window_start <= UTC_TIMESTAMP() - INTERVAL ? SECOND, 1, hits + 1),
               window_start = IF(window_start <= UTC_TIMESTAMP() - INTERVAL ? SECOND, UTC_TIMESTAMP(), window_start)',
            [$bucket, $windowSeconds, $windowSeconds]
        );
        return (int) DB::value('SELECT hits FROM rate_limits WHERE bucket = ?', [$bucket]);
    }

    /** Ile prób już jest (bez rejestrowania nowej). */
    public static function count(string $bucket, int $windowSeconds): int
    {
        return (int) (DB::value(
            'SELECT hits FROM rate_limits WHERE bucket = ? AND window_start > UTC_TIMESTAMP() - INTERVAL ? SECOND',
            [self::key($bucket), $windowSeconds]
        ) ?? 0);
    }

    /** Sekundy do końca okna (do komunikatu „spróbuj za X min”). */
    public static function retryAfter(string $bucket, int $windowSeconds): int
    {
        $left = DB::value(
            'SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), window_start + INTERVAL ? SECOND) FROM rate_limits WHERE bucket = ?',
            [$windowSeconds, self::key($bucket)]
        );
        return max(0, (int) ($left ?? 0));
    }

    public static function clear(string $bucket): void
    {
        DB::query('DELETE FROM rate_limits WHERE bucket = ?', [self::key($bucket)]);
    }

    /** Sprzątanie przez worker: wpisy starsze niż doba. */
    public static function purge(int $olderThanSeconds = 86400): int
    {
        return DB::query(
            'DELETE FROM rate_limits WHERE window_start < UTC_TIMESTAMP() - INTERVAL ? SECOND',
            [$olderThanSeconds]
        )->rowCount();
    }

    /** Kolumna ma 120 znaków — długie klucze (np. e-mail) skracamy hashem. */
    private static function key(string $bucket): string
    {
        return strlen($bucket) <= 120 ? $bucket : substr($bucket, 0, 55) . ':' . hash('sha256', $bucket);
    }
}
