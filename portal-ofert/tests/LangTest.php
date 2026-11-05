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
        $dynamic = array_merge(
            array_map(fn($r) => 'validation.' . $r, ['required', 'accepted', 'email', 'maxlength', 'minlength', 'int', 'decimal', 'min', 'max', 'date', 'in', 'url', 'phone_pl', 'nip']),
            ['otp.invalid', 'otp.expired', 'otp.too_many', 'otp.sent_whatsapp', 'otp.sent_sms', 'otp.sent_sms_fallback',
             'register.phone_hint_whatsapp', 'register.phone_hint_sms', 'register.phone_hint_none',
             'access.level_1', 'access.level_2', 'access.level_3', 'media.state_pending_add', 'media.state_pending_remove',
             'email.moderation.subject_new', 'email.moderation.subject_revision', 'email.moderation.text_new', 'email.moderation.text_revision',
             'email.approved.subject_new', 'email.approved.subject_revision', 'email.approved.text_new', 'email.approved.text_revision',
             'email.rejected.text_new', 'email.rejected.text_revision', 'upload.too_large_server', 'upload.partial', 'upload.failed'],
            array_map(fn($s) => 'status.' . $s, ['draft', 'pending', 'published', 'paused', 'sold', 'archived', 'rejected']),
            ['listings.sort_najnowsze', 'listings.sort_najstarsze', 'listings.sort_asc', 'listings.sort_desc', 'access.request_requested', 'access.request_already', 'access.request_granted',
             'access.docs_locked_guest', 'access.docs_locked_member'],
            // Etap 5: klucze składane w kodzie
            array_map(fn($s) => 'inquiry_status.' . $s, \App\Http\Controllers\Admin\InquiriesController::STATUSES),
            array_map(fn($s) => 'grant_status.' . $s, ['requested', 'granted', 'denied', 'revoked']),
            array_map(fn($s) => 'role.' . $s, ['member', 'moderator', 'admin']),
            array_map(fn($s) => 'user_status.' . $s, ['active', 'blocked', 'deleted']),
            array_map(fn($s) => 'field_type.' . $s, \App\Presets\Importer::FIELD_TYPES),
            array_map(fn($s) => 'filter_ui.' . $s, \App\Presets\Importer::FILTER_UI),
            array_map(fn($s) => 'saved.freq_' . $s, ['daily', 'weekly', 'off']),
            array_map(fn($s) => 'mine.action_' . $s, ['pause', 'resume', 'sold', 'archive']),
            array_map(fn($s) => 'mine.status_' . $s, ['pause', 'resume', 'sold', 'archive']),
            array_map(fn($s) => 'admin.action_' . $s, ['pause', 'resume', 'sold', 'archive', 'restore']),
            ['mine.confirm_sold', 'mine.confirm_archive', 'admin.confirm_sold', 'admin.confirm_archive', 'admin.kind_new', 'admin.kind_revision',
             'admin.approved_new', 'admin.approved_revision', 'admin.rejected_new', 'admin.rejected_revision',
             'admin.company_source_manual', 'admin.company_source_registry', 'fields.level_1_hint', 'fields.level_2_hint', 'fields.level_3_hint',
             'fields.val_min', 'fields.val_max', 'fields.val_maxlength'],
        );
        foreach ($dynamic as $key) {
            if (!Lang::has($key)) {
                $missing[] = $key;
            }
        }
        T::same([], array_values(array_unique($missing)));
    },

    'Odbiór: zero tekstów po angielsku — widoki bez tekstu poza __(), teksty bez angielskich słów' => function (): void {
        $allowed = ['NIP', 'Website' /* pole-pułapka na boty, ukryte */];
        $hits = [];
        foreach (glob(APP_ROOT . '/views/{,*/,*/*/}*.php', GLOB_BRACE) ?: [] as $file) {
            $s = (string) file_get_contents($file);
            $s = (string) preg_replace(['/<\?(php|=).*?\?>/s', '/<(script|style)\b.*?<\/\1>/s', '/<!--.*?-->/s', '/<[^>]*>/s'], ' ', $s);
            preg_match_all('/\p{L}{3,}/u', html_entity_decode($s), $m);
            foreach (array_diff(array_unique($m[0]), $allowed) as $word) {
                $hits[] = basename(dirname($file)) . '/' . basename($file) . ': ' . $word;
            }
        }
        $english = '/\b(the|your|please|submit|password|error|dashboard|account|settings|click|save|cancel|delete|search|next|previous|loading|required|invalid|welcome|sign in|sign up|log ?out|upload|download)\b/i';
        foreach (require APP_ROOT . '/lang/pl.php' as $key => $text) {
            $plain = (string) preg_replace(['/\{[a-z_]+\}/', '/bin\/[a-z\-]+\.php/'], '', (string) $text);
            if (preg_match($english, $plain, $m)) {
                $hits[] = "{$key}: {$m[0]}";
            }
        }
        T::same([], $hits);
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

    'Słownictwo: preset nadpisuje teksty, tylko istniejące klucze' => function (): void {
        Lang::setOverrideLoader(fn() => ['term.listing' => 'Inwestycja', 'nie.istnieje' => 'X', 'term.listings' => ['zly typ']]);
        T::same('Inwestycja', __('term.listing'));
        T::same('Oferty', __('term.listings'), 'nie-tekst ignorowany');
        T::assert(!array_key_exists('nie.istnieje', Lang::all()), 'nowe klucze nie powstają z presetu');
        Lang::setOverrideLoader(fn() => []);
        T::same('Oferta', __('term.listing'), 'domyślnie neutralnie');
        \App\Core\Settings::flush();

        $errors = \App\Presets\Importer::validate([
            'preset' => 'x', 'fields' => [['key' => 'a', 'label' => 'A', 'type' => 'text']],
            'terms' => ['term.listing' => 'Ogłoszenie', 'term.lisitng' => 'literówka', 'term.listings' => ''],
            'account_types' => [['key' => 'Zly Klucz', 'label' => 'X'], ['key' => 'a', 'label' => 'A'], ['key' => 'a', 'label' => 'B']],
        ]);
        T::same(4, count($errors), implode(' | ', $errors));
    },

    'Rdzeń nie zna branży: brak słownictwa inwestycyjnego w kodzie i tekstach domyślnych' => function (): void {
        $files = array_merge(
            glob(APP_ROOT . '/app/{,*/,*/*/}*.php', GLOB_BRACE) ?: [],
            glob(APP_ROOT . '/views/{,*/,*/*/}*.php', GLOB_BRACE) ?: [],
            [APP_ROOT . '/lang/pl.php', APP_ROOT . '/migrations/001_init.sql', APP_ROOT . '/public/assets/css/app.css'],
        );
        $hits = [];
        foreach ($files as $file) {
            $content = (string) file_get_contents($file);
            // komentarze z przykładami presetów są dozwolone — sprawdzamy kod i teksty, nie dokumentację w komentarzach
            $content = (string) preg_replace('#(//|--|\*|\#).*$#m', '', $content);
            if (preg_match('/inwest|dewelop|investor|developer|kapita/iu', $content, $m)) {
                $hits[] = basename($file) . ': ' . $m[0];
            }
        }
        T::same([], $hits);
    },
];
