<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Access\Gate;
use App\Auth\Auth;
use App\Core\DB;
use App\Core\Markdown;
use App\Core\Request;
use App\Core\Response;
use App\Listings\Fields;
use App\Listings\ListingService;
use App\Listings\MediaService;

/** „Moje oferty” i podgląd oferty (autor, moderator, admin) — pełny widok publiczny w etapie 4. */
final class MyListingsController
{
    public function index(Request $request): Response
    {
        $rows = DB::fetchAll(
            "SELECT l.*, c.name AS category_name,
                    (SELECT status FROM listing_revisions r WHERE r.listing_id = l.id AND r.status IN ('draft','pending','rejected') ORDER BY r.id DESC LIMIT 1) AS revision_status,
                    (SELECT review_note FROM listing_revisions r WHERE r.listing_id = l.id ORDER BY r.id DESC LIMIT 1) AS revision_note,
                    (SELECT id FROM media m WHERE m.listing_id = l.id ORDER BY is_cover DESC, sort_order LIMIT 1) AS cover_id
             FROM listings l JOIN categories c ON c.id = l.category_id
             WHERE l.user_id = ? AND l.status <> 'archived' ORDER BY l.updated_at DESC",
            [Auth::id()]
        );
        return Response::view('listings/mine', ['title' => __('mine.title'), 'listings' => $rows, 'noindex' => true]);
    }

    public function preview(Request $request): Response
    {
        $listing = ListingService::find((int) $request->params['id']);
        $user = Auth::user();
        if ($listing === null || !(Gate::isOwner($user, $listing) || Gate::isStaff($user))) {
            return Response::view('errors/404', [], 404);
        }
        $state = ListingService::editable($listing);
        $fields = Fields::forCategory($state['category_id']);
        return Response::view('listings/preview', [
            'title' => __('preview.title', ['title' => $state['core']['title']]),
            'listing' => $listing,
            'state' => $state,
            'groups' => Fields::grouped($fields),
            'description' => Markdown::toHtml((string) ($state['core']['full_desc_md'] ?? '')),
            'confidential' => Markdown::toHtml((string) ($state['core']['confidential_md'] ?? '')),
            'images' => MediaService::images((int) $listing['id']),
            'attachments' => MediaService::attachments((int) $listing['id']),
            'category' => DB::value('SELECT name FROM categories WHERE id = ?', [$state['category_id']]),
            'isStaff' => Gate::isStaff($user),
            'noindex' => true,
        ]);
    }
}
