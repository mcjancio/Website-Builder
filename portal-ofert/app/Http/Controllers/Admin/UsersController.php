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

/** Użytkownicy (tylko admin): wyszukiwanie, blokada, rola, reset 2FA, eksport CSV. */
final class UsersController
{
    private const PER_PAGE = 30;

    public function index(Request $request): Response
    {
        [$where, $args] = $this->filters($request);
        $total = (int) DB::value("SELECT COUNT(*) FROM users u LEFT JOIN companies c ON c.id = u.company_id WHERE {$where}", $args);
        $page = max(1, min(100000, (int) $request->str('strona', '1')));
        $rows = DB::fetchAll(
            "SELECT u.id, u.email, u.first_name, u.last_name, u.phone_e164, u.role, u.account_type, u.status, u.created_at,
                    u.last_login_at, u.email_verified_at, u.totp_enabled_at, c.name AS company,
                    (SELECT COUNT(*) FROM listings l WHERE l.user_id = u.id AND l.status = 'published') AS listings
             FROM users u LEFT JOIN companies c ON c.id = u.company_id
             WHERE {$where} ORDER BY u.created_at DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            $args
        );
        return Response::view('admin/users', [
            'title' => __('admin.users'), 'rows' => $rows, 'total' => $total, 'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)), 'q' => $request->str('q'), 'role' => $request->str('role'),
            'noindex' => true,
        ]);
    }

    public function show(Request $request): Response
    {
        $u = DB::fetch(
            'SELECT u.*, c.name AS company, c.nip, c.city AS company_city, c.source AS company_source
             FROM users u LEFT JOIN companies c ON c.id = u.company_id WHERE u.id = ?',
            [(int) $request->params['id']]
        );
        if ($u === null) {
            return Response::view('errors/404', [], 404);
        }
        return Response::view('admin/user-show', [
            'title' => trim($u['first_name'] . ' ' . $u['last_name']),
            'u' => $u,
            'listings' => DB::fetchAll('SELECT id, slug, title, status FROM listings WHERE user_id = ? ORDER BY updated_at DESC', [$u['id']]),
            'consents' => DB::fetchAll('SELECT type, granted, page_version, created_at FROM consents WHERE user_id = ? ORDER BY created_at DESC', [$u['id']]),
            'events' => DB::fetchAll('SELECT action, created_at FROM audit_log WHERE user_id = ? ORDER BY id DESC LIMIT 20', [$u['id']]),
            'isSelf' => (int) $u['id'] === Auth::id(),
            'noindex' => true,
        ]);
    }

    public function action(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $u = DB::fetch('SELECT id, role, status FROM users WHERE id = ?', [$id]);
        $back = url('/admin/uzytkownicy/' . $id);
        if ($u === null || $u['status'] === 'deleted') {
            return Response::view('errors/404', [], 404);
        }
        if ($id === Auth::id()) {
            Session::flash('error', __('admin.not_on_self'));
            return Response::redirect($back);
        }
        switch ($request->str('action')) {
            case 'block':
                DB::update('users', ['status' => 'blocked'], ['id' => $id]);
                \App\Auth\Auth::invalidateSessions($id);
                Audit::log('user.block', 'user', $id, [], $request, Auth::id());
                break;
            case 'unblock':
                DB::update('users', ['status' => 'active', 'failed_logins' => 0, 'locked_until' => null], ['id' => $id]);
                Audit::log('user.unblock', 'user', $id, [], $request, Auth::id());
                break;
            case 'role':
                $role = $request->str('role');
                if (!in_array($role, ['member', 'moderator', 'admin'], true)) {
                    break;
                }
                DB::update('users', ['role' => $role], ['id' => $id]);
                \App\Auth\Auth::invalidateSessions($id);
                Audit::log('role.change', 'user', $id, ['from' => $u['role'], 'to' => $role], $request, Auth::id());
                break;
            case 'reset2fa':
                // Np. zgubiony telefon. Admin/moderator zostanie poproszony o ponowną konfigurację przy logowaniu.
                DB::update('users', ['totp_secret' => null, 'totp_enabled_at' => null, 'recovery_codes' => null, 'totp_last_step' => null], ['id' => $id]);
                \App\Auth\Auth::invalidateSessions($id);
                Audit::log('2fa.reset_by_admin', 'user', $id, [], $request, Auth::id());
                break;
            default:
                Session::flash('error', __('admin.action_unavailable'));
                return Response::redirect($back);
        }
        Session::flash('success', __('admin.saved'));
        return Response::redirect($back);
    }

    public function export(Request $request): Response
    {
        [$where, $args] = $this->filters($request);
        Audit::log('user.export', null, null, [], $request, Auth::id());
        return Csv::response('uzytkownicy-' . gmdate('Y-m-d') . '.csv',
            ['ID', 'E-mail', 'Imię', 'Nazwisko', 'Telefon', 'Firma', 'NIP', 'Typ konta', 'Rola', 'Status', 'Rejestracja (UTC)', 'Zgoda marketingowa'],
            DB::query(
                "SELECT u.id, u.email, u.first_name, u.last_name, u.phone_e164, c.name, c.nip, u.account_type, u.role, u.status, u.created_at,
                        (SELECT IF(granted, 'tak', 'nie') FROM consents k WHERE k.user_id = u.id AND k.type = 'marketing' ORDER BY k.id DESC LIMIT 1)
                 FROM users u LEFT JOIN companies c ON c.id = u.company_id WHERE {$where} ORDER BY u.created_at DESC",
                $args
            ));
    }

    /** @return array{0:string,1:array} */
    private function filters(Request $request): array
    {
        $where = ["u.status <> 'deleted'"];
        $args = [];
        $q = $request->str('q');
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(u.email LIKE ? OR u.last_name LIKE ? OR u.phone_e164 LIKE ? OR c.name LIKE ? OR c.nip LIKE ?)';
            array_push($args, $like, $like, $like, $like, $like);
        }
        $role = $request->str('role');
        if (in_array($role, ['member', 'moderator', 'admin'], true)) {
            $where[] = 'u.role = ?';
            $args[] = $role;
        }
        return [implode(' AND ', $where), $args];
    }
}
