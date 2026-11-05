<?php

declare(strict_types=1);

namespace App\Integrations;

use App\Core\Config;
use App\Core\Http;
use App\Core\Logger;

/**
 * GetResponse API v3 — dopisanie kontaktu do listy portalu (zadanie kolejki „getresponse.upsert”).
 * Telefon idzie do pola własnego (getresponse.phone_field_id) w formacie +48…, bez walidacji GR,
 * która wcześniej odrzucała numery. Brak api_key = integracja wyłączona (zadanie kończy się bez błędu).
 */
final class GetResponse
{
    public static function isConfigured(): bool
    {
        return Config::get('getresponse.api_key', '') !== '' && Config::get('getresponse.list_token', '') !== '';
    }

    /** @throws \RuntimeException przy błędzie API (zadanie wróci do kolejki) */
    public static function upsertContact(string $email, string $name, ?string $phone, array $tags = []): void
    {
        if (!self::isConfigured()) {
            return;
        }
        $data = [
            'email' => $email,
            'name' => $name,
            'campaign' => ['campaignId' => (string) Config::get('getresponse.list_token')],
        ];
        $fieldId = (string) Config::get('getresponse.phone_field_id', '');
        if ($phone !== null && $fieldId !== '') {
            $data['customFieldValues'] = [['customFieldId' => $fieldId, 'value' => [$phone]]];
        }
        $tagIds = array_values(array_filter(array_map(
            static fn($t) => Config::get('getresponse.tags.' . $t), $tags
        )));
        if ($tagIds !== []) {
            $data['tags'] = array_map(static fn($id) => ['tagId' => (string) $id], $tagIds);
        }

        $res = Http::postJson('https://api.getresponse.com/v3/contacts', $data, ['X-Auth-Token' => 'api-key ' . Config::get('getresponse.api_key')]);
        // 202 = przyjęty; 409 = kontakt już jest na liście — oba w porządku.
        if (in_array($res['status'], [202, 409], true)) {
            return;
        }
        Logger::warning('GetResponse odrzucił kontakt', ['status' => $res['status'], 'body' => mb_substr($res['body'], 0, 500)]);
        if ($res['status'] >= 500 || $res['status'] === 429) {
            throw new \RuntimeException("GetResponse HTTP {$res['status']} — ponowię później.");
        }
        // 4xx (np. zły format) — ponawianie nic nie da; zostaje wpis w logu.
    }
}
