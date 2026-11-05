<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Access\AccessService;
use App\Access\Gate;
use App\Auth\Auth;
use App\Core\DB;
use App\Core\Markdown;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;
use App\Http\Middleware\RequireVerifiedEmail;
use App\Listings\Favorites;
use App\Listings\Fields;
use App\Listings\InquiryService;
use App\Listings\ListingQuery;
use App\Listings\ListingService;
use App\Listings\MediaService;
use App\Listings\Stats;
use App\Listings\ValueStore;

/** Publiczna część: lista ofert z filtrami, strona oferty, zapytanie, prośba o dostęp L3. */
final class ListingsController
{
    public function index(Request $request): Response
    {
        $user = Auth::user();
        $level = ListingQuery::levelFor($user);
        $result = ListingQuery::search($request->query, $level);
        $category = isset($result['applied']['kategoria'])
            ? DB::fetch('SELECT name, slug FROM categories WHERE slug = ?', [$result['applied']['kategoria']]) : null;

        return Response::view('listings/index', [
            'title' => $category['name'] ?? __('term.listings'),
            'description' => (string) setting('portal.tagline', ''),
            'result' => $result,
            'level' => $level,
            'filterFields' => ListingQuery::filterFields($level),
            'memberFilters' => $level < 2 && count(ListingQuery::filterFields(2)) > count(ListingQuery::filterFields(1)),
            'sortOptions' => ListingQuery::sortOptions($level),
            'categories' => DB::fetchAll('SELECT id, parent_id, slug, name FROM categories WHERE is_active = 1 ORDER BY sort_order, name'),
            'query' => $request->query,
            'user' => $user,
            // Strony wyników 2+ i kombinacje filtrów nie trafiają do indeksu wyszukiwarek (duplikaty).
            'noindex' => count(array_diff_key($result['applied'], ['kategoria' => 1])) > 0 || $result['page'] > 1,
            'canonical' => url('/oferty', $category ? ['kategoria' => $category['slug']] : []),
        ]);
    }

    public function show(Request $request): Response
    {
        $listing = ListingService::find((int) $request->params['id']);
        $user = Auth::user();
        if ($listing === null || !Gate::canView($user, $listing)) {
            return Response::view('errors/404', [], 404);
        }
        // Adres kanoniczny (np. po zmianie tytułu stary link przekierowuje na nowy).
        if ($request->params['slug'] . '-' . $request->params['id'] !== $listing['slug']) {
            return Response::redirect(url('/oferta/' . $listing['slug']), 301);
        }
        Stats::recordView($listing, $user, $request);
        return Response::view('listings/show', $this->showData($listing, $user, [], []));
    }

    public function inquiry(Request $request): Response
    {
        $listing = ListingService::find((int) $request->params['id']);
        $user = Auth::user();
        if ($listing === null || !Gate::canView($user, $listing)) {
            return Response::view('errors/404', [], 404);
        }
        $back = url('/oferta/' . $listing['slug']) . '#zapytanie';

        // Pole-pułapka: wypełniają je boty. Udajemy sukces, nic nie zapisujemy.
        if ($request->str('website') !== '') {
            Session::flash('success', __('inquiry.sent'));
            return Response::redirect($back);
        }
        if (!RateLimiter::attempt('inquiry:ip:' . $request->ip(), 5, 3600)) {
            return Response::html(View::render('listings/show', $this->showData($listing, $user, ['message' => __('errors.429.text')], $request->post)), 429);
        }

        $v = Validator::make($request->post, [
            'name' => 'required|maxlength:150',
            'email' => 'required|email',
            'phone' => 'phone_pl',
            'message' => 'required|minlength:10|maxlength:3000',
            'consent' => 'accepted',
        ], [
            'name' => __('inquiry.name'), 'email' => __('register.email'), 'phone' => __('inquiry.phone'),
            'message' => __('inquiry.message'), 'consent' => __('inquiry.consent_short'),
        ]);
        if ($v->fails()) {
            return Response::html(View::render('listings/show', $this->showData($listing, $user, $v->errors(), $request->post)), 422);
        }
        $d = $v->validated();
        InquiryService::create($listing, $user['id'] ?? null, [
            'name' => $d['name'], 'email' => mb_strtolower($d['email']), 'phone' => $d['phone'], 'message' => $d['message'],
        ], $request);
        Session::flash('success', __('inquiry.sent'));
        return Response::redirect($back);
    }

    public function requestAccess(Request $request): Response
    {
        $listing = ListingService::find((int) $request->params['id']);
        $user = Auth::user();
        if ($listing === null || !Gate::canView($user, $listing)) {
            return Response::view('errors/404', [], 404);
        }
        $back = url('/oferta/' . $listing['slug']) . '#dostep';
        if (!RequireVerifiedEmail::isVerifiedOrInGrace($user)) {
            Session::flash('error', __('verify_email.required'));
            return Response::redirect($back);
        }
        if (!RateLimiter::attempt('access:user:' . $user['id'], 20, 86400)) {
            Session::flash('error', __('errors.429.text'));
            return Response::redirect($back);
        }
        $result = AccessService::request((int) $listing['id'], (int) $user['id'], $request->str('message'), $request);
        Session::flash('success', __('access.request_' . $result));
        return Response::redirect($back);
    }

    private function showData(array $listing, ?array $user, array $errors, array $old): array
    {
        $level = Gate::level($user, $listing);
        $fields = Fields::forCategory((int) $listing['category_id']);
        $values = ValueStore::load((int) $listing['id']);
        $groups = [];
        $hidden = 0;
        $videos = [];
        foreach (Fields::grouped($fields) as $group => $gf) {
            foreach ($gf as $key => $f) {
                if (!array_key_exists($key, $values)) {
                    continue;
                }
                if ($f['access_level'] > $level) {
                    $hidden++;
                    continue;
                }
                if ($f['type'] === 'url_list') {
                    foreach ((array) $values[$key] as $u) {
                        $videos[] = ['url' => $u, 'embed' => \App\Listings\Display::embedUrl($u), 'label' => $f['label']];
                    }
                    continue;
                }
                $groups[$group][$key] = $f;
            }
        }
        $liveImages = array_values(array_filter(MediaService::images((int) $listing['id'], true),
            static fn($m) => (int) $m['access_level'] <= $level));
        usort($liveImages, static fn($a, $b) => [-(int) $a['is_cover'], (int) $a['sort_order']] <=> [-(int) $b['is_cover'], (int) $b['sort_order']]);
        $attachments = MediaService::attachments((int) $listing['id'], true);
        $category = DB::fetch('SELECT c.name, c.slug, p.name AS parent_name, p.slug AS parent_slug FROM categories c LEFT JOIN categories p ON p.id = c.parent_id WHERE c.id = ?', [$listing['category_id']]);
        $isOwner = Gate::isOwner($user, $listing);

        if ($user !== null && $old === [] && !$isOwner) {
            $old = ['name' => trim($user['first_name'] . ' ' . $user['last_name']), 'email' => $user['email'], 'phone' => $user['phone_e164']];
        }
        $cover = $liveImages[0] ?? null;
        return [
            'title' => $listing['title'],
            'description' => $listing['short_desc'],
            'listing' => $listing,
            'level' => $level,
            'values' => $values,
            'groups' => $groups,
            'hiddenCount' => $hidden,
            'videos' => $videos,
            'images' => $liveImages,
            'attachments' => $attachments,
            'fullDesc' => $level >= Gate::MEMBERS ? Markdown::toHtml((string) $listing['full_desc_md']) : null,
            'hasFullDesc' => trim((string) $listing['full_desc_md']) !== '',
            'confidential' => $level >= Gate::GRANTED ? Markdown::toHtml((string) $listing['confidential_md']) : null,
            'hasConfidential' => trim((string) $listing['confidential_md']) !== '',
            'category' => $category,
            'user' => $user,
            'isOwner' => $isOwner,
            'accessStatus' => $user !== null ? AccessService::statusFor((int) $listing['id'], (int) $user['id']) : null,
            'isFavorite' => $user !== null && Favorites::has((int) $user['id'], (int) $listing['id']),
            'errors' => $errors,
            'old' => $old,
            'canonical' => url('/oferta/' . $listing['slug']),
            'ogImage' => $cover !== null && $listing['status'] === 'published' && (int) $cover['access_level'] === 1
                ? rtrim((string) config('app.url'), '/') . url('/media/' . $cover['id'] . '/1600') : null,
            'noindex' => $listing['status'] !== 'published',
        ];
    }
}
