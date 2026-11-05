<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\DB;
use App\Core\Request;
use App\Core\Response;

/** sitemap.xml (tylko treści publiczne) i robots.txt. */
final class SeoController
{
    public function sitemap(Request $request): Response
    {
        $base = rtrim((string) config('app.url'), '/');
        $urls = [[$base . url('/'), null], [$base . url('/oferty'), null]];
        foreach (DB::fetchAll('SELECT slug FROM categories WHERE is_active = 1 ORDER BY sort_order') as $c) {
            $urls[] = [$base . url('/oferty', ['kategoria' => $c['slug']]), null];
        }
        foreach (DB::fetchAll("SELECT slug, updated_at FROM listings WHERE status = 'published' ORDER BY published_at DESC LIMIT 45000") as $l) {
            $urls[] = [$base . url('/oferta/' . $l['slug']), substr((string) $l['updated_at'], 0, 10)];
        }
        foreach (DB::fetchAll('SELECT slug, updated_at FROM pages') as $p) {
            $urls[] = [$base . url('/' . $p['slug']), substr((string) $p['updated_at'], 0, 10)];
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as [$loc, $mod]) {
            $xml .= '  <url><loc>' . htmlspecialchars($loc, ENT_XML1) . '</loc>' . ($mod ? '<lastmod>' . $mod . '</lastmod>' : '') . "</url>\n";
        }
        $xml .= "</urlset>\n";
        return Response::text($xml, 200, 'application/xml')->withHeader('Cache-Control', 'public, max-age=3600');
    }

    public function robots(Request $request): Response
    {
        $base = rtrim((string) config('app.url'), '/');
        $lines = [
            'User-agent: *',
            'Disallow: ' . url('/konto'),
            'Disallow: ' . url('/moje-oferty'),
            'Disallow: ' . url('/dodaj-oferte'),
            'Disallow: ' . url('/plik/'),
            'Disallow: ' . url('/logowanie'),
            'Disallow: ' . url('/rejestracja'),
            'Disallow: ' . url('/reset-hasla'),
            'Disallow: ' . url('/api/'),
            'Allow: /',
            '',
            'Sitemap: ' . $base . url('/sitemap.xml'),
        ];
        return Response::text(implode("\n", $lines) . "\n")->withHeader('Cache-Control', 'public, max-age=86400');
    }
}
