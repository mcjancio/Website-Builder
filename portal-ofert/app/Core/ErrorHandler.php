<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Każdy błąd PHP zamieniany na wyjątek; nieobsłużony wyjątek → log + strona 500
 * (szczegóły tylko gdy app.debug = true).
 */
final class ErrorHandler
{
    private static bool $debug = false;

    public static function register(bool $debug): void
    {
        self::$debug = $debug;
        error_reporting(E_ALL);
        ini_set('display_errors', $debug && PHP_SAPI === 'cli' ? '1' : '0');

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false; // błąd wyciszony operatorem @
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler([self::class, 'handle']);

        register_shutdown_function(static function (): void {
            $err = error_get_last();
            if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                self::handle(new \ErrorException($err['message'], 0, $err['type'], $err['file'], $err['line']));
            }
        });
    }

    public static function isDebug(): bool
    {
        return self::$debug;
    }

    public static function handle(\Throwable $e): void
    {
        Logger::critical($e->getMessage(), [
            'exception' => $e::class,
            'file' => $e->getFile() . ':' . $e->getLine(),
            'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 15),
        ]);

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, '[BŁĄD] ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ")\n");
            exit(1);
        }

        self::renderErrorPage($e)->send();
    }

    public static function renderErrorPage(\Throwable $e): Response
    {
        try {
            $body = View::render('errors/500', ['exception' => self::$debug ? $e : null]);
        } catch (\Throwable) {
            $body = '<!doctype html><meta charset="utf-8"><title>Błąd</title><p>Wystąpił błąd serwera. Spróbuj ponownie za chwilę.</p>';
        }
        return Response::html($body, 500);
    }
}
