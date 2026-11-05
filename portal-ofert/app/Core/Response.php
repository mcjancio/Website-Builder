<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    /**
     * Nagłówki bezpieczeństwa wysyłane zawsze (dublują .htaccess — na wypadek
     * hostingu bez mod_headers). CSP bez 'unsafe-inline': cały CSS/JS w plikach.
     */
    public const SECURITY_HEADERS = [
        'Content-Security-Policy' => "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; font-src 'self'; connect-src 'self'; frame-src https://www.youtube-nocookie.com https://player.vimeo.com; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'",
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
        'Cross-Origin-Opener-Policy' => 'same-origin',
    ];

    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = [],
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return new self($body, $status, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function text(string $body, int $status = 200, string $type = 'text/plain'): self
    {
        return new self($body, $status, ['Content-Type' => $type . '; charset=utf-8']);
    }

    public static function redirect(string $to, int $status = 302): self
    {
        // Tylko adresy wewnętrzne — ochrona przed open redirect.
        if (!str_starts_with($to, '/') || str_starts_with($to, '//') || str_starts_with($to, '/\\') || preg_match('/[\r\n]/', $to)) {
            $to = url('/');
        }
        return new self('', $status, ['Location' => $to]);
    }

    public static function view(string $template, array $data = [], int $status = 200): self
    {
        return self::html(View::render($template, $data), $status);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach (self::SECURITY_HEADERS as $name => $value) {
                if (!isset($this->headers[$name])) {
                    header($name . ': ' . $value);
                }
            }
            if (Config::get('app.hsts', true) && (($_SERVER['HTTPS'] ?? 'off') !== 'off')) {
                header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
            }
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
            Cookies::flush();
        }
        echo $this->body;
    }
}
