<?php

declare(strict_types=1);

namespace App\Integrations;

use App\Core\Config;
use App\Core\DB;
use App\Core\Http;
use App\Core\HttpException;
use App\Core\Settings;

/**
 * Claude (Anthropic Messages API) przez cURL — bez SDK i Composera, jak reszta portalu.
 *  - klucz i model z config.php (ai.anthropic_api_key, ai.model),
 *  - odpowiedź w JSON zgodnym ze schematem (output_config.format = json_schema),
 *  - odmowa modelu: serwerowe przejście na model zapasowy (fallbacks: "default"),
 *  - każde wywołanie trafia do ai_usage z kosztem; po przekroczeniu miesięcznego budżetu funkcje AI się wyłączają.
 */
final class Claude
{
    private const URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2026-10-21';
    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    /** Ceny USD za 1 mln tokenów [wejście, wyjście]; nadpisanie: config ai.prices. */
    private const PRICES = [
        'claude-sonnet-5-5' => [2.00, 10.00],
        'claude-opus-5-5' => [4.00, 20.00],
        'claude-haiku-5-5' => [0.10, 0.50],
        'claude-opus-4-8' => [5.00, 25.00],
        'claude-sonnet-5' => [2.00, 10.00],
    ];

    public static function model(): string
    {
        return (string) (Config::get('ai.model') ?: 'claude-sonnet-5-5');
    }

    public static function hasKey(): bool
    {
        return trim((string) Config::get('ai.anthropic_api_key', '')) !== '';
    }

    /** AI włączone w ustawieniach portalu, jest klucz i nie wyczerpano budżetu. */
    public static function available(?string $feature = null): bool
    {
        $ai = (array) Settings::get('ai', []);
        if (empty($ai['enabled']) || !self::hasKey()) {
            return false;
        }
        if ($feature !== null && array_key_exists($feature, $ai) && !$ai[$feature]) {
            return false;
        }
        return !self::budgetExceeded();
    }

    public static function monthSpend(): float
    {
        return (float) DB::value("SELECT COALESCE(SUM(cost_usd), 0) FROM ai_usage WHERE created_at >= DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-01')");
    }

    public static function budget(): float
    {
        return (float) (Settings::get('ai.monthly_budget_usd', 30) ?? 30);
    }

    public static function budgetExceeded(): bool
    {
        return self::budget() > 0 && self::monthSpend() >= self::budget();
    }

    /**
     * Jedno wywołanie z odpowiedzią JSON zgodną ze schematem.
     * @param array<int,array> $content  bloki treści wiadomości użytkownika (document, text)
     * @param array<string,mixed> $schema JSON Schema odpowiedzi
     * @param array{feature:string,user_id?:?int,listing_id?:?int} $meta
     * @return array<string,mixed> zdekodowana odpowiedź
     * @throws AiException
     */
    public static function json(string $system, array $content, array $schema, array $meta, int $maxTokens = 16000): array
    {
        if (!self::hasKey()) {
            throw new AiException(__('ai.err_not_configured'));
        }
        if (self::budgetExceeded()) {
            throw new AiException(__('ai.err_budget'));
        }
        $model = self::model();
        $payload = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $content]],
            'output_config' => [
                'effort' => (string) (Config::get('ai.effort') ?: 'medium'),
                'format' => ['type' => 'json_schema', 'schema' => $schema],
            ],
            'fallbacks' => 'default',
        ];
        $started = microtime(true);
        try {
            $res = Http::request('POST', self::URL, [
                'Content-Type' => 'application/json',
                'x-api-key' => (string) Config::get('ai.anthropic_api_key'),
                'anthropic-version' => self::API_VERSION,
                'anthropic-beta' => self::FALLBACK_BETA,
            ], json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), (int) (Config::get('ai.timeout') ?: 180));
        } catch (HttpException $e) {
            self::log($meta, $model, [], 'error', $e->getMessage(), $started);
            throw new AiException(__('ai.err_connection'), 0, $e);
        }

        $body = $res['json'] ?? [];
        if ($res['status'] !== 200 || !is_array($body)) {
            $msg = 'HTTP ' . $res['status'] . ': ' . mb_substr((string) ($body['error']['message'] ?? $res['body']), 0, 300);
            self::log($meta, $model, [], 'error', $msg, $started);
            throw new AiException(in_array($res['status'], [429, 500, 502, 503, 529], true) ? __('ai.err_busy') : __('ai.err_api'), $res['status']);
        }
        $usedModel = (string) ($body['model'] ?? $model);
        $usage = self::usage($body);
        if (($body['stop_reason'] ?? '') === 'refusal') {
            self::log($meta, $usedModel, $usage, 'refused', (string) ($body['stop_details']['category'] ?? ''), $started);
            throw new AiException(__('ai.err_refused'));
        }
        if (($body['stop_reason'] ?? '') === 'max_tokens') {
            self::log($meta, $usedModel, $usage, 'error', 'max_tokens', $started);
            throw new AiException(__('ai.err_too_long'));
        }
        $text = '';
        foreach ((array) ($body['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= (string) $block['text'];
            }
        }
        $data = json_decode($text, true);
        if (!is_array($data)) {
            self::log($meta, $usedModel, $usage, 'error', 'Odpowiedź nie jest JSON-em', $started);
            throw new AiException(__('ai.err_api'));
        }
        self::log($meta, $usedModel, $usage, 'ok', null, $started);
        return $data;
    }

    /** Blok dokumentu PDF (base64) do treści wiadomości. */
    public static function pdfBlock(string $path): array
    {
        return ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode((string) file_get_contents($path))]];
    }

    /**
     * Zużycie z odpowiedzi. Przy przejściu na model zapasowy usage.iterations opisuje każdą próbę
     * (każda rozliczana po stawkach swojego modelu) — sumujemy je, inaczej bierzemy usage główne.
     * @return array<int,array{model:string,in:int,out:int}>
     */
    private static function usage(array $body): array
    {
        $out = [];
        foreach ((array) ($body['usage']['iterations'] ?? []) as $it) {
            $out[] = ['model' => (string) ($it['model'] ?? $body['model'] ?? self::model()), 'in' => self::inTokens($it), 'out' => (int) ($it['output_tokens'] ?? 0)];
        }
        if ($out === []) {
            $u = (array) ($body['usage'] ?? []);
            $out[] = ['model' => (string) ($body['model'] ?? self::model()), 'in' => self::inTokens($u), 'out' => (int) ($u['output_tokens'] ?? 0)];
        }
        return $out;
    }

    private static function inTokens(array $u): int
    {
        return (int) ($u['input_tokens'] ?? 0) + (int) ($u['cache_creation_input_tokens'] ?? 0) + (int) ($u['cache_read_input_tokens'] ?? 0);
    }

    public static function cost(string $model, int $in, int $out): float
    {
        $prices = (array) (Config::get('ai.prices.' . $model) ?? self::PRICES[$model] ?? self::PRICES['claude-opus-5-5']);
        return round($in / 1e6 * (float) $prices[0] + $out / 1e6 * (float) $prices[1], 5);
    }

    private static function log(array $meta, string $model, array $usage, string $status, ?string $error, float $started): void
    {
        $in = array_sum(array_column($usage, 'in'));
        $out = array_sum(array_column($usage, 'out'));
        $cost = array_sum(array_map(static fn($u) => self::cost($u['model'], $u['in'], $u['out']), $usage));
        DB::insert('ai_usage', [
            'feature' => mb_substr((string) $meta['feature'], 0, 30),
            'model' => mb_substr($model, 0, 60),
            'user_id' => $meta['user_id'] ?? null,
            'listing_id' => $meta['listing_id'] ?? null,
            'input_tokens' => $in,
            'output_tokens' => $out,
            'cost_usd' => $cost,
            'status' => $status,
            'error' => $error !== null ? mb_substr($error, 0, 500) : null,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);
    }
}
