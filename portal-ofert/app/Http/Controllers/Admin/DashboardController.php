<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\CampaignMode;
use App\Core\DB;
use App\Core\Queue;
use App\Core\Request;
use App\Core\Response;
use App\Listings\ListingService;

final class DashboardController
{
    public function index(Request $request): Response
    {
        $days = DB::fetchAll(
            'SELECT day, SUM(views_public + views_member) AS views, SUM(inquiries) AS inquiries
             FROM listing_stats WHERE day >= UTC_DATE() - INTERVAL 29 DAY GROUP BY day ORDER BY day'
        );
        $byDay = array_column($days, null, 'day');
        $series = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = gmdate('Y-m-d', strtotime("-{$i} days"));
            $series[] = ['day' => $d, 'views' => (int) ($byDay[$d]['views'] ?? 0), 'inquiries' => (int) ($byDay[$d]['inquiries'] ?? 0)];
        }
        return Response::view('admin/dashboard', [
            'title' => __('admin.dashboard'),
            'counts' => [
                'moderation' => count(ListingService::pendingQueue()),
                'access' => (int) DB::value("SELECT COUNT(*) FROM access_grants WHERE status = 'requested'"),
                'inquiries' => (int) DB::value("SELECT COUNT(*) FROM inquiries WHERE status = 'new'"),
                'users7' => (int) DB::value("SELECT COUNT(*) FROM users WHERE created_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY AND status = 'active'"),
                'published' => (int) DB::value("SELECT COUNT(*) FROM listings WHERE status = 'published'"),
                'failedJobs' => Queue::failedCount(),
            ],
            'series' => $series,
            'campaignUntil' => CampaignMode::until(),
            'recentUsers' => DB::fetchAll("SELECT u.id, u.first_name, u.last_name, u.email, u.created_at, c.name AS company FROM users u LEFT JOIN companies c ON c.id = u.company_id WHERE u.status = 'active' ORDER BY u.created_at DESC LIMIT 5"),
            'noindex' => true,
        ]);
    }
}
