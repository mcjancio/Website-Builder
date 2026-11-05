<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Auth\Audit;
use App\Auth\Auth;
use App\Core\CampaignMode;
use App\Core\DB;
use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Core\Str;
use App\Core\View;

/** Strony treści, ustawienia portalu i słownictwo, tryb kampanii, dziennik zdarzeń (tylko admin). */
final class ContentController
{
    // ------------------------------------------------------------------ strony treści

    public function pages(Request $request): Response
    {
        return Response::view('admin/pages', [
            'title' => __('admin.pages'),
            'pages' => DB::fetchAll('SELECT id, slug, title, in_menu, version, updated_at FROM pages ORDER BY menu_order, id'),
            'noindex' => true,
        ]);
    }

    public function editPage(Request $request): Response
    {
        $page = isset($request->params['id']) ? DB::fetch('SELECT * FROM pages WHERE id = ?', [(int) $request->params['id']]) : null;
        if (isset($request->params['id']) && $page === null) {
            return Response::view('errors/404', [], 404);
        }
        return Response::view('admin/page-edit', ['title' => $page['title'] ?? __('pages.new'), 'page' => $page, 'errors' => [], 'noindex' => true]);
    }

    public function savePage(Request $request): Response
    {
        $id = isset($request->params['id']) ? (int) $request->params['id'] : null;
        if ($id !== null && $request->str('action') === 'delete') {
            DB::query('DELETE FROM pages WHERE id = ?', [$id]);
            Audit::log('page.delete', 'page', $id, [], $request, Auth::id());
            Session::flash('success', __('admin.deleted'));
            return Response::redirect(url('/admin/strony'));
        }
        $title = trim($request->str('title'));
        $body = (string) ($request->post['body_md'] ?? '');
        $slug = Str::slug($request->str('slug') !== '' ? $request->str('slug') : $title, 120);
        $errors = [];
        if ($title === '' || mb_strlen($title) > 200) {
            $errors['title'] = __('field.required', ['field' => __('pages.title_label')]);
        }
        $reserved = ['oferty', 'oferta', 'konto', 'admin', 'logowanie', 'rejestracja', 'reset-hasla', 'moje-oferty', 'dodaj-oferte',
            'media', 'plik', 'api', 'webhook', 'health', 'wyloguj', 'potwierdz-email', 'sitemap', 'robots', 'theme', 'podglad-markdown'];
        if (in_array($slug, $reserved, true)) {
            $errors['slug'] = __('pages.slug_reserved');
        } elseif (DB::value('SELECT 1 FROM pages WHERE slug = ? AND id <> ?', [$slug, $id ?? 0]) !== null) {
            $errors['slug'] = __('fields.err_key_taken');
        }
        if ($errors !== []) {
            $page = ['id' => $id, 'title' => $title, 'slug' => $slug, 'body_md' => $body, 'in_menu' => (int) ($request->str('in_menu') !== '')];
            return Response::html(View::render('admin/page-edit', ['title' => $title ?: __('pages.new'), 'page' => $page, 'errors' => $errors, 'noindex' => true]), 422);
        }
        $data = ['title' => $title, 'slug' => $slug, 'body_md' => mb_substr($body, 0, 100000), 'in_menu' => (int) ($request->str('in_menu') !== '')];
        if ($id === null) {
            $data['menu_order'] = (int) DB::value('SELECT COALESCE(MAX(menu_order), 0) + 10 FROM pages');
            $id = DB::insert('pages', $data);
        } else {
            // Każda zmiana podbija wersję — zgody (np. regulaminu) wskazują wersję, którą zaakceptowano.
            DB::query('UPDATE pages SET title = ?, slug = ?, body_md = ?, in_menu = ?, version = version + 1 WHERE id = ?',
                [$data['title'], $data['slug'], $data['body_md'], $data['in_menu'], $id]);
        }
        Audit::log('page.save', 'page', $id, ['slug' => $slug], $request, Auth::id());
        Session::flash('success', __('admin.saved'));
        return Response::redirect(url('/admin/strony/' . $id));
    }

    // ------------------------------------------------------------------ ustawienia

    public const SETTINGS_FIELDS = ['name', 'tagline', 'locked_content_notice', 'theme.accent', 'theme.mode', 'cta.consultation_url', 'cta.consultation_label'];

    public function settings(Request $request): Response
    {
        return Response::view('admin/settings', $this->settingsData([]));
    }

    public function saveSettings(Request $request): Response
    {
        $portal = (array) Settings::get('portal', []);
        $in = (array) ($request->post['portal'] ?? []);
        $errors = [];
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            $errors['name'] = __('field.required', ['field' => __('settings.name')]);
        }
        $accent = trim((string) ($in['accent'] ?? ''));
        if (!preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $accent)) {
            $errors['accent'] = __('settings.err_color');
        }
        $cta = trim((string) ($in['consultation_url'] ?? ''));
        if ($cta !== '' && !preg_match('#^https?://#', $cta)) {
            $errors['consultation_url'] = __('validation.url');
        }
        if ($errors !== []) {
            return Response::html(View::render('admin/settings', $this->settingsData($errors, $in)), 422);
        }
        $portal['name'] = $name;
        $portal['tagline'] = mb_substr(trim((string) ($in['tagline'] ?? '')), 0, 200);
        $portal['locked_content_notice'] = mb_substr(trim((string) ($in['locked_content_notice'] ?? '')), 0, 300);
        $portal['theme'] = array_merge((array) ($portal['theme'] ?? []), ['accent' => $accent, 'mode' => ($in['mode'] ?? '') === 'dark' ? 'dark' : 'light']);
        $portal['cta'] = array_merge((array) ($portal['cta'] ?? []), ['consultation_url' => $cta, 'consultation_label' => mb_substr(trim((string) ($in['consultation_label'] ?? '')), 0, 60)]);
        Settings::set('portal', $portal);

        $terms = [];
        foreach ((array) ($request->post['terms'] ?? []) as $key => $text) {
            $text = trim((string) $text);
            if ($text !== '' && Lang::has((string) $key) && $text !== $this->defaultText((string) $key)) {
                $terms[$key] = mb_substr($text, 0, 300);
            }
        }
        Settings::set('terms', $terms);

        $ai = (array) Settings::get('ai', []);
        $aiIn = (array) ($request->post['ai'] ?? []);
        foreach (['enabled', 'extract_from_pdf', 'rewrite_description', 'moderation_hints'] as $flag) {
            $ai[$flag] = !empty($aiIn[$flag]);
        }
        $ai['monthly_budget_usd'] = max(0, min(10000, (float) str_replace(',', '.', (string) ($aiIn['monthly_budget_usd'] ?? ($ai['monthly_budget_usd'] ?? 30)))));
        Settings::set('ai', $ai);
        Audit::log('settings.save', null, null, [], $request, Auth::id());
        Session::flash('success', __('admin.saved'));
        return Response::redirect(url('/admin/ustawienia'));
    }

    public function campaign(Request $request): Response
    {
        if ($request->str('action') === 'enable') {
            CampaignMode::enable((int) $request->str('hours', '6'));
            Audit::log('campaign.enable', null, null, ['hours' => (int) $request->str('hours', '6')], $request, Auth::id());
        } else {
            CampaignMode::disable();
            Audit::log('campaign.disable', null, null, [], $request, Auth::id());
        }
        Session::flash('success', __('admin.saved'));
        return Response::redirect(url('/admin'));
    }

    // ------------------------------------------------------------------ dziennik

    public function audit(Request $request): Response
    {
        $where = ['1=1'];
        $args = [];
        $action = $request->str('action');
        if ($action !== '' && preg_match('/^[a-z0-9_.]+$/', $action)) {
            $where[] = 'a.action LIKE ?';
            $args[] = $action . '%';
        }
        $email = $request->str('email');
        if ($email !== '') {
            $where[] = 'u.email LIKE ?';
            $args[] = '%' . addcslashes($email, '%_\\') . '%';
        }
        $w = implode(' AND ', $where);
        $page = max(1, (int) $request->str('strona', '1'));
        $total = (int) DB::value("SELECT COUNT(*) FROM audit_log a LEFT JOIN users u ON u.id = a.user_id WHERE {$w}", $args);
        $rows = DB::fetchAll(
            "SELECT a.*, u.email FROM audit_log a LEFT JOIN users u ON u.id = a.user_id WHERE {$w} ORDER BY a.id DESC LIMIT 50 OFFSET " . (($page - 1) * 50),
            $args
        );
        return Response::view('admin/audit', [
            'title' => __('admin.audit'), 'rows' => $rows, 'total' => $total, 'page' => $page,
            'pages' => max(1, (int) ceil($total / 50)), 'action' => $action, 'email' => $email, 'noindex' => true,
        ]);
    }

    private function settingsData(array $errors, array $old = []): array
    {
        $termKeys = array_values(array_filter(array_keys(Lang::all()), static fn($k) => str_starts_with($k, 'term.') || $k === 'home.lead'));
        return [
            'title' => __('admin.settings'),
            'portal' => (array) Settings::get('portal', []),
            'terms' => (array) Settings::get('terms', []),
            'termKeys' => $termKeys,
            'defaults' => array_combine($termKeys, array_map(fn($k) => $this->defaultText($k), $termKeys)),
            'errors' => $errors,
            'old' => $old,
            'ai' => (array) Settings::get('ai', []),
            'aiKey' => \App\Integrations\Claude::hasKey(),
            'aiModel' => \App\Integrations\Claude::model(),
            'aiSpend' => \App\Integrations\Claude::monthSpend(),
            'aiCalls' => (int) DB::value("SELECT COUNT(*) FROM ai_usage WHERE created_at >= DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-01')"),
            'noindex' => true,
        ];
    }

    /** Tekst z lang/pl.php bez nadpisań presetu. */
    private function defaultText(string $key): string
    {
        static $lines = null;
        $lines ??= require APP_ROOT . '/lang/' . \App\Core\Config::get('app.locale', 'pl') . '.php';
        return (string) ($lines[$key] ?? '');
    }
}
