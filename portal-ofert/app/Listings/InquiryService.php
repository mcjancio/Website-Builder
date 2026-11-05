<?php

declare(strict_types=1);

namespace App\Listings;

use App\Core\Config;
use App\Core\DB;
use App\Core\Mailer;
use App\Core\Queue;
use App\Core\Request;

/**
 * Zapytanie o ofertę (lead). Zapis w bazie + zgoda (RODO) + e-mail do admina i autora oferty
 * + opcjonalny webhook do CRM. Wszystko poza zapisem idzie przez kolejkę.
 */
final class InquiryService
{
    /**
     * @param array{name:string,email:string,phone:?string,message:string} $data
     */
    public static function create(?array $listing, ?int $userId, array $data, Request $request): int
    {
        $ip = inet_pton($request->ip()) ?: inet_pton('0.0.0.0');
        $id = DB::transaction(static function () use ($listing, $userId, $data, $request, $ip): int {
            $consentId = DB::insert('consents', [
                'user_id' => $userId, 'email' => $data['email'], 'type' => 'inquiry',
                'granted' => 1, 'ip' => $ip, 'user_agent' => $request->userAgent() ?: null,
            ]);
            $utm = array_filter(array_intersect_key($request->query, array_flip(['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'fbclid', 'gclid'])), 'is_string');
            $id = DB::insert('inquiries', [
                'listing_id' => $listing['id'] ?? null,
                'user_id' => $userId,
                'name' => $data['name'],
                'email' => $data['email'],
                'phone_e164' => $data['phone'],
                'message' => $data['message'],
                'consent_id' => $consentId,
                'utm' => $utm !== [] ? json_encode($utm, JSON_UNESCAPED_UNICODE) : null,
                'ip' => $ip,
            ]);
            if ($listing !== null) {
                Stats::bump((int) $listing['id'], 'inquiries');
            }
            return $id;
        });

        $base = rtrim((string) Config::get('app.url'), '/');
        $mail = [
            'name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'], 'message' => $data['message'],
            'title' => $listing['title'] ?? null,
            'url' => $listing ? $base . url('/oferta/' . $listing['slug']) : null,
        ];
        $to = array_column(DB::fetchAll("SELECT email FROM users WHERE role = 'admin' AND status = 'active'"), 'email');
        if ($listing !== null) {
            $to[] = (string) DB::value('SELECT email FROM users WHERE id = ?', [$listing['user_id']]);
        }
        foreach (array_unique(array_filter($to)) as $email) {
            Mailer::queue($email, 'inquiry-new', $mail);
        }
        if (Config::get('webhook.url', '') !== '') {
            Queue::push('webhook', ['event' => 'inquiry.created', 'data' => ['id' => $id, 'listing_id' => $listing['id'] ?? null] + $mail]);
        }
        return $id;
    }
}
