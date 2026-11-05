<?php

declare(strict_types=1);

namespace App\Core;

/**
 * „Tryb kampanii” (KONCEPCJA §7.5 — lekcja z 10.03.2025): na czas wysyłki SMS/mailingu
 * blokuje wdrożenia (.cpanel.yml sprawdza plik storage/campaign.lock) i pokazuje baner w panelu.
 * Wygasa sam po ustawionym czasie, żeby nikt nie zapomniał go wyłączyć.
 */
final class CampaignMode
{
    public static function file(): string
    {
        return APP_ROOT . '/storage/campaign.lock';
    }

    public static function until(): ?int
    {
        if (!is_file(self::file())) {
            return null;
        }
        $until = (int) trim((string) file_get_contents(self::file()));
        if ($until <= time()) {
            @unlink(self::file());
            return null;
        }
        return $until;
    }

    public static function enable(int $hours): void
    {
        file_put_contents(self::file(), (string) (time() + max(1, min(72, $hours)) * 3600), LOCK_EX);
    }

    public static function disable(): void
    {
        @unlink(self::file());
    }
}
