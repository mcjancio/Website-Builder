<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\DB;
use App\Core\Migrator;
use App\Core\Queue;
use App\Core\Request;
use App\Core\Response;

/**
 * /health — dla monitoringu (UptimeRobot / Better Stack): 200 gdy wszystko ok, 503 gdy nie.
 * Nie zdradza wersji ani konfiguracji. Szczegóły kolejki tylko z tokenem
 * (?token=… z config.php: health.token), żeby obcy nie oglądali ruchu portalu.
 */
final class HealthController
{
    /** Kolejka uznawana za „stojącą”, gdy najstarsze gotowe zadanie czeka dłużej (cron workera nie działa). */
    private const QUEUE_STALL_SECONDS = 900;

    public function show(Request $request): Response
    {
        $checks = [];
        $ok = true;

        try {
            DB::value('SELECT 1');
            $checks['db'] = 'ok';
        } catch (\Throwable) {
            $checks['db'] = 'error';
            $ok = false;
        }

        if ($checks['db'] === 'ok') {
            try {
                $pending = (new Migrator(APP_ROOT . '/migrations'))->pending();
                $checks['migrations'] = $pending === [] ? 'ok' : 'pending';
                $ok = $ok && $pending === [];
            } catch (\Throwable) {
                $checks['migrations'] = 'error';
                $ok = false;
            }
        }

        // Tryb testowy kodów na produkcji = nikt nie dostanie kodu weryfikacyjnego.
        if (Config::get('otp.test_mode', false) && Config::get('app.env') === 'production') {
            $checks['otp'] = 'test_mode';
            $ok = false;
        }

        $checks['storage'] = $this->storageWritable() ? 'ok' : 'error';
        $ok = $ok && $checks['storage'] === 'ok';

        $details = [];
        if ($checks['db'] === 'ok') {
            try {
                $age = Queue::oldestPendingAge();
                $checks['queue'] = $age > self::QUEUE_STALL_SECONDS ? 'stalled' : 'ok';
                $ok = $ok && $checks['queue'] === 'ok';
                $details = ['pending' => Queue::pendingCount(), 'failed' => Queue::failedCount(), 'oldest_pending_s' => $age];
            } catch (\Throwable) {
                $checks['queue'] = 'error';
                $ok = false;
            }
        }

        // Informacyjnie (nie wpływa na status — alarm idzie w dziennym raporcie e-mail): wiek kopii zapasowej.
        $latest = \App\Core\Backup::latest();
        $checks['backup'] = $latest === null ? 'none' : (time() - $latest > \App\Core\ErrorReport::BACKUP_MAX_AGE_HOURS * 3600 ? 'stale' : 'ok');

        // Informacyjnie (nie wpływa na status): trwa tryb kampanii — wdrożenia wstrzymane.
        if (\App\Core\CampaignMode::until() !== null) {
            $checks['campaign'] = 'on';
        }

        $body = ['status' => $ok ? 'ok' : 'error', 'checks' => $checks, 'time' => gmdate('c')];
        $token = (string) Config::get('health.token', '');
        if ($token !== '' && hash_equals($token, (string) $request->str('token'))) {
            $body['queue'] = $details;
        }

        return Response::json($body, $ok ? 200 : 503)->withHeader('Cache-Control', 'no-store');
    }

    private function storageWritable(): bool
    {
        foreach (['logs', 'cache', 'uploads'] as $dir) {
            $path = APP_ROOT . '/storage/' . $dir;
            if (!is_dir($path) || !is_writable($path)) {
                return false;
            }
        }
        $probe = APP_ROOT . '/storage/cache/.health-' . bin2hex(random_bytes(4));
        $written = @file_put_contents($probe, '1') === 1;
        @unlink($probe);
        return $written;
    }
}
