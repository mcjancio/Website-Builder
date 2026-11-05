<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Auth\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Listings\ListingService;
use App\Listings\MediaService;

/** Moderacja w przeglądarce: kolejka, porównanie „było/jest”, akceptacja i odrzucenie z uzasadnieniem. */
final class ModerationController
{
    public function index(Request $request): Response
    {
        return Response::view('admin/moderation', ['title' => __('admin.moderation'), 'queue' => ListingService::pendingQueue(), 'noindex' => true]);
    }

    public function show(Request $request): Response
    {
        $listing = ListingService::find((int) $request->params['id']);
        if ($listing === null) {
            return Response::view('errors/404', [], 404);
        }
        $isRevision = ListingService::isLive($listing);
        return Response::view('admin/moderation-show', [
            'title' => __('admin.moderation') . ': ' . $listing['title'],
            'listing' => $listing,
            'isRevision' => $isRevision,
            'revision' => $isRevision ? ListingService::openRevision((int) $listing['id']) : null,
            'pending' => $listing['status'] === 'pending' || ($isRevision && (ListingService::openRevision((int) $listing['id'])['status'] ?? '') === 'pending'),
            'diff' => ListingService::diff($listing),
            'images' => MediaService::images((int) $listing['id']),
            'attachments' => MediaService::attachments((int) $listing['id']),
            'author' => DB::fetch('SELECT u.id, u.email, u.first_name, u.last_name, u.phone_e164, u.created_at, c.name AS company, c.nip FROM users u LEFT JOIN companies c ON c.id = u.company_id WHERE u.id = ?', [$listing['user_id']]),
            'authorListings' => (int) DB::value("SELECT COUNT(*) FROM listings WHERE user_id = ? AND status = 'published'", [$listing['user_id']]),
            'aiEnabled' => \App\Ai\Assistant::enabled('hints'),
            'aiHints' => \App\Ai\Assistant::latest((int) $listing['id'], 'hints'),
            'noindex' => true,
        ]);
    }

    public function decide(Request $request): Response
    {
        $id = (int) $request->params['id'];
        try {
            if ($request->str('action') === 'approve') {
                $kind = ListingService::approve($id, Auth::id(), $request);
                Session::flash('success', __('admin.approved_' . $kind));
            } else {
                $note = $request->str('note');
                if (mb_strlen($note) < 5) {
                    Session::flash('error', __('admin.reject_note_required'));
                    return Response::redirect(url('/admin/moderacja/' . $id));
                }
                $kind = ListingService::reject($id, Auth::id(), $note, $request);
                Session::flash('success', __('admin.rejected_' . $kind));
            }
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
        }
        return Response::redirect(url('/admin/moderacja'));
    }
}
