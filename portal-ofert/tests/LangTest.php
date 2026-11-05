<?php

declare(strict_types=1);

use App\Core\Lang;

return [
    'Teksty: każdy klucz __() z kodu istnieje w lang/pl.php' => function (): void {
        $files = array_merge(
            glob(APP_ROOT . '/app/{,*/,*/*/}*.php', GLOB_BRACE) ?: [],
            glob(APP_ROOT . '/views/{,*/,*/*/}*.php', GLOB_BRACE) ?: [],
        );
        $missing = [];
        foreach ($files as $file) {
            preg_match_all("/__\\('([a-z0-9_.]+)'\\s*[,)]/", (string) file_get_contents($file), $m);
            foreach ($m[1] as $key) {
                if (!Lang::has($key)) {
                    $missing[] = $key . ' (' . basename($file) . ')';
                }
            }
        }
        // Klucze dynamiczne: validation.{reguła}
        foreach (['required', 'accepted', 'email', 'maxlength', 'minlength', 'int', 'decimal', 'min', 'max', 'date', 'in', 'url', 'phone_pl', 'nip'] as $rule) {
            if (!Lang::has('validation.' . $rule)) {
                $missing[] = 'validation.' . $rule;
            }
        }
        T::same([], array_values(array_unique($missing)));
    },

    'Presety: oba pliki przechodzą walidację' => function (): void {
        foreach (glob(APP_ROOT . '/presets/*.json') ?: [] as $file) {
            $errors = \App\Presets\Importer::validate(\App\Presets\Importer::readFile($file));
            T::same([], $errors, basename($file));
        }
        $bad = \App\Presets\Importer::validate([
            'preset' => 'X Y', 'fields' => [
                ['key' => 'a', 'label' => 'A', 'type' => 'select'],
                ['key' => 'a', 'label' => '', 'type' => 'kolor', 'access_level' => 5, 'categories' => ['brak']],
            ],
        ]);
        T::assert(count($bad) >= 6, 'wykryte błędy: ' . implode(' | ', $bad));
    },
];
