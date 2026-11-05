<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Access\Gate;
use App\Auth\Audit;
use App\Auth\Auth;
use App\Core\DB;
use App\Core\Markdown;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Core\UploadedFile;
use App\Core\View;
use App\Listings\FieldInput;
use App\Listings\Fields;
use App\Listings\ListingService;
use App\Listings\MediaService;

/**
 * Kreator oferty: 1 Podstawy → 2 Parametry → 3 Opis i zdjęcia → 4 Dokumenty i wysyłka.
 * Każdy krok zapisuje szkic (także autozapis co 30 s z JS). Do moderacji wysyła się w kroku 4,
 * a portal pokazuje listę braków zamiast komunikatu o błędzie.
 */
final class ListingWizardController
{
    public const STEPS = [1 => 'basics', 2 => 'fields', 3 => 'description', 4 => 'documents'];

    public function create(Request $request): Response
    {
        return Response::view('listings/wizard', $this->viewData(null, 1, []));
    }

    public function store(Request $request): Response
    {
        [$core, $categoryId, $errors] = $this->basicsInput($request, null);
        if ($errors !== []) {
            return Response::html(View::render('listings/wizard', $this->viewData(null, 1, $errors, $request->post)), 422);
        }
        $id = ListingService::create((int) Auth::id(), (int) $categoryId, $core['title'], $core['short_desc']);
        Audit::log('listing.create', 'listing', $id, [], $request);
        return Response::redirect(url('/moje-oferty/' . $id . '/edycja/2'));
    }

    public function edit(Request $request): Response
    {
        $listing = $this->owned($request);
        if ($listing instanceof Response) {
            return $listing;
        }
        $step = (int) ($request->params['step'] ?? 1);
        return Response::view('listings/wizard', $this->viewData($listing, $step, []));
    }

    public function update(Request $request): Response
    {
        $listing = $this->owned($request);
        if ($listing instanceof Response) {
            return $listing;
        }
        $step = (int) $request->params['step'];
        $autosave = $request->header('X-Autosave') === '1';
        $state = ListingService::editable($listing);
        $errors = [];
        $result = 'saved';

        switch ($step) {
            case 1:
                [$core, $categoryId, $errors] = $this->basicsInput($request, $listing);
                if ($errors === []) {
                    $result = ListingService::save($listing, $core);
                    if ($categoryId !== null && !ListingService::isLive($listing) && $categoryId !== (int) $listing['category_id']) {
                        DB::update('listings', ['category_id' => $categoryId], ['id' => $listing['id']]);
                    }
                }
                break;
            case 2:
                $fields = Fields::forCategory($state['category_id']);
                [$values, $errors] = FieldInput::parse($fields, (array) ($request->post['f'] ?? []));
                // Zapisujemy poprawne wartości nawet przy błędach w innych polach — szkic nie ginie.
                foreach (array_keys($errors) as $bad) {
                    if (array_key_exists($bad, $state['values'])) {
                        $values[$bad] = $state['values'][$bad];
                    }
                }
                $result = ListingService::save($listing, [], $fields, $values);
                break;
            case 3:
                $core = [
                    'full_desc_md' => mb_substr($request->str('full_desc_md'), 0, 20000),
                    'confidential_md' => mb_substr($request->str('confidential_md'), 0, 20000),
                ];
                $result = ListingService::save($listing, $core);
                break;
            case 4:
                $meta = [];
                foreach ((array) ($request->post['att'] ?? []) as $attId => $a) {
                    $lvl = (int) ($a['access_level'] ?? 3);
                    $meta[(int) $attId] = [
                        'label' => mb_substr(trim((string) ($a['label'] ?? '')), 0, 200),
                        'access_level' => in_array($lvl, [1, 2, 3], true) ? $lvl : 3,
                    ];
                }
                $owned = array_map('intval', array_column(MediaService::attachments((int) $listing['id']), 'id'));
                $meta = array_intersect_key($meta, array_flip($owned));
                $result = ListingService::save($listing, [], null, null, $meta);
                break;
        }

        if ($autosave) {
            return Response::json(['saved' => $errors === [], 'errors' => $errors, 'at' => gmdate('c'),
                'message' => __('wizard.autosaved', ['time' => $this->localTime()])], $errors === [] ? 200 : 422);
        }
        if ($errors !== []) {
            $fresh = ListingService::find((int) $listing['id']);
            return Response::html(View::render('listings/wizard', $this->viewData($fresh, $step, $errors, $request->post)), 422);
        }
        if (in_array($result, ['back_to_draft', 'revision_back_to_draft'], true)) {
            Session::flash('notice', __('wizard.back_to_draft'));
        }

        $next = match ($request->str('go')) {
            'prev' => max(1, $step - 1),
            'stay' => $step,
            default => min(4, $step + 1),
        };
        return Response::redirect(url('/moje-oferty/' . $listing['id'] . '/edycja/' . $next));
    }

    public function submit(Request $request): Response
    {
        $listing = $this->owned($request);
        if ($listing instanceof Response) {
            return $listing;
        }
        $missing = ListingService::submit($listing, $request);
        if ($missing !== []) {
            Session::set('wizard_missing_' . $listing['id'], $missing);
            Session::flash('error', __('wizard.missing', ['count' => count($missing)]));
            return Response::redirect(url('/moje-oferty/' . $listing['id'] . '/edycja/4'));
        }
        Session::forget('wizard_missing_' . $listing['id']);
        Session::flash('success', __(ListingService::isLive($listing) ? 'wizard.submitted_revision' : 'wizard.submitted'));
        return Response::redirect(url('/moje-oferty'));
    }

    public function uploadImages(Request $request): Response
    {
        $listing = $this->owned($request);
        if ($listing instanceof Response) {
            return $listing;
        }
        $results = [];
        foreach (UploadedFile::fromField($request->files, 'images') as $file) {
            $results[] = MediaService::addImage($listing, $file);
        }
        return $this->uploadResponse($request, $listing, $results, 3, 'zdjecia');
    }

    public function uploadAttachments(Request $request): Response
    {
        $listing = $this->owned($request);
        if ($listing instanceof Response) {
            return $listing;
        }
        $level = $request->str('access_level') !== '' ? (int) $request->str('access_level') : null;
        $results = [];
        foreach (UploadedFile::fromField($request->files, 'files') as $file) {
            $results[] = MediaService::addAttachment($listing, $file, $request->str('label'), $level);
        }
        return $this->uploadResponse($request, $listing, $results, 4, 'dokumenty');
    }

    /** Akcje na zdjęciu/pliku: usun | okladka | w-gore | w-dol */
    public function mediaAction(Request $request): Response
    {
        $listing = $this->owned($request);
        if ($listing instanceof Response) {
            return $listing;
        }
        $table = $request->params['kind'] === 'zdjecia' ? 'media' : 'attachments';
        $itemId = (int) $request->params['item'];
        match ($request->str('action')) {
            'usun' => MediaService::remove($table, $listing, $itemId),
            'okladka' => $table === 'media' ? MediaService::setCover((int) $listing['id'], $itemId) : null,
            'w-gore' => MediaService::move($table, (int) $listing['id'], $itemId, -1),
            'w-dol' => MediaService::move($table, (int) $listing['id'], $itemId, 1),
            default => null,
        };
        $step = $table === 'media' ? 3 : 4;
        return Response::redirect(url('/moje-oferty/' . $listing['id'] . '/edycja/' . $step) . '#' . $request->params['kind']);
    }

    public function destroy(Request $request): Response
    {
        $listing = $this->owned($request);
        if ($listing instanceof Response) {
            return $listing;
        }
        if (ListingService::deleteDraft($listing)) {
            Audit::log('listing.delete_draft', 'listing', (int) $listing['id'], [], $request);
            Session::flash('success', __('wizard.deleted'));
        } else {
            Session::flash('error', __('wizard.cannot_delete'));
        }
        return Response::redirect(url('/moje-oferty'));
    }

    public function markdownPreview(Request $request): Response
    {
        return Response::json(['html' => Markdown::toHtml(mb_substr($request->str('text'), 0, 20000))]);
    }

    // ------------------------------------------------------------------

    /** @return array{0:array,1:?int,2:array} */
    private function basicsInput(Request $request, ?array $listing): array
    {
        $titleMax = (int) Settings::get('limits.title_max', 120);
        $shortMax = (int) Settings::get('limits.short_desc_max', 300);
        $title = trim((string) preg_replace('/\s+/u', ' ', $request->str('title')));
        $short = trim($request->str('short_desc'));
        $errors = [];
        if ($title === '') {
            $errors['title'] = __('field.required', ['field' => __('listing.title')]);
        } elseif (mb_strlen($title) > $titleMax) {
            $errors['title'] = __('field.too_long', ['field' => __('listing.title'), 'max' => $titleMax]);
        }
        if (mb_strlen($short) > $shortMax) {
            $errors['short_desc'] = __('field.too_long', ['field' => __('listing.short_desc'), 'max' => $shortMax]);
        } elseif ($short === '' && $listing === null) {
            $errors['short_desc'] = __('field.required', ['field' => __('listing.short_desc')]);
        }
        $categoryId = null;
        if ($listing === null || !ListingService::isLive($listing)) {
            $categoryId = (int) $request->str('category_id');
            if (DB::value('SELECT 1 FROM categories WHERE id = ? AND is_active = 1', [$categoryId]) === null) {
                $errors['category_id'] = __('field.required', ['field' => __('term.categories')]);
            }
        }
        return [['title' => $title, 'short_desc' => $short], $categoryId, $errors];
    }

    private function uploadResponse(Request $request, array $listing, array $results, int $step, string $anchor): Response
    {
        $errors = array_values(array_filter(array_map(static fn($r) => $r['error'] ?? null, $results)));
        $added = count(array_filter($results, static fn($r) => $r['ok']));
        if ($request->wantsJson()) {
            return Response::json(['added' => $added, 'errors' => $errors], $errors !== [] && $added === 0 ? 422 : 200);
        }
        if ($results === []) {
            Session::flash('error', __('upload.none'));
        } elseif ($errors !== []) {
            Session::flash('error', implode(' ', $errors));
        }
        if ($added > 0) {
            Session::flash('success', __(ListingService::isLive($listing) ? 'upload.added_pending' : 'upload.added', ['count' => $added]));
        }
        return Response::redirect(url('/moje-oferty/' . $listing['id'] . '/edycja/' . $step) . '#' . $anchor);
    }

    /** Oferta zalogowanego autora albo odpowiedź 404/403. */
    private function owned(Request $request): array|Response
    {
        $listing = ListingService::find((int) $request->params['id']);
        if ($listing === null || !Gate::isOwner(Auth::user(), $listing)) {
            return Response::view('errors/404', [], 404);
        }
        if (!Gate::canEdit(Auth::user(), $listing)) {
            Session::flash('error', __('wizard.not_editable'));
            return Response::redirect(url('/moje-oferty'));
        }
        return $listing;
    }

    private function viewData(?array $listing, int $step, array $errors, array $old = []): array
    {
        $state = $listing ? ListingService::editable($listing) : ['core' => [], 'values' => [], 'category_id' => null, 'revision' => null, 'attachments_meta' => []];
        $fields = Fields::forCategory($state['category_id']);
        $categories = DB::fetchAll('SELECT id, parent_id, name FROM categories WHERE is_active = 1 ORDER BY sort_order, name');
        $missing = $listing ? (Session::get('wizard_missing_' . $listing['id']) ?? []) : [];
        if ($listing && $step === 4) {
            $missing = FieldInput::missingRequired($fields, $state['values']);
            if (trim((string) ($state['core']['short_desc'] ?? '')) === '') {
                $missing = ['short_desc' => __('listing.short_desc')] + $missing;
            }
        }
        return [
            'title' => $listing ? __('wizard.edit_title', ['title' => $state['core']['title']]) : __('term.listing_add'),
            'listing' => $listing,
            'state' => $state,
            'step' => $step,
            'errors' => $errors,
            'old' => $old,
            'fields' => $fields,
            'categories' => $categories,
            'images' => $listing ? MediaService::images((int) $listing['id']) : [],
            'attachments' => $listing ? MediaService::attachments((int) $listing['id']) : [],
            'isLive' => $listing ? ListingService::isLive($listing) : false,
            'missing' => $missing,
            'limits' => (array) Settings::get('limits', []),
            'accessLevels' => (array) Settings::get('access_levels', []),
            'attachmentTypes' => MediaService::attachmentTypes(),
            'noindex' => true,
        ];
    }

    private function localTime(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone((string) config('app.timezone', 'Europe/Warsaw'))))->format('H:i');
    }
}
