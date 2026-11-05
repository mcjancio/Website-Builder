<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimalny, BEZPIECZNY Markdown dla stron treści i opisów ofert.
 * Najpierw escapowanie całego tekstu, potem zamiana składni — surowy HTML nigdy nie przechodzi.
 * Obsługa: ## nagłówki (h2–h4), akapity, **pogrubienie**, _kursywa_ / *kursywa*,
 * listy "- " i "1. ", [link](https://…), > cytat, pusta linia = nowy akapit.
 */
final class Markdown
{
    public static function toHtml(string $md): string
    {
        $lines = preg_split('/\R/', str_replace("\t", '    ', $md)) ?: [];
        $html = [];
        $para = [];
        $list = null; // 'ul' | 'ol'
        $quote = [];

        $flushPara = static function () use (&$para, &$html): void {
            if ($para !== []) {
                $html[] = '<p>' . implode('<br>', array_map([self::class, 'inline'], $para)) . '</p>';
                $para = [];
            }
        };
        $flushList = static function () use (&$list, &$html): void {
            if ($list !== null) {
                $html[] = "</{$list}>";
                $list = null;
            }
        };
        $flushQuote = static function () use (&$quote, &$html): void {
            if ($quote !== []) {
                $html[] = '<blockquote><p>' . implode('<br>', array_map([self::class, 'inline'], $quote)) . '</p></blockquote>';
                $quote = [];
            }
        };

        foreach ($lines as $line) {
            $trim = trim($line);

            if ($trim === '') {
                $flushPara();
                $flushList();
                $flushQuote();
                continue;
            }
            if (preg_match('/^(#{2,4})\s+(.+)$/', $trim, $m)) {
                $flushPara();
                $flushList();
                $flushQuote();
                $level = strlen($m[1]);
                $html[] = "<h{$level}>" . self::inline($m[2]) . "</h{$level}>";
                continue;
            }
            if (preg_match('/^(?:[-*]|(\d+)\.)\s+(.+)$/', $trim, $m)) {
                $flushPara();
                $flushQuote();
                $type = $m[1] !== '' ? 'ol' : 'ul';
                if ($list !== $type) {
                    $flushList();
                    $html[] = "<{$type}>";
                    $list = $type;
                }
                $html[] = '<li>' . self::inline($m[2]) . '</li>';
                continue;
            }
            if (str_starts_with($trim, '>')) {
                $flushPara();
                $flushList();
                $quote[] = ltrim(substr($trim, 1));
                continue;
            }
            $flushList();
            $flushQuote();
            $para[] = $trim;
        }
        $flushPara();
        $flushList();
        $flushQuote();

        return implode("\n", $html);
    }

    public static function inline(string $text): string
    {
        $s = e($text);
        // Linki: tylko http(s), mailto i ścieżki względne portalu (bez "//host").
        // Adresy chowamy pod znacznikami, żeby formatowanie (_ i *) nie psuło href.
        $hrefs = [];
        $s = (string) preg_replace_callback(
            '/\[([^\]]+)\]\(((?:https?:\/\/|mailto:|\/(?!\/))[^\s)]*)\)/',
            static function (array $m) use (&$hrefs): string {
                $hrefs[] = $m[2];
                $attrs = str_starts_with($m[2], 'http') ? ' rel="noopener" target="_blank"' : '';
                return '<a href="' . "\u{E000}" . (count($hrefs) - 1) . "\u{E001}" . '"' . $attrs . '>' . $m[1] . '</a>';
            },
            $s
        );
        $s = (string) preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $s);
        $s = (string) preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/u', '<em>$1</em>', $s);
        $s = (string) preg_replace('/(?<![\w])_(?!\s)(.+?)(?<!\s)_(?![\w])/u', '<em>$1</em>', $s);
        return (string) preg_replace_callback('/\x{E000}(\d+)\x{E001}/u', static fn($m) => $hrefs[(int) $m[1]], $s);
    }
}
