<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;

/**
 * /theme.css — kolory z presetu (settings.portal.theme) jako zmienne CSS.
 * Osobny plik zamiast inline <style>, bo CSP nie dopuszcza stylów inline.
 */
final class ThemeController
{
    public function css(Request $request): Response
    {
        $theme = (array) Settings::get('portal.theme', []);
        $accent = self::color($theme['accent'] ?? null, '#C8102E');
        $accentContrast = self::color($theme['accent_contrast'] ?? null, '#FFFFFF');
        $mode = ($theme['mode'] ?? 'light') === 'dark' ? 'dark' : 'light';

        $css = ":root{--accent:{$accent};--accent-contrast:{$accentContrast};color-scheme:{$mode};}\n";
        if ($mode === 'dark') {
            $css .= ":root{--bg:#0e0f11;--surface:#17191c;--text:#f2f2f0;--muted:#a7a9ad;--border:#2b2e33;}\n";
        }

        $etag = '"' . substr(sha1($css), 0, 16) . '"';
        if (($request->header('If-None-Match') ?? '') === $etag) {
            return new Response('', 304, ['ETag' => $etag]);
        }
        return Response::text($css, 200, 'text/css')
            ->withHeader('Cache-Control', 'public, max-age=300')
            ->withHeader('ETag', $etag);
    }

    /** Tylko #RGB / #RRGGBB — wartość z bazy trafia do CSS, nic innego nie przepuszczamy. */
    private static function color(mixed $value, string $fallback): string
    {
        return is_string($value) && preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $value) ? $value : $fallback;
    }
}
