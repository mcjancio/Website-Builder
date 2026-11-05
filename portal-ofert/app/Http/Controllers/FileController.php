<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Access\Gate;
use App\Auth\Audit;
use App\Auth\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Listings\ListingService;
use App\Listings\MediaService;

/**
 * Wydawanie zdjęć i dokumentów z storage/ (poza public_html) po sprawdzeniu Gate.
 * Linku do pliku nie da się „podać dalej” — każde pobranie sprawdza uprawnienia od nowa.
 */
final class FileController
{
    public function image(Request $request): Response
    {
        $media = DB::fetch('SELECT * FROM media WHERE id = ?', [(int) $request->params['id']]);
        $listing = $media ? ListingService::find((int) $media['listing_id']) : null;
        $user = Auth::user();
        if ($media === null || $listing === null || !$this->visible($user, $listing, $media['state'])
            || !Gate::canSee($user, $listing, (int) $media['access_level'])) {
            return Response::text('Not found', 404);
        }
        $path = MediaService::imagePath($media, (int) $request->params['size']);
        if ($path === null) {
            return Response::text('Not found', 404);
        }
        $etag = '"' . $media['id'] . '-' . filemtime($path) . '"';
        // Publiczne zdjęcia opublikowanej oferty mogą być cache'owane; pozostałe — tylko w przeglądarce.
        $public = $listing['status'] === 'published' && (int) $media['access_level'] === 1 && $media['state'] !== 'pending_add';
        $headers = [
            'Content-Type' => 'image/webp',
            // Krótko: po wstrzymaniu oferty zdjęcie nie może długo krążyć w pamięci podręcznej CDN/proxy.
            'Cache-Control' => $public ? 'public, max-age=300' : 'private, max-age=600',
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
        ];
        if (($request->header('If-None-Match') ?? '') === $etag) {
            return new Response('', 304, $headers);
        }
        return new Response((string) file_get_contents($path), 200, $headers);
    }

    public function attachment(Request $request): Response
    {
        $att = DB::fetch('SELECT * FROM attachments WHERE id = ?', [(int) $request->params['id']]);
        $listing = $att ? ListingService::find((int) $att['listing_id']) : null;
        $user = Auth::user();
        if ($att === null || $listing === null || !$this->visible($user, $listing, $att['state'])) {
            return Response::view('errors/404', [], 404);
        }
        if (!Gate::canSee($user, $listing, (int) $att['access_level'])) {
            Audit::log('file.denied', 'attachment', (int) $att['id'], ['level' => (int) $att['access_level']], $request);
            return Response::view('errors/403', [], $user === null ? 401 : 403);
        }
        $path = APP_ROOT . '/storage/uploads/' . $att['stored_path'];
        if (!is_file($path)) {
            return Response::view('errors/404', [], 404);
        }
        DB::insert('downloads', ['attachment_id' => $att['id'], 'user_id' => $user['id'] ?? null, 'ip' => inet_pton($request->ip()) ?: inet_pton('0.0.0.0')]);
        if ((int) $att['access_level'] === 3) {
            Audit::log('file.download', 'attachment', (int) $att['id'], ['listing' => (int) $listing['id']], $request);
        }
        $name = $att['original_name'];
        $ascii = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
        // PDF otwiera się w przeglądarce, reszta pobiera się jako plik.
        $disposition = $att['mime'] === 'application/pdf' ? 'inline' : 'attachment';
        return new Response((string) file_get_contents($path), 200, [
            'Content-Type' => $att['mime'],
            'Content-Disposition' => $disposition . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            // „sandbox” blokuje wbudowaną przeglądarkę PDF w Chrome — dla PDF tylko zakaz ładowania czegokolwiek.
            'Content-Security-Policy' => $disposition === 'inline' ? "default-src 'none'" : "default-src 'none'; sandbox",
        ]);
    }

    /** Pliki oczekujące na moderację widzą tylko autor i moderacja. */
    private function visible(?array $user, array $listing, string $state): bool
    {
        if ($state === 'pending_add') {
            return Gate::isOwner($user, $listing) || Gate::isStaff($user);
        }
        return Gate::canView($user, $listing);
    }
}
