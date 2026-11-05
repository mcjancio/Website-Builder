<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Access\Gate;
use App\Ai\Assistant;
use App\Auth\Auth;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\UploadedFile;
use App\Listings\ListingService;
use App\Listings\MediaService;

/** Asystent AI w kreatorze (autor) i w moderacji (zespół). Wyniki zawsze trafiają do człowieka. */
final class AiController
{
    /** Oferta z PDF: nowy plik albo dokument już dodany do oferty. */
    public function extract(Request $request): Response
    {
        $listing = $this->owned($request);
        if ($listing instanceof Response) {
            return $listing;
        }
        $back = url('/moje-oferty/' . $listing['id'] . '/edycja/2') . '#asystent';
        if (!Assistant::enabled('extract') || !$this->limit('extract')) {
            return $this->fail($back, Assistant::enabled('extract') ? 'ai.err_limit' : 'ai.err_disabled');
        }
        $attachmentId = (int) $request->str('attachment_id');
        $files = UploadedFile::fromField($request->files, 'pdf');
        if ($files !== []) {
            if ($files[0]->ok() && $files[0]->mime() !== 'application/pdf') {
                return $this->fail($back, 'ai.err_pdf');
            }
            // Źródłowy dokument zostaje przy ofercie jako poufny — autor może go potem usunąć lub udostępnić.
            $r = MediaService::addAttachment($listing, $files[0], '', 3);
            if (!$r['ok']) {
                Session::flash('error', $r['error']);
                return Response::redirect($back);
            }
            $attachmentId = (int) $r['id'];
        }
        $att = $attachmentId > 0 ? \App\Core\DB::fetch('SELECT id, mime FROM attachments WHERE id = ? AND listing_id = ?', [$attachmentId, $listing['id']]) : null;
        if ($att === null || $att['mime'] !== 'application/pdf') {
            return $this->fail($back, 'ai.err_pdf');
        }
        Assistant::request('extract', (int) $listing['id'], Auth::id(), (int) $att['id']);
        Session::flash('notice', __('ai.queued'));
        return Response::redirect($back);
    }

    public function rewrite(Request $request): Response
    {
        $listing = $this->owned($request);
        if ($listing instanceof Response) {
            return $listing;
        }
        $back = url('/moje-oferty/' . $listing['id'] . '/edycja/3') . '#asystent';
        if (!Assistant::enabled('rewrite') || !$this->limit('rewrite')) {
            return $this->fail($back, Assistant::enabled('rewrite') ? 'ai.err_limit' : 'ai.err_disabled');
        }
        $notes = trim($request->str('notes'));
        if (mb_strlen($notes) < 30) {
            return $this->fail($back, 'ai.err_notes');
        }
        Assistant::request('rewrite', (int) $listing['id'], Auth::id(), null, $notes);
        Session::flash('notice', __('ai.queued'));
        return Response::redirect($back);
    }

    public function applyRewrite(Request $request): Response
    {
        $listing = $this->owned($request);
        if ($listing instanceof Response) {
            return $listing;
        }
        $task = Assistant::task((int) $request->params['task']);
        if ($task === null || (int) $task['listing_id'] !== (int) $listing['id'] || $task['kind'] !== 'rewrite') {
            return Response::view('errors/404', [], 404);
        }
        $part = $request->str('part');
        Assistant::applyRewrite((int) $task['id'], in_array($part, ['all', 'short'], true), in_array($part, ['all', 'full'], true));
        Session::flash('success', __('ai.applied'));
        return Response::redirect(url('/moje-oferty/' . $listing['id'] . '/edycja/' . ($part === 'short' ? 1 : 3)));
    }

    /** Stan zlecenia (odpytywany przez JS, gdy model pracuje w tle). */
    public function status(Request $request): Response
    {
        $task = Assistant::task((int) $request->params['task']);
        $listing = $task ? ListingService::find((int) $task['listing_id']) : null;
        $user = Auth::user();
        if ($task === null || $listing === null || (int) $listing['id'] !== (int) $request->params['id']
            || !(Gate::isOwner($user, $listing) || Gate::isStaff($user))) {
            return Response::json(['error' => 'not_found'], 404);
        }
        return Response::json(['status' => $task['status'], 'done' => !in_array($task['status'], ['queued', 'running'], true)]);
    }

    /** Moderator: podpowiedzi AI (zlecane też automatycznie przy wysłaniu oferty). */
    public function hints(Request $request): Response
    {
        $id = (int) $request->params['id'];
        if (ListingService::find($id) === null) {
            return Response::view('errors/404', [], 404);
        }
        if (!Assistant::enabled('hints')) {
            return $this->fail(url('/admin/moderacja/' . $id), 'ai.err_disabled');
        }
        Assistant::request('hints', $id, Auth::id());
        Session::flash('notice', __('ai.queued'));
        return Response::redirect(url('/admin/moderacja/' . $id) . '#ai');
    }

    private function owned(Request $request): array|Response
    {
        $listing = ListingService::find((int) $request->params['id']);
        $user = Auth::user();
        if ($listing === null || !Gate::isOwner($user, $listing)) {
            return Response::view('errors/404', [], 404);
        }
        if (!Gate::canEdit($user, $listing)) {
            Session::flash('error', __('wizard.not_editable'));
            return Response::redirect(url('/moje-oferty'));
        }
        return $listing;
    }

    /** Ochrona budżetu przed seryjnym klikaniem: 20 zleceń na godzinę na osobę. */
    private function limit(string $kind): bool
    {
        return RateLimiter::attempt('ai:' . $kind . ':user:' . Auth::id(), 20, 3600);
    }

    private function fail(string $back, string $key): Response
    {
        Session::flash('error', __($key));
        return Response::redirect($back);
    }
}
