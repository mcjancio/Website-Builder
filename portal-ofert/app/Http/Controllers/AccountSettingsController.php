<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Auth\AccountService;
use App\Auth\Audit;
use App\Auth\Auth;
use App\Core\DB;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Core\Validator;
use App\Core\View;

/** „Moje konto”: profil i firma, bezpieczeństwo (hasło, urządzenia), usunięcie konta. */
final class AccountSettingsController
{
    public function profile(Request $request): Response
    {
        return Response::view('account/profile', $this->profileData([], []));
    }

    public function saveProfile(Request $request): Response
    {
        $user = Auth::user();
        $types = array_column((array) Settings::get('account_types', []), 'key');
        $rules = ['first_name' => 'required|maxlength:100', 'last_name' => 'required|maxlength:100'];
        if ($types !== []) {
            $rules['account_type'] = 'required|in:' . implode(',', $types);
        }
        $v = Validator::make($request->post, $rules, ['first_name' => __('register.first_name'), 'last_name' => __('register.last_name'), 'account_type' => __('register.account_type')]);
        if ($v->fails()) {
            return Response::html(View::render('account/profile', $this->profileData($v->errors(), $request->post)), 422);
        }
        $d = $v->validated();
        DB::update('users', ['first_name' => $d['first_name'], 'last_name' => $d['last_name'], 'account_type' => $d['account_type'] ?? $user['account_type']], ['id' => $user['id']]);
        // Nazwę firmy można poprawić tylko, gdy nie pochodzi z rejestru MF.
        $company = DB::fetch('SELECT c.id, c.source FROM users u JOIN companies c ON c.id = u.company_id WHERE u.id = ?', [$user['id']]);
        $companyName = trim($request->str('company_name'));
        if ($company !== null && $company['source'] === 'manual' && $companyName !== '') {
            DB::update('companies', ['name' => mb_substr($companyName, 0, 255)], ['id' => $company['id']]);
        }
        Audit::log('profile.update', 'user', (int) $user['id'], [], $request);
        Session::flash('success', __('admin.saved'));
        return Response::redirect(url('/konto/profil'));
    }

    public function security(Request $request): Response
    {
        return Response::view('account/security', $this->securityData([]));
    }

    public function changePassword(Request $request): Response
    {
        $user = Auth::user();
        $errors = [];
        if (!RateLimiter::attempt('pwchange:user:' . $user['id'], 10, 3600)) {
            $errors['current_password'] = __('auth.too_many_attempts');
        }
        $hash = (string) DB::value('SELECT password_hash FROM users WHERE id = ?', [$user['id']]);
        if ($errors === [] && !password_verify((string) ($request->post['current_password'] ?? ''), $hash)) {
            $errors['current_password'] = __('security.wrong_password');
        }
        $new = (string) ($request->post['password'] ?? '');
        if (mb_strlen($new) < 10) {
            $errors['password'] = __('validation.minlength', ['field' => __('password.new'), 'arg' => 10]);
        } elseif ($new !== ($request->post['password_confirmation'] ?? null)) {
            $errors['password_confirmation'] = __('register.password_mismatch');
        }
        if ($errors !== []) {
            return Response::html(View::render('account/security', $this->securityData($errors)), 422);
        }
        AccountService::changePassword((int) $user['id'], $new, $request);
        \App\Core\Session::regenerate();
        Session::flash('success', __('security.password_changed'));
        return Response::redirect(url('/konto/bezpieczenstwo'));
    }

    public function logoutDevices(Request $request): Response
    {
        AccountService::logoutEverywhere((int) Auth::id(), $request);
        Session::flash('success', __('security.devices_logged_out'));
        return Response::redirect(url('/konto/bezpieczenstwo'));
    }

    public function deleteForm(Request $request): Response
    {
        return Response::view('account/delete', ['title' => __('delete.title'), 'errors' => [], 'noindex' => true]);
    }

    public function delete(Request $request): Response
    {
        $user = Auth::user();
        $errors = [];
        if (in_array($user['role'], ['admin', 'moderator'], true)
            && (int) DB::value("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'") <= 1 && $user['role'] === 'admin') {
            $errors['password'] = __('delete.last_admin');
        }
        if ($request->str('confirm') !== '1') {
            $errors['confirm'] = __('validation.accepted', ['field' => __('delete.confirm_label')]);
        }
        if ($errors === [] && !RateLimiter::attempt('reauth:user:' . $user['id'], 5, 900)) {
            $errors['password'] = __('auth.too_many_attempts');
        }
        if ($errors === [] && !password_verify((string) ($request->post['password'] ?? ''), (string) DB::value('SELECT password_hash FROM users WHERE id = ?', [$user['id']]))) {
            $errors['password'] = __('security.wrong_password');
        }
        if ($errors !== []) {
            return Response::html(View::render('account/delete', ['title' => __('delete.title'), 'errors' => $errors, 'noindex' => true]), 422);
        }
        AccountService::delete((int) $user['id'], $request);
        Auth::logout($request);
        \App\Core\Session::start($request);
        Session::flash('success', __('delete.done'));
        return Response::redirect(url('/'));
    }

    private function profileData(array $errors, array $old): array
    {
        $user = Auth::user();
        $company = DB::fetch('SELECT c.* FROM users u JOIN companies c ON c.id = u.company_id WHERE u.id = ?', [$user['id']]);
        return [
            'title' => __('profile.title'), 'user' => $user, 'company' => $company, 'errors' => $errors, 'old' => $old,
            'accountTypes' => (array) Settings::get('account_types', []), 'noindex' => true,
        ];
    }

    private function securityData(array $errors): array
    {
        $user = Auth::user();
        return [
            'title' => __('security.title'), 'user' => $user, 'errors' => $errors,
            'devices' => AccountService::devices((int) $user['id']),
            'lastEvents' => DB::fetchAll("SELECT action, created_at FROM audit_log WHERE user_id = ? AND action LIKE 'login.%' ORDER BY id DESC LIMIT 10", [$user['id']]),
            'noindex' => true,
        ];
    }
}
