<?php

declare(strict_types=1);

namespace App\Core;

/** Log do plików storage/logs/app-RRRR-MM-DD.log (jeden JSON na linię). */
final class Logger
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3, 'critical' => 4];

    private static string $dir = '';
    private static int $minLevel = 1;

    public static function init(string $dir, string $minLevel = 'info'): void
    {
        self::$dir = rtrim($dir, '/');
        self::$minLevel = self::LEVELS[$minLevel] ?? 1;
    }

    public static function debug(string $message, array $context = []): void
    {
        self::write('debug', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    public static function critical(string $message, array $context = []): void
    {
        self::write('critical', $message, $context);
    }

    /** Usuwa logi starsze niż $days dni (wywoływane przez worker). */
    public static function purgeOlderThan(int $days): int
    {
        if (self::$dir === '') {
            return 0;
        }
        $removed = 0;
        $limit = time() - $days * 86400;
        foreach (glob(self::$dir . '/app-*.log') ?: [] as $file) {
            if (filemtime($file) < $limit && @unlink($file)) {
                $removed++;
            }
        }
        return $removed;
    }

    private static function write(string $level, string $message, array $context): void
    {
        if (self::$dir === '' || self::LEVELS[$level] < self::$minLevel) {
            return;
        }
        $line = json_encode([
            'time' => gmdate('Y-m-d\TH:i:s\Z'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        $file = self::$dir . '/app-' . gmdate('Y-m-d') . '.log';
        // Brak możliwości zapisu logu nie może wyłożyć aplikacji.
        @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
