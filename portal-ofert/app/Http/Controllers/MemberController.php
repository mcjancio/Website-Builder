<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Access\Gate;
use App\Auth\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Listings\Favorites;
use App\Listings\ListingService;
use App\Listings\SavedSearches;

/** Obserwowane oferty, zapisane wyszukiwania z alertami, moje dostępy do treści poufnych. */
final class MemberController
{
    public function favorites(Request $request): Response
    {
        return Response::view('account/favorites', ['title' => __('favorites.title'), 'items' => Favorites::forUser((int) Auth::id()), 'noindex' => true]);
    }

    public function toggleFavorite(Request $request): Response
    {
        $listing = ListingService::find((int) $request->params['id']);
        if ($listing === null || !Gate::canView(Auth::user(), $listing)) {
            return Response::view('errors/404', [], 404);
        }
        $on = Favorites::toggle((int) Auth::id(), (int) $listing['id']);
        if ($request->wantsJson()) {
            return Response::json(['favorite' => $on]);
        }
        Session::flash('success', __($on ? 'favorites.added' : 'favorites.removed'));
        $back = $request->str('back');
        return Response::redirect(preg_match('#^/(oferta/|konto/obserwowane)#', $back) ? url($back) : url('/oferta/' . $listing['slug']));
    }

    public function searches(Request $request): Response
    {
        return Response::view('account/searches', ['title' => __('saved.title'), 'items' => SavedSearches::forUser((int) Auth::id()), 'noindex' => true]);
    }

    public function saveSearch(Request $request): Response
    {
        $query = (array) ($request->post['q'] ?? []);
        $id = SavedSearches::save((int) Auth::id(), $query, $request->str('name'), $request->str('frequency', 'weekly'));
        Session::flash($id ? 'success' : 'error', __($id ? 'saved.saved' : 'saved.limit', ['max' => SavedSearches::MAX_PER_USER]));
        return Response::redirect($id ? url('/konto/wyszukiwania') : url('/oferty'));
    }

    public function updateSearch(Request $request): Response
    {
        $id = (int) $request->params['id'];
        if ($request->str('action') === 'delete') {
            SavedSearches::delete((int) Auth::id(), $id);
            Session::flash('success', __('admin.deleted'));
        } else {
            SavedSearches::update((int) Auth::id(), $id, $request->str('frequency'));
            Session::flash('success', __('admin.saved'));
        }
        return Response::redirect(url('/konto/wyszukiwania'));
    }

    public function access(Request $request): Response
    {
        return Response::view('account/access', [
            'title' => __('myaccess.title'),
            'items' => DB::fetchAll(
                "SELECT g.status, g.requested_at, g.decided_at, g.expires_at, l.title, l.slug, l.status AS listing_status
                 FROM access_grants g JOIN listings l ON l.id = g.listing_id WHERE g.user_id = ? ORDER BY g.requested_at DESC",
                [Auth::id()]
            ),
            'noindex' => true,
        ]);
    }
}
