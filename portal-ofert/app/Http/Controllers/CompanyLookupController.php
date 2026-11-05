<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Integrations\MfVatWhitelist;

/** /api/firma/{nip} — autouzupełnianie danych firmy w formularzu rejestracji (JSON). */
final class CompanyLookupController
{
    public function show(Request $request): Response
    {
        $nip = (string) $request->params['nip'];
        if (!Validator::nipValid($nip)) {
            return Response::json(['found' => false, 'error' => __('validation.nip')], 422);
        }
        if (!RateLimiter::attempt('nip:ip:' . $request->ip(), 30, 3600)) {
            return Response::json(['found' => false, 'error' => __('errors.429.text')], 429);
        }
        $c = MfVatWhitelist::lookup($nip);
        return Response::json($c === null
            ? ['found' => false]
            : ['found' => true, 'company' => array_intersect_key($c, array_flip(['name', 'address', 'postal_code', 'city', 'vat_status']))]
        )->withHeader('Cache-Control', 'no-store');
    }
}
