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

/** „Moje oferty”: lista ze statystykami, podgląd (autor i moderacja), statystyki i zapytania, zmiana statusu. */
final class MyListingsController
{
    public function index(Request $request): Response
    {
        $rows = DB::fetchAll(
            "SELECT l.*, c.name AS category_name,
                    (SELECT status FROM listing_revisions r WHERE r.listing_id = l.id AND r.status IN ('draft','pending','rejected') ORDER BY r.id DESC LIMIT 1) AS revision_status,
                    (SELECT review_note FROM listing_revisions r WHERE r.listing_id = l.id ORDER BY r.id DESC LIMIT 1) AS revision_note,
                    (SELECT id FROM media m WHERE m.listing_id = l.id ORDER BY is_cover DESC, sort_order LIMIT 1) AS cover_id,
                    (SELECT COALESCE(SUM(views_public + views_member), 0) FROM listing_stats s WHERE s.listing_id = l.id AND s.day >= UTC_DATE() - INTERVAL 29 DAY) AS views30,
                    (SELECT COUNT(*) FROM inquiries i WHERE i.listing_id = l.id AND i.status <> 'spam') AS inquiries
             FROM listings l JOIN categories c ON c.id = l.category_id
             WHERE l.user_id = ? AND l.status <> 'archived' ORDER BY l.updated_at DESC",
            [Auth::id()]
        );
        return Response::view('listings/mine', ['title' => __('mine.title'), 'listings' => $rows, 'noindex' => true]);
    }

    /** Statystyki oferty dla autora: wyświetlenia 30 dni, zapytania (leady), obserwujący. */
    public function stats(Request $request): Response
    {
        $listing = ListingService::find((int) $request->params['id']);
        if ($listing === null || !Gate::isOwner(Auth::user(), $listing)) {
            return Response::view('errors/404', [], 404);
        }
        $rows = DB::fetchAll('SELECT day, views_public, views_member, inquiries FROM listing_stats WHERE listing_id = ? AND day >= UTC_DATE() - INTERVAL 29 DAY ORDER BY day', [$listing['id']]);
        $byDay = array_column($rows, null, 'day');
        $series = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = gmdate('Y-m-d', strtotime("-{$i} days"));
            $series[] = ['day' => $d, 'views' => (int) ($byDay[$d]['views_public'] ?? 0) + (int) ($byDay[$d]['views_member'] ?? 0), 'inquiries' => (int) ($byDay[$d]['inquiries'] ?? 0)];
        }
        return Response::view('listings/stats', [
            'title' => __('stats.title', ['title' => $listing['title']]),
            'listing' => $listing,
            'series' => $series,
            'totals' => DB::fetch('SELECT COALESCE(SUM(views_public),0) AS pub, COALESCE(SUM(views_member),0) AS mem, COALESCE(SUM(inquiries),0) AS inq FROM listing_stats WHERE listing_id = ?', [$listing['id']]),
            'members' => (int) DB::value('SELECT COUNT(*) FROM listing_member_views WHERE listing_id = ?', [$listing['id']]),
            'favorites' => (int) DB::value('SELECT COUNT(*) FROM favorites WHERE listing_id = ?', [$listing['id']]),
            'inquiries' => DB::fetchAll("SELECT name, email, phone_e164, message, created_at FROM inquiries WHERE listing_id = ? AND status <> 'spam' ORDER BY created_at DESC LIMIT 100", [$listing['id']]),
            'noindex' => true,
        ]);
    }

    /** Autor: wstrzymaj / wznów / zakończ / archiwizuj własną ofertę. */
    public function status(Request $request): Response
    {
        $listing = ListingService::find((int) $request->params['id']);
        if ($listing === null || !Gate::isOwner(Auth::user(), $listing)) {
            return Response::view('errors/404', [], 404);
        }
        $action = $request->str('action');
        $ok = in_array($action, ['pause', 'resume', 'sold', 'archive'], true) && ListingService::changeStatus($listing, $action, Auth::id(), $request);
        \App\Core\Session::flash($ok ? 'success' : 'error', $ok ? __('mine.status_' . $action) : __('admin.action_unavailable'));
        return Response::redirect(url('/moje-oferty'));
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
