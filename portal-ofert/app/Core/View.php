<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Szablony PHP w katalogu views/. Widok może wybrać layout:
 *   <?php $layout = 'layouts/base'; $title = 'Tytuł'; ?>
 * Domyślnie strony renderują się w layouts/base; partiale wołamy View::partial().
 */
final class View
{
    public static function render(string $template, array $data = [], ?string $layout = 'layouts/base'): string
    {
        $vars = $data;
        $vars['layout'] = $layout;
        [$content, $vars] = self::capture($template, $vars);

        $layoutName = $vars['layout'] ?? null;
        if (!$layoutName) {
            return $content;
        }
        $vars['content'] = $content;
        [$html] = self::capture((string) $layoutName, $vars);
        return $html;
    }

    public static function partial(string $template, array $data = []): string
    {
        [$html] = self::capture($template, $data);
        return $html;
    }

    /** @return array{0:string,1:array} wyrenderowany HTML i zmienne po wykonaniu szablonu */
    private static function capture(string $template, array $vars): array
    {
        if (!preg_match('#^[a-z0-9_\-/]+$#', $template)) {
            throw new \InvalidArgumentException("Niedozwolona nazwa szablonu: {$template}");
        }
        $file = APP_ROOT . '/views/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("Brak szablonu: {$template}");
        }

        $level = ob_get_level();
        ob_start();
        try {
            $vars = (static function (string $__file, array $__vars): array {
                extract($__vars, EXTR_SKIP);
                require $__file;
                return get_defined_vars();
            })($file, $vars);
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }
        unset($vars['__file'], $vars['__vars']);
        return [(string) ob_get_clean(), $vars];
    }
}
