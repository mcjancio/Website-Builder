<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Access\AccessService;
use App\Auth\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/** Prośby o dostęp do treści poufnych (L3) i nadane dostępy. */
final class AccessController
{
    public function index(Request $request): Response
    {
        return Response::view('admin/access', [
            'title' => __('admin.access'),
            'pending' => AccessService::pending(),
            'granted' => DB::fetchAll(
                "SELECT g.*, l.title, l.slug, u.email, u.first_name, u.last_name FROM access_grants g
                 JOIN listings l ON l.id = g.listing_id JOIN users u ON u.id = g.user_id
                 WHERE g.status = 'granted' ORDER BY g.decided_at DESC LIMIT 200"
            ),
            'noindex' => true,
        ]);
    }

    public function decide(Request $request): Response
    {
        $id = (int) $request->params['id'];
        try {
            match ($request->str('action')) {
                'grant' => AccessService::decide($id, true, Auth::id(),
                    ($days = (int) $request->str('days')) > 0 ? gmdate('Y-m-d H:i:s', time() + $days * 86400) : null, $request),
                'deny' => AccessService::decide($id, false, Auth::id(), null, $request),
                'revoke' => AccessService::revoke($id, Auth::id()),
                default => throw new \RuntimeException(__('admin.action_unavailable')),
            };
            Session::flash('success', __('admin.saved'));
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
        }
        return Response::redirect(url('/admin/dostepy'));
    }
}
