<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Plik z formularza ($_FILES). Typ sprawdzamy po ZAWARTOŚCI (finfo), nie po rozszerzeniu
 * ani nagłówku przeglądarki. Zapis przez move_uploaded_file — w testach (app.env = testing)
 * dozwolone kopiowanie zwykłego pliku.
 */
final class UploadedFile
{
    private function __construct(
        public readonly string $tmpPath,
        public readonly string $clientName,
        public readonly int $size,
        public readonly int $error,
        private readonly bool $local = false,
    ) {
    }

    /** Plik z dysku serwera (import ze starej strony, CLI) — przechodzi te same kontrole typu i rozmiaru. */
    public static function local(string $path, ?string $name = null): self
    {
        $ok = is_file($path);
        return new self($path, $name ?? basename($path), $ok ? (int) filesize($path) : 0, $ok ? UPLOAD_ERR_OK : UPLOAD_ERR_NO_FILE, true);
    }

    /**
     * Normalizuje pole $_FILES (pojedyncze lub name[]) do listy plików.
     * @return self[]
     */
    public static function fromField(array $files, string $field): array
    {
        $f = $files[$field] ?? null;
        if (!is_array($f) || !isset($f['tmp_name'])) {
            return [];
        }
        if (!is_array($f['tmp_name'])) {
            return $f['error'] === UPLOAD_ERR_NO_FILE ? [] : [new self((string) $f['tmp_name'], (string) $f['name'], (int) $f['size'], (int) $f['error'])];
        }
        $out = [];
        foreach ($f['tmp_name'] as $i => $tmp) {
            if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $out[] = new self((string) $tmp, (string) $f['name'][$i], (int) $f['size'][$i], (int) $f['error'][$i]);
            }
        }
        return $out;
    }

    public function ok(): bool
    {
        return $this->error === UPLOAD_ERR_OK && is_file($this->tmpPath);
    }

    /** Komunikat dla użytkownika przy błędzie PHP uploadu (np. za duży plik wg limitów serwera). */
    public function errorKey(): string
    {
        return match ($this->error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'upload.too_large_server',
            UPLOAD_ERR_PARTIAL => 'upload.partial',
            default => 'upload.failed',
        };
    }

    public function mime(): string
    {
        return (string) (new \finfo(FILEINFO_MIME_TYPE))->file($this->tmpPath);
    }

    /** Nazwa wyświetlana: bez ścieżki i znaków sterujących, maks. 200 znaków. */
    public function safeName(): string
    {
        $name = basename(str_replace('\\', '/', $this->clientName));
        $name = (string) preg_replace('/[\x00-\x1F\x7F"<>:|?*]+/u', '', $name);
        return mb_substr($name !== '' ? $name : 'plik', 0, 200);
    }

    public function moveTo(string $dest): void
    {
        $dir = dirname($dest);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException("Nie można utworzyć katalogu {$dir}");
        }
        if (is_uploaded_file($this->tmpPath)) {
            $ok = move_uploaded_file($this->tmpPath, $dest);
        } elseif ($this->local || (PHP_SAPI === 'cli' && Config::get('app.env') === 'testing')) {
            $ok = copy($this->tmpPath, $dest);
        } else {
            $ok = false;
        }
        if (!$ok) {
            throw new \RuntimeException('Nie udało się zapisać przesłanego pliku.');
        }
        @chmod($dest, 0640);
    }

    /** Do testów: plik z dysku udający upload. */
    public static function fake(string $path, ?string $name = null): array
    {
        return ['tmp_name' => $path, 'name' => $name ?? basename($path), 'size' => filesize($path), 'error' => UPLOAD_ERR_OK, 'type' => ''];
    }
}
