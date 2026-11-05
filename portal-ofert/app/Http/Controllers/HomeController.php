<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\DB;
use App\Core\Request;
use App\Core\Response;

final class HomeController
{
    public function index(Request $request): Response
    {
        $categories = DB::fetchAll(
            'SELECT slug, name FROM categories WHERE is_active = 1 AND parent_id IS NULL ORDER BY sort_order, name'
        );
        $level = \App\Listings\ListingQuery::levelFor(\App\Auth\Auth::user());
        return Response::view('home', [
            'categories' => $categories,
            'latest' => \App\Listings\ListingQuery::latest(6, $level),
            'total' => (int) DB::value("SELECT COUNT(*) FROM listings WHERE status = 'published'"),
            'level' => $level,
            'canonical' => url('/'),
        ]);
    }
}
