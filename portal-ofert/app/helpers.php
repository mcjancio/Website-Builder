<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Lang;

/** Escapowanie do HTML — używaj przy KAŻDYM wypisaniu danych w widoku. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/** Tekst interfejsu z lang/pl.php, z podstawieniem {parametrów}. */
function __(string $key, array $params = []): string
{
    return Lang::get($key, $params);
}

function config(string $key, mixed $default = null): mixed
{
    return Config::get($key, $default);
}

/** Adres względny w portalu (uwzględnia app.base_path, gdy portal nie stoi w katalogu głównym domeny). */
function url(string $path = '/', array $query = []): string
{
    $base = rtrim((string) Config::get('app.base_path', ''), '/');
    $url = $base . '/' . ltrim($path, '/');
    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }
    return $url;
}

/** Adres do pliku w public/assets z parametrem wersji (cache-busting po wdrożeniu). */
function asset(string $path): string
{
    $file = APP_ROOT . '/public/assets/' . ltrim($path, '/');
    $v = is_file($file) ? substr((string) filemtime($file), -6) : '0';
    return url('assets/' . ltrim($path, '/')) . '?v=' . $v;
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
}

/** Ustawienie portalu z tabeli settings (np. setting('portal.name')). */
function setting(string $key, mixed $default = null): mixed
{
    return \App\Core\Settings::get($key, $default);
}
