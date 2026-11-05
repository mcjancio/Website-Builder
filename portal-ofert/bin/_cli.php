<?php

declare(strict_types=1);

/** Wspólne funkcje skryptów bin/*.php. */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

/**
 * Parsuje argumenty: --flaga, --klucz=wartość, pozostałe jako pozycyjne.
 * @return array{0:array<string,string|bool>,1:string[]}
 */
function cli_args(array $argv): array
{
    $opts = [];
    $pos = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--([a-z0-9\-]+)(?:=(.*))?$/', $arg, $m)) {
            $opts[$m[1]] = $m[2] ?? true;
        } else {
            $pos[] = $arg;
        }
    }
    return [$opts, $pos];
}

function out(string $text = ''): void
{
    fwrite(STDOUT, $text . "\n");
}

function ok(string $text): void
{
    out((stream_isatty(STDOUT) ? "\033[32m✔\033[0m " : '[OK] ') . $text);
}

function warn(string $text): void
{
    fwrite(STDERR, (stream_isatty(STDERR) ? "\033[33m!\033[0m " : '[UWAGA] ') . $text . "\n");
}

function fail(string $text, int $code = 1): never
{
    fwrite(STDERR, (stream_isatty(STDERR) ? "\033[31m✘\033[0m " : '[BŁĄD] ') . $text . "\n");
    exit($code);
}

function ask(string $question, string $default = ''): string
{
    $suffix = $default !== '' ? " [{$default}]" : '';
    fwrite(STDOUT, $question . $suffix . ': ');
    $line = fgets(STDIN);
    $line = $line === false ? '' : trim($line);
    return $line === '' ? $default : $line;
}

/** Hasło bez wyświetlania znaków (gdy terminal na to pozwala). */
function ask_secret(string $question): string
{
    fwrite(STDOUT, $question . ': ');
    $tty = stream_isatty(STDIN) && DIRECTORY_SEPARATOR === '/';
    if ($tty) {
        shell_exec('stty -echo');
    }
    $line = fgets(STDIN);
    if ($tty) {
        shell_exec('stty echo');
        fwrite(STDOUT, "\n");
    }
    return $line === false ? '' : rtrim($line, "\r\n");
}
