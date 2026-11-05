<?php

declare(strict_types=1);

/**
 * Worker kolejki zadań (e-maile, SMS, integracje). Uruchamiany z crona co minutę:
 *
 *   * * * * *  /usr/local/bin/php /home/KONTO/portal/bin/worker.php >/dev/null 2>&1
 *
 * Opcje:
 *   --max-seconds=50   ile maksymalnie pracować w jednym uruchomieniu (domyślnie 50 — przed kolejnym cronem)
 *   --max-jobs=200     limit zadań na uruchomienie
 *   --ping             dodaj zadanie testowe system.ping (sprawdzenie, czy cron działa)
 *   --verbose          wypisuj przebieg
 *
 * Blokada pliku sprawia, że dwa workery nie działają jednocześnie, gdy jedno uruchomienie się przeciągnie.
 */

require __DIR__ . '/_cli.php';

use App\Core\DB;
use App\Core\Logger;
use App\Core\Queue;
use App\Core\RateLimiter;

[$opts] = cli_args($argv);
$verbose = isset($opts['verbose']);
$maxSeconds = max(1, (int) ($opts['max-seconds'] ?? 50));
$maxJobs = max(1, (int) ($opts['max-jobs'] ?? 200));

if (isset($opts['ping'])) {
    $id = Queue::push('system.ping', ['at' => gmdate('c')]);
    ok("Dodano zadanie system.ping (#{$id}).");
}

$lock = fopen(APP_ROOT . '/storage/cache/worker.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    $verbose && out('Inny worker już działa — kończę.');
    exit(0);
}

/** @var array<string,callable> $handlers */
$handlers = require APP_ROOT . '/app/jobs.php';
$started = microtime(true);
$processed = 0;
$failed = 0;

while ($processed < $maxJobs && (microtime(true) - $started) < $maxSeconds) {
    $job = Queue::reserve();
    if ($job === null) {
        break;
    }
    $processed++;
    try {
        $handler = $handlers[$job['type']] ?? null;
        if ($handler === null) {
            throw new \RuntimeException("Brak obsługi zadania typu \"{$job['type']}\" (app/jobs.php).");
        }
        $handler($job['payload']);
        Queue::complete($job['id']);
        $verbose && ok("#{$job['id']} {$job['type']}");
    } catch (\Throwable $e) {
        $failed++;
        Queue::fail($job['id'], $e->getMessage(), $job['attempts']);
        $last = $job['attempts'] >= $job['max_attempts'];
        $context = ['job' => $job['id'], 'type' => $job['type'], 'attempt' => $job['attempts'], 'error' => $e->getMessage()];
        $last ? Logger::error('Zadanie nieudane — wyczerpano próby', $context) : Logger::warning('Zadanie nieudane — ponowienie', $context);
        $verbose && warn("#{$job['id']} {$job['type']}: {$e->getMessage()}" . ($last ? ' (ostatnia próba)' : ''));
    }
}

// Sprzątanie raz na godzinę (pierwsze uruchomienie w danej godzinie).
$stamp = APP_ROOT . '/storage/cache/worker-housekeeping';
if (!is_file($stamp) || filemtime($stamp) < time() - 3600) {
    touch($stamp);
    $purged = [
        'jobs' => Queue::purgeDone(30),
        'rate_limits' => RateLimiter::purge(),
        'tokens' => DB::query('DELETE FROM user_tokens WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 7 DAY')->rowCount(),
        'sms_codes' => DB::query('DELETE FROM sms_codes WHERE created_at < UTC_TIMESTAMP() - INTERVAL 30 DAY')->rowCount(),
        'logs' => Logger::purgeOlderThan(30),
        'sessions' => purge_sessions(APP_ROOT . '/storage/sessions', (int) config('session.lifetime', 7200)),
    ];
    Logger::info('Worker: sprzątanie', $purged);
    $verbose && out('Sprzątanie: ' . json_encode($purged));
}

$verbose && out(sprintf('Gotowe: %d zadań, w tym %d nieudanych, %.1f s.', $processed, $failed, microtime(true) - $started));
flock($lock, LOCK_UN);

function purge_sessions(string $dir, int $lifetime): int
{
    $n = 0;
    foreach (glob($dir . '/sess_*') ?: [] as $file) {
        if (filemtime($file) < time() - $lifetime && @unlink($file)) {
            $n++;
        }
    }
    return $n;
}
