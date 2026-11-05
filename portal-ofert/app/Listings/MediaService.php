<?php

declare(strict_types=1);

namespace App\Listings;

use App\Core\DB;
use App\Core\Logger;
use App\Core\Settings;
use App\Core\UploadedFile;

/**
 * Zdjęcia (→ WebP w 3 rozmiarach, bez EXIF) i załączniki (PDF/DOCX/XLSX) ofert.
 * Wszystko w storage/uploads — POZA public_html; wydawane przez PHP po sprawdzeniu Gate.
 * W opublikowanej ofercie dodanie/usunięcie czeka na moderację (state pending_add / pending_remove).
 */
final class MediaService
{
    public const SIZES = [400, 960, 1600];
    public const IMAGE_MIME = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    // Ochrona pamięci: 24 Mpx × 4 B ≈ 96 MB na obraz (+ kopia przy obrocie EXIF) — mieści się w memory_limit 256M.
    private const MAX_PIXELS = 24_000_000;

    // ------------------------------------------------------------------ zdjęcia

    /**
     * @return array{ok:bool,id?:int,error?:string}
     */
    public static function addImage(array $listing, UploadedFile $file): array
    {
        if (!$file->ok()) {
            return ['ok' => false, 'error' => __($file->errorKey())];
        }
        $maxMb = (int) Settings::get('limits.image_max_mb', 10);
        if ($file->size > $maxMb * 1048576) {
            return ['ok' => false, 'error' => __('upload.too_large', ['name' => $file->safeName(), 'max' => $maxMb])];
        }
        $mime = $file->mime();
        if (!isset(self::IMAGE_MIME[$mime])) {
            return ['ok' => false, 'error' => __('upload.image_type', ['name' => $file->safeName()])];
        }
        $max = (int) Settings::get('limits.images_max', 20);
        if (self::count('media', (int) $listing['id']) >= $max) {
            return ['ok' => false, 'error' => __('upload.images_max', ['max' => $max])];
        }

        $info = @getimagesize($file->tmpPath);
        if ($info === false || $info[0] * $info[1] > self::MAX_PIXELS) {
            return ['ok' => false, 'error' => __('upload.image_invalid', ['name' => $file->safeName()])];
        }
        $src = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($file->tmpPath),
            'image/png' => @imagecreatefrompng($file->tmpPath),
            'image/webp' => @imagecreatefromwebp($file->tmpPath),
        };
        if (!$src) {
            return ['ok' => false, 'error' => __('upload.image_invalid', ['name' => $file->safeName()])];
        }
        $src = self::autoOrient($src, $file->tmpPath, $mime);

        $base = self::newPath('img');
        $w = imagesx($src);
        $h = imagesy($src);
        $total = 0;
        foreach (self::SIZES as $size) {
            $tw = min($size, $w);
            $th = (int) round($h * $tw / $w);
            $dst = imagecreatetruecolor($tw, $th);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
            $path = APP_ROOT . '/storage/uploads/' . $base . '_' . $size . '.webp';
            // Ponowne zakodowanie usuwa EXIF (GPS, model aparatu) i ewentualny ukryty kod.
            if (!imagewebp($dst, $path, 80)) {
                throw new \RuntimeException('Nie udało się zapisać WebP.');
            }
            imagedestroy($dst);
            $total += (int) filesize($path);
        }
        imagedestroy($src);

        $live = !ListingService::isLive($listing);
        $isFirst = self::count('media', (int) $listing['id']) === 0;
        $id = DB::insert('media', [
            'listing_id' => $listing['id'],
            'path_base' => $base,
            'alt' => mb_substr(pathinfo($file->safeName(), PATHINFO_FILENAME), 0, 200),
            'access_level' => 1,
            'state' => $live ? 'live' : 'pending_add',
            'is_cover' => $isFirst ? 1 : 0,
            'sort_order' => (int) DB::value('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM media WHERE listing_id = ?', [$listing['id']]),
            'width' => min($w, max(self::SIZES)),
            'height' => (int) round($h * min($w, max(self::SIZES)) / $w),
            'size_bytes' => $total,
        ]);
        return ['ok' => true, 'id' => $id];
    }

    /** Ścieżka do pliku w rozmiarze ≥ żądanego (albo największym). */
    public static function imagePath(array $media, int $size): ?string
    {
        foreach (self::SIZES as $s) {
            if ($s >= $size) {
                $p = APP_ROOT . '/storage/uploads/' . $media['path_base'] . '_' . $s . '.webp';
                return is_file($p) ? $p : null;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------ załączniki

    /** @return array{ok:bool,id?:int,error?:string} */
    public static function addAttachment(array $listing, UploadedFile $file, string $label, ?int $level): array
    {
        if (!$file->ok()) {
            return ['ok' => false, 'error' => __($file->errorKey())];
        }
        $maxMb = (int) Settings::get('limits.attachment_max_mb', 25);
        if ($file->size > $maxMb * 1048576) {
            return ['ok' => false, 'error' => __('upload.too_large', ['name' => $file->safeName(), 'max' => $maxMb])];
        }
        $allowed = self::attachmentTypes();
        $mime = $file->mime();
        if (!isset($allowed[$mime])) {
            return ['ok' => false, 'error' => __('upload.file_type', ['name' => $file->safeName(), 'types' => strtoupper(implode(', ', array_unique($allowed)))])];
        }
        $max = (int) Settings::get('limits.attachments_max', 25);
        if (self::count('attachments', (int) $listing['id']) >= $max) {
            return ['ok' => false, 'error' => __('upload.attachments_max', ['max' => $max])];
        }

        $rel = self::newPath('files') . '.' . $allowed[$mime];
        $file->moveTo(APP_ROOT . '/storage/uploads/' . $rel);
        $level = in_array($level, [1, 2, 3], true) ? $level : (int) Settings::get('limits.attachment_default_level', 3);
        $name = $file->safeName();

        $id = DB::insert('attachments', [
            'listing_id' => $listing['id'],
            'original_name' => $name,
            'stored_path' => $rel,
            'mime' => $mime,
            'size_bytes' => $file->size,
            'sha256' => hash_file('sha256', APP_ROOT . '/storage/uploads/' . $rel),
            'label' => mb_substr(trim($label) !== '' ? trim($label) : pathinfo($name, PATHINFO_FILENAME), 0, 200),
            'access_level' => $level,
            'state' => ListingService::isLive($listing) ? 'pending_add' : 'live',
            'sort_order' => (int) DB::value('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM attachments WHERE listing_id = ?', [$listing['id']]),
        ]);
        return ['ok' => true, 'id' => $id];
    }

    /** @return array<string,string> MIME => rozszerzenie (z presetu limits.attachment_mime) */
    public static function attachmentTypes(): array
    {
        $map = [
            'application/pdf' => 'pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
        ];
        $wanted = (array) Settings::get('limits.attachment_mime', ['application/pdf']);
        return array_intersect_key($map, array_flip($wanted));
    }

    // ------------------------------------------------------------------ wspólne

    /** Usunięcie: przed publikacją od razu; w opublikowanej ofercie — po akceptacji moderatora. */
    public static function remove(string $table, array $listing, int $id): bool
    {
        self::assertTable($table);
        $row = DB::fetch("SELECT * FROM {$table} WHERE id = ? AND listing_id = ?", [$id, $listing['id']]);
        if ($row === null) {
            return false;
        }
        if (!ListingService::isLive($listing) || $row['state'] === 'pending_add') {
            self::deleteRow($table, $row);
            if ($table === 'media' && (int) $row['is_cover'] === 1) {
                self::ensureCover((int) $listing['id']);
            }
            return true;
        }
        DB::query("UPDATE {$table} SET state = IF(state = 'pending_remove', 'live', 'pending_remove') WHERE id = ?", [$id]);
        return true;
    }

    public static function setCover(int $listingId, int $mediaId): void
    {
        if (DB::value('SELECT 1 FROM media WHERE id = ? AND listing_id = ?', [$mediaId, $listingId]) === null) {
            return;
        }
        DB::query('UPDATE media SET is_cover = (id = ?) WHERE listing_id = ?', [$mediaId, $listingId]);
    }

    /** Przesunięcie o jedną pozycję (przyciski ← → — dostępne z klawiatury, bez przeciągania). */
    public static function move(string $table, int $listingId, int $id, int $direction): void
    {
        self::assertTable($table);
        $rows = DB::fetchAll("SELECT id FROM {$table} WHERE listing_id = ? ORDER BY sort_order, id", [$listingId]);
        $ids = array_map('intval', array_column($rows, 'id'));
        $pos = array_search($id, $ids, true);
        $swap = $pos === false ? null : $pos + ($direction < 0 ? -1 : 1);
        if ($swap === null || !isset($ids[$swap])) {
            return;
        }
        [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
        foreach ($ids as $i => $rid) {
            DB::query("UPDATE {$table} SET sort_order = ? WHERE id = ?", [($i + 1) * 10, $rid]);
        }
    }

    /** @return array[] zdjęcia oferty (do edycji — także oczekujące) */
    public static function images(int $listingId, bool $liveOnly = false): array
    {
        $where = $liveOnly ? "AND state IN ('live', 'pending_remove')" : '';
        return DB::fetchAll("SELECT * FROM media WHERE listing_id = ? {$where} ORDER BY sort_order, id", [$listingId]);
    }

    public static function attachments(int $listingId, bool $liveOnly = false): array
    {
        $where = $liveOnly ? "AND state IN ('live', 'pending_remove')" : '';
        return DB::fetchAll("SELECT * FROM attachments WHERE listing_id = ? {$where} ORDER BY sort_order, id", [$listingId]);
    }

    /** Akceptacja moderatora: dodane → widoczne, oznaczone do usunięcia → usunięte. */
    public static function applyPending(int $listingId): void
    {
        foreach (['media', 'attachments'] as $table) {
            DB::query("UPDATE {$table} SET state = 'live' WHERE listing_id = ? AND state = 'pending_add'", [$listingId]);
            foreach (DB::fetchAll("SELECT * FROM {$table} WHERE listing_id = ? AND state = 'pending_remove'", [$listingId]) as $row) {
                self::deleteRow($table, $row);
            }
        }
        self::ensureCover($listingId);
    }

    /** Odrzucenie rewizji: nowe pliki znikają, oznaczone do usunięcia wracają. */
    public static function discardPending(int $listingId): void
    {
        foreach (['media', 'attachments'] as $table) {
            foreach (DB::fetchAll("SELECT * FROM {$table} WHERE listing_id = ? AND state = 'pending_add'", [$listingId]) as $row) {
                self::deleteRow($table, $row);
            }
            DB::query("UPDATE {$table} SET state = 'live' WHERE listing_id = ? AND state = 'pending_remove'", [$listingId]);
        }
        self::ensureCover($listingId);
    }

    public static function deleteAllFiles(int $listingId): void
    {
        foreach (['media', 'attachments'] as $table) {
            foreach (DB::fetchAll("SELECT * FROM {$table} WHERE listing_id = ?", [$listingId]) as $row) {
                self::deleteRow($table, $row);
            }
        }
    }

    private static function assertTable(string $table): void
    {
        if (!in_array($table, ['media', 'attachments'], true)) {
            throw new \InvalidArgumentException("Niedozwolona tabela: {$table}");
        }
    }

    private static function ensureCover(int $listingId): void
    {
        if (DB::value("SELECT 1 FROM media WHERE listing_id = ? AND is_cover = 1", [$listingId]) === null) {
            $first = DB::value('SELECT id FROM media WHERE listing_id = ? ORDER BY sort_order, id LIMIT 1', [$listingId]);
            if ($first !== null) {
                DB::query('UPDATE media SET is_cover = 1 WHERE id = ?', [$first]);
            }
        }
    }

    private static function deleteRow(string $table, array $row): void
    {
        if ($table === 'media') {
            foreach (self::SIZES as $s) {
                @unlink(APP_ROOT . '/storage/uploads/' . $row['path_base'] . '_' . $s . '.webp');
            }
        } else {
            @unlink(APP_ROOT . '/storage/uploads/' . $row['stored_path']);
        }
        DB::query("DELETE FROM {$table} WHERE id = ?", [$row['id']]);
    }

    private static function count(string $table, int $listingId): int
    {
        return (int) DB::value("SELECT COUNT(*) FROM {$table} WHERE listing_id = ? AND state <> 'pending_remove'", [$listingId]);
    }

    /** img/ab/cd/<losowe> — dwa poziomy katalogów, żeby nie mieć tysięcy plików w jednym. */
    private static function newPath(string $kind): string
    {
        $rand = bin2hex(random_bytes(16));
        $dir = $kind . '/' . substr($rand, 0, 2) . '/' . substr($rand, 2, 2);
        $abs = APP_ROOT . '/storage/uploads/' . $dir;
        if (!is_dir($abs) && !mkdir($abs, 0750, true) && !is_dir($abs)) {
            throw new \RuntimeException("Nie można utworzyć katalogu {$abs}");
        }
        return $dir . '/' . $rand;
    }

    /** Zdjęcia z telefonu bywają zapisane „bokiem” — obracamy wg EXIF Orientation. */
    private static function autoOrient(\GdImage $img, string $path, string $mime): \GdImage
    {
        if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data($path);
        $rotated = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => null,
        };
        if ($rotated instanceof \GdImage) {
            imagedestroy($img);
            return $rotated;
        }
        if ($rotated === false) {
            Logger::warning('Nie udało się obrócić zdjęcia wg EXIF');
        }
        return $img;
    }
}
