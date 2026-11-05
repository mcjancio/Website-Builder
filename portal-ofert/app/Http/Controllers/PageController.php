<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\DB;
use App\Core\Markdown;
use App\Core\Request;
use App\Core\Response;

/** Strony treści z tabeli `pages` (Zasady współpracy, Regulamin, Kontakt…). */
final class PageController
{
    public function show(Request $request): Response
    {
        $page = DB::fetch('SELECT slug, title, body_md, updated_at FROM pages WHERE slug = ?', [$request->params['slug']]);
        if ($page === null) {
            return Response::view('errors/404', [], 404);
        }
        return Response::view('page', [
            'title' => $page['title'],
            'page' => $page,
            'body' => Markdown::toHtml((string) $page['body_md']),
        ]);
    }
}
