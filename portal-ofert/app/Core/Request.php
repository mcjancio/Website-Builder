<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    /** Parametry ścieżki z routera, np. {id}. */
    public array $params = [];

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $server = [],
        public readonly array $cookies = [],
        public readonly array $files = [],
        private ?string $body = null,
    ) {
    }

    /** Surowa treść żądania (np. JSON webhooka — potrzebna do sprawdzenia podpisu HMAC). */
    public function rawBody(): string
    {
        if ($this->body === null) {
            $this->body = PHP_SAPI === 'cli' ? '' : (string) file_get_contents('php://input');
        }
        return $this->body;
    }

    public function cookie(string $name): ?string
    {
        $v = $this->cookies[$name] ?? null;
        return is_string($v) ? $v : null;
    }

    public static function fromGlobals(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = rawurldecode((string) parse_url($uri, PHP_URL_PATH));

        $base = rtrim((string) Config::get('app.base_path', ''), '/');
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            self::normalizePath($path),
            $_GET,
            $_POST,
            $_SERVER,
            $_COOKIE,
            $_FILES,
        );
    }

    /** Usuwa końcowy ukośnik (poza "/") i zdublowane ukośniki. */
    public static function normalizePath(string $path): string
    {
        $path = '/' . trim((string) preg_replace('#/+#', '/', $path), '/');
        return $path;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function str(string $key, string $default = ''): string
    {
        $v = $this->input($key, $default);
        return is_string($v) ? trim($v) : $default;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $v = $this->server[$key] ?? null;
        return is_string($v) ? $v : null;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function isHttps(): bool
    {
        if (($this->server['HTTPS'] ?? '') !== '' && strtolower((string) $this->server['HTTPS']) !== 'off') {
            return true;
        }
        return $this->fromTrustedProxy() && strtolower((string) $this->header('X-Forwarded-Proto')) === 'https';
    }

    /** IP klienta; X-Forwarded-For brany pod uwagę tylko od zaufanych proxy (np. Cloudflare). */
    public function ip(): string
    {
        $remote = (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
        if ($this->fromTrustedProxy()) {
            $forwarded = $this->header('CF-Connecting-IP') ?? $this->header('X-Forwarded-For');
            if ($forwarded) {
                $first = trim(explode(',', $forwarded)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP)) {
                    return $first;
                }
            }
        }
        return $remote;
    }

    public function userAgent(): string
    {
        return mb_substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function wantsJson(): bool
    {
        return str_contains((string) $this->header('Accept'), 'application/json')
            || $this->header('X-Requested-With') === 'XMLHttpRequest';
    }

    private function fromTrustedProxy(): bool
    {
        $trusted = (array) Config::get('security.trusted_proxies', []);
        return in_array($this->server['REMOTE_ADDR'] ?? '', $trusted, true);
    }
}
