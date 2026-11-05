<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Auth\Audit;
use App\Auth\Auth;
use App\Core\Csv;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/** Zapytania jako prosty CRM: status, przypisanie, notatka, eksport CSV. */
final class InquiriesController
{
    public const STATUSES = ['new', 'in_progress', 'closed', 'spam'];
    private const PER_PAGE = 30;

    public function index(Request $request): Response
    {
        [$where, $args] = $this->filters($request);
        $total = (int) DB::value("SELECT COUNT(*) FROM inquiries i LEFT JOIN listings l ON l.id = i.listing_id WHERE {$where}", $args);
        $page = max(1, (int) $request->str('strona', '1'));
        $rows = DB::fetchAll(
            "SELECT i.*, l.title AS listing_title, l.slug AS listing_slug, a.email AS assignee
             FROM inquiries i LEFT JOIN listings l ON l.id = i.listing_id LEFT JOIN users a ON a.id = i.assigned_to
             WHERE {$where} ORDER BY i.created_at DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            $args
        );
        return Response::view('admin/inquiries', [
            'title' => __('admin.inquiries'), 'rows' => $rows, 'total' => $total, 'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)), 'status' => $request->str('status'), 'q' => $request->str('q'),
            'noindex' => true,
        ]);
    }

    public function show(Request $request): Response
    {
        $row = DB::fetch(
            'SELECT i.*, l.title AS listing_title, l.slug AS listing_slug, u.email AS account_email
             FROM inquiries i LEFT JOIN listings l ON l.id = i.listing_id LEFT JOIN users u ON u.id = i.user_id WHERE i.id = ?',
            [(int) $request->params['id']]
        );
        if ($row === null) {
            return Response::view('errors/404', [], 404);
        }
        return Response::view('admin/inquiry-show', [
            'title' => __('admin.inquiry') . ' #' . $row['id'], 'row' => $row,
            'staff' => DB::fetchAll("SELECT id, email, first_name, last_name FROM users WHERE role IN ('admin','moderator') AND status = 'active' ORDER BY first_name"),
            'noindex' => true,
        ]);
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $status = $request->str('status');
        $assigned = (int) $request->str('assigned_to');
        DB::query(
            'UPDATE inquiries SET status = ?, admin_note = ?, assigned_to = ? WHERE id = ?',
            [in_array($status, self::STATUSES, true) ? $status : 'new', mb_substr($request->str('admin_note'), 0, 5000) ?: null,
             $assigned > 0 && DB::value("SELECT 1 FROM users WHERE id = ? AND role IN ('admin','moderator')", [$assigned]) ? $assigned : null, $id]
        );
        Audit::log('inquiry.update', 'inquiry', $id, ['status' => $status], $request);
        Session::flash('success', __('admin.saved'));
        return Response::redirect(url('/admin/zapytania/' . $id));
    }

    public function export(Request $request): Response
    {
        [$where, $args] = $this->filters($request);
        $rows = DB::query(
            "SELECT i.id, i.created_at, i.status, i.name, i.email, i.phone_e164, l.title, i.message, i.admin_note
             FROM inquiries i LEFT JOIN listings l ON l.id = i.listing_id WHERE {$where} ORDER BY i.created_at DESC",
            $args
        );
        Audit::log('inquiry.export', null, null, [], $request, Auth::id());
        return Csv::response('zapytania-' . gmdate('Y-m-d') . '.csv',
            ['ID', 'Data (UTC)', 'Status', 'Imię i nazwisko', 'E-mail', 'Telefon', 'Oferta', 'Wiadomość', 'Notatka'], $rows);
    }

    /** @return array{0:string,1:array} */
    private function filters(Request $request): array
    {
        $where = ['1=1'];
        $args = [];
        $status = $request->str('status');
        if (in_array($status, self::STATUSES, true)) {
            $where[] = 'i.status = ?';
            $args[] = $status;
        }
        $q = $request->str('q');
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(i.name LIKE ? OR i.email LIKE ? OR l.title LIKE ?)';
            array_push($args, $like, $like, $like);
        }
        return [implode(' AND ', $where), $args];
    }
}
