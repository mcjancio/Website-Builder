<?php

declare(strict_types=1);

namespace App\Import;

/**
 * Treść wpisu WordPressa (HTML z edytora / page buildera) → Markdown obsługiwany przez portal
 * (## nagłówki, akapity, **pogrubienie**, _kursywa_, listy, [link](https://…), > cytat).
 * Wszystko inne (style, skrypty, iframe, shortcody [xyz]) jest usuwane — zostaje sam tekst.
 */
final class HtmlToMarkdown
{
    public static function convert(string $html): string
    {
        $html = (string) preg_replace('/\[\/?[a-z_\-]+[^\]]*\]/i', '', $html); // shortcody WP
        if (trim(strip_tags($html)) === '') {
            return '';
        }
        // wpautop: WordPress trzyma akapity jako puste linie, bez <p>
        if (!preg_match('/<(p|div|h[1-6]|ul|ol|blockquote)\b/i', $html)) {
            $html = '<p>' . preg_replace('/\R{2,}/', '</p><p>', trim($html)) . '</p>';
            $html = (string) preg_replace('/\R/', '<br>', $html);
        }
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="root">' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        $root = $doc->getElementById('root');
        $md = $root ? self::blocks($root) : '';
        $md = (string) preg_replace("/[ \t]+\n/", "\n", $md);
        $md = (string) preg_replace("/\n{3,}/", "\n\n", $md);
        return trim($md);
    }

    private static function blocks(\DOMNode $node): string
    {
        $out = '';
        $inline = '';
        $flush = static function () use (&$out, &$inline): void {
            $t = trim((string) preg_replace('/[ \t]+/', ' ', $inline));
            if ($t !== '') {
                $out .= $t . "\n\n";
            }
            $inline = '';
        };
        foreach ($node->childNodes as $child) {
            $tag = $child instanceof \DOMElement ? strtolower($child->tagName) : '#text';
            switch ($tag) {
                case 'script': case 'style': case 'iframe': case 'noscript': case 'form': case 'svg':
                    break;
                case 'h1': case 'h2': case 'h3': case 'h4': case 'h5': case 'h6':
                    $flush();
                    $level = min(4, max(2, (int) $tag[1]));
                    $text = trim(self::inline($child));
                    $text !== '' && $out .= str_repeat('#', $level) . ' ' . str_replace("\n", ' ', $text) . "\n\n";
                    break;
                case 'ul': case 'ol':
                    $flush();
                    $n = 1;
                    foreach ($child->childNodes as $li) {
                        if ($li instanceof \DOMElement && strtolower($li->tagName) === 'li') {
                            $text = trim(str_replace("\n", ' ', self::inline($li)));
                            if ($text !== '') {
                                $out .= ($tag === 'ol' ? ($n++) . '. ' : '- ') . $text . "\n";
                            }
                        }
                    }
                    $out .= "\n";
                    break;
                case 'blockquote':
                    $flush();
                    $text = trim(self::blocks($child));
                    $text !== '' && $out .= implode("\n", array_map(static fn($l) => '> ' . $l, array_filter(explode("\n", $text), static fn($l) => trim($l) !== ''))) . "\n\n";
                    break;
                case 'p': case 'div': case 'section': case 'article': case 'figure': case 'table': case 'tr': case 'td': case 'th': case 'tbody': case 'thead':
                    $flush();
                    $inner = self::hasBlockChildren($child) ? self::blocks($child) : trim(self::inline($child));
                    if (trim($inner) !== '') {
                        $out .= rtrim($inner) . "\n\n";
                    }
                    break;
                case 'hr':
                    $flush();
                    break;
                default:
                    $inline .= self::inline($child, true);
            }
        }
        $flush();
        return $out;
    }

    private static function hasBlockChildren(\DOMNode $node): bool
    {
        foreach ($node->childNodes as $c) {
            if ($c instanceof \DOMElement && preg_match('/^(p|div|h[1-6]|ul|ol|blockquote|section|table|tr|td|figure)$/i', $c->tagName)) {
                return true;
            }
        }
        return false;
    }

    private static function inline(\DOMNode $node, bool $self = false): string
    {
        if ($node instanceof \DOMText) {
            return (string) preg_replace('/\s+/u', ' ', $node->wholeText);
        }
        if (!$node instanceof \DOMElement) {
            return '';
        }
        $tag = strtolower($node->tagName);
        if (in_array($tag, ['script', 'style', 'iframe', 'img', 'svg', 'noscript'], true)) {
            return '';
        }
        if ($tag === 'br') {
            return "\n";
        }
        $text = '';
        foreach ($node->childNodes as $c) {
            $text .= self::inline($c, true);
        }
        $core = trim($text);
        if ($core === '') {
            return $text === '' ? '' : ' ';
        }
        $lead = $text !== ltrim($text) ? ' ' : '';
        $trail = $text !== rtrim($text) ? ' ' : '';
        return $lead . match ($tag) {
            'strong', 'b' => '**' . $core . '**',
            'em', 'i' => '_' . $core . '_',
            'a' => preg_match('#^https?://#', (string) $node->getAttribute('href')) && $core !== $node->getAttribute('href')
                ? '[' . str_replace(['[', ']'], '', $core) . '](' . str_replace([' ', ')'], ['%20', '%29'], $node->getAttribute('href')) . ')'
                : $core,
            default => $core,
        } . $trail;
    }
}
