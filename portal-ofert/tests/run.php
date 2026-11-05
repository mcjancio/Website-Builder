<?php

declare(strict_types=1);

/**
 * Minimalny runner testów (bez PHPUnit — zgodnie z zasadą „bez zależności”).
 *
 *   php tests/run.php                 — testy jednostkowe
 *   PORTAL_TEST_DB=1 php tests/run.php — także integracyjne (CZYŚCI bazę z config.testing.php!)
 *
 * Konfiguracja testów: config.testing.php (wzór: tests/config.testing.example.php).
 */

$root = dirname(__DIR__);
$testConfig = $root . '/config.testing.php';
if (!is_file($testConfig)) {
    $testConfig = __DIR__ . '/config.testing.example.php';
}
putenv('PORTAL_CONFIG=' . $testConfig);

require $root . '/app/bootstrap.php';
restore_exception_handler();

final class T
{
    public static int $passed = 0;
    public static array $failures = [];
    public static string $current = '';

    public static function assert(bool $cond, string $message = ''): void
    {
        if ($cond) {
            self::$passed++;
            return;
        }
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0] ?? [];
        self::$failures[] = sprintf('%s: %s (%s:%d)', self::$current, $message ?: 'asercja nie powiodła się',
            basename($trace['file'] ?? '?'), $trace['line'] ?? 0);
    }

    public static function same(mixed $expected, mixed $actual, string $message = ''): void
    {
        $ok = $expected === $actual;
        if (!$ok) {
            $message = ($message !== '' ? $message . ' — ' : '') . 'oczekiwano ' . var_export($expected, true)
                . ', jest ' . var_export($actual, true);
        }
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0] ?? [];
        if ($ok) {
            self::$passed++;
        } else {
            self::$failures[] = sprintf('%s: %s (%s:%d)', self::$current, $message, basename($trace['file'] ?? '?'), $trace['line'] ?? 0);
        }
    }

    public static function throws(callable $fn, string $class, string $message = ''): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            self::assert($e instanceof $class, $message . ' — rzucono ' . $e::class . ': ' . $e->getMessage());
            return;
        }
        self::assert(false, $message ?: "oczekiwano wyjątku {$class}");
    }
}

/** @var array<string,callable> $tests */
$tests = [];
$withDb = (bool) getenv('PORTAL_TEST_DB');
foreach (glob(__DIR__ . '/*Test.php') ?: [] as $file) {
    $isDb = str_ends_with($file, 'DbTest.php');
    if ($isDb && !$withDb) {
        continue;
    }
    $tests += require $file;
}

$start = microtime(true);
foreach ($tests as $name => $fn) {
    T::$current = $name;
    try {
        $fn();
    } catch (\Throwable $e) {
        T::$failures[] = "{$name}: wyjątek " . $e::class . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
    }
}

printf("%d testów, %d asercji, %d błędów (%.2f s)%s\n", count($tests), T::$passed, count(T::$failures),
    microtime(true) - $start, $withDb ? '' : ' — bez testów bazy (PORTAL_TEST_DB=1)');
foreach (T::$failures as $f) {
    echo "  ✘ {$f}\n";
}
exit(T::$failures === [] ? 0 : 1);
