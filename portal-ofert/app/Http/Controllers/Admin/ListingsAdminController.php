<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Auth\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Listings\ListingService;

/** Wszystkie oferty: filtr statusu, wyszukiwanie, akcje (wstrzymaj, zakończ, archiwizuj, wyróżnij). */
final class ListingsAdminController
{
    private const PER_PAGE = 30;

    public function index(Request $request): Response
    {
        $where = ['1=1'];
        $args = [];
        $status = $request->str('status');
        if (in_array($status, ['draft', 'pending', 'published', 'paused', 'sold', 'archived', 'rejected'], true)) {
            $where[] = 'l.status = ?';
            $args[] = $status;
        }
        $q = $request->str('q');
        if ($q !== '') {
            $where[] = '(l.title LIKE ? OR u.email LIKE ? OR l.id = ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($args, $like, $like, (int) $q);
        }
        $w = implode(' AND ', $where);
        $total = (int) DB::value("SELECT COUNT(*) FROM listings l JOIN users u ON u.id = l.user_id WHERE {$w}", $args);
        $page = max(1, min((int) ceil(max(1, $total) / self::PER_PAGE), (int) $request->str('strona', '1')));
        $rows = DB::fetchAll(
            "SELECT l.id, l.slug, l.title, l.status, l.is_featured, l.published_at, l.updated_at, u.email, c.name AS category,
                    (SELECT COALESCE(SUM(views_public + views_member), 0) FROM listing_stats s WHERE s.listing_id = l.id) AS views,
                    (SELECT COUNT(*) FROM inquiries i WHERE i.listing_id = l.id) AS inquiries
             FROM listings l JOIN users u ON u.id = l.user_id JOIN categories c ON c.id = l.category_id
             WHERE {$w} ORDER BY l.updated_at DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            $args
        );
        return Response::view('admin/listings', [
            'title' => __('admin.listings'), 'rows' => $rows, 'total' => $total, 'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)), 'status' => $status, 'q' => $q, 'noindex' => true,
        ]);
    }

    public function action(Request $request): Response
    {
        $listing = ListingService::find((int) $request->params['id']);
        if ($listing === null) {
            return Response::view('errors/404', [], 404);
        }
        $action = $request->str('action');
        if (in_array($action, ['feature', 'unfeature'], true)) {
            ListingService::setFeatured((int) $listing['id'], $action === 'feature', Auth::id());
            Session::flash('success', __('admin.saved'));
        } elseif (ListingService::changeStatus($listing, $action, Auth::id(), $request)) {
            Session::flash('success', __('admin.status_changed', ['status' => __('status.' . DB::value('SELECT status FROM listings WHERE id = ?', [$listing['id']]))]));
        } else {
            Session::flash('error', __('admin.action_unavailable'));
        }
        $back = $request->str('back');
        return Response::redirect(str_starts_with($back, '/admin') ? url($back) : url('/admin/oferty'));
    }
}
