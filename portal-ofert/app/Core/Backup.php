<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Kopie zapasowe bez zewnętrznych narzędzi (działa także, gdy hosting blokuje exec/mysqldump):
 *  - baza: zrzut SQL w PHP (gzip), jedna instrukcja na linię, binaria jako 0x…,
 *  - pliki: storage/uploads jako .tar.gz,
 *  - rotacja: usuwa kopie starsze niż N dni,
 *  - odtworzenie: restore() wczytuje zrzut z powrotem (np. na stagingu).
 * config.php NIE trafia do kopii (sekrety) — trzymaj go w menedżerze haseł.
 */
final class Backup
{
    private const ROWS_PER_INSERT = 200;
    private const MAX_INSERT_BYTES = 1_000_000;
    private const END_MARK = '-- koniec';
    private const PREFIX_DB = 'baza-';
    private const PREFIX_FILES = 'pliki-';

    public static function dir(): string
    {
        $dir = APP_ROOT . '/storage/backups';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        return $dir;
    }

    /**
     * Zrzut całej bazy do pliku .sql.gz. Zwraca ścieżkę.
     * Spójny stan (migawka transakcji InnoDB) — wiersze usuwane w trakcie przez worker nie „przesuwają” stron.
     * Po zapisie plik jest sprawdzany (znacznik końca), zanim zastąpi cokolwiek w katalogu kopii.
     */
    public static function dumpDatabase(?string $file = null): string
    {
        $file ??= self::dir() . '/' . self::PREFIX_DB . gmdate('Ymd-His') . '.sql.gz';
        $part = $file . '.part';
        $pdo = DB::pdo();
        $gz = gzopen($part, 'wb6');
        if ($gz === false) {
            throw new \RuntimeException("Nie można zapisać {$file}");
        }
        $w = static function (string $line) use ($gz, $part): void {
            $line .= "\n";
            if (gzwrite($gz, $line) !== strlen($line)) {
                throw new \RuntimeException("Zapis kopii nieudany ({$part}) — brak miejsca na dysku?");
            }
        };
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        try {
            $w('-- Portal Ofert: kopia bazy ' . gmdate('Y-m-d H:i:s') . ' UTC');
            $w('SET NAMES utf8mb4;');
            $w("SET time_zone = '+00:00';");
            $w('SET FOREIGN_KEY_CHECKS = 0;');

            foreach (DB::fetchAll("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'") as $row) {
                $table = (string) array_values($row)[0];
                $create = (string) array_values(DB::fetch('SHOW CREATE TABLE ' . DB::ident($table)))[1];
                $w('DROP TABLE IF EXISTS ' . DB::ident($table) . ';');
                // CREATE w jednej linii — odtwarzanie czyta instrukcję po instrukcji.
                $w((string) preg_replace('/\s*\R\s*/', ' ', $create) . ';');

                $columns = DB::fetchAll('SHOW COLUMNS FROM ' . DB::ident($table));
                $binary = [];
                $names = [];
                foreach ($columns as $c) {
                    $names[] = DB::ident($c['Field']);
                    $binary[] = (bool) preg_match('/binary|blob/i', (string) $c['Type']);
                }
                $prefix = 'INSERT INTO ' . DB::ident($table) . ' (' . implode(',', $names) . ') VALUES ';
                $order = self::orderBy($table, $columns);
                $offset = 0;
                do {
                    $rows = $pdo->query('SELECT * FROM ' . DB::ident($table) . " ORDER BY {$order} LIMIT " . self::ROWS_PER_INSERT . " OFFSET {$offset}")->fetchAll(PDO::FETCH_NUM);
                    $values = [];
                    $bytes = 0;
                    foreach ($rows as $r) {
                        $vals = [];
                        foreach ($r as $i => $v) {
                            $vals[] = match (true) {
                                $v === null => 'NULL',
                                $binary[$i] => $v === '' ? "''" : '0x' . bin2hex((string) $v),
                                is_int($v), is_float($v) => (string) $v,
                                default => $pdo->quote((string) $v),
                            };
                        }
                        $tuple = '(' . implode(',', $vals) . ')';
                        // Jedna instrukcja najwyżej ~1 MB — mieści się w max_allowed_packet typowego hostingu.
                        if ($values !== [] && $bytes + strlen($tuple) > self::MAX_INSERT_BYTES) {
                            $w($prefix . implode(',', $values) . ';');
                            $values = [];
                            $bytes = 0;
                        }
                        $values[] = $tuple;
                        $bytes += strlen($tuple) + 1;
                    }
                    $values !== [] && $w($prefix . implode(',', $values) . ';');
                    $offset += self::ROWS_PER_INSERT;
                } while (count($rows) === self::ROWS_PER_INSERT);
            }
            $w('SET FOREIGN_KEY_CHECKS = 1;');
            $w(self::END_MARK);
        } catch (\Throwable $e) {
            gzclose($gz);
            @unlink($part);
            throw $e;
        } finally {
            $pdo->inTransaction() && $pdo->commit();
        }
        if (!gzclose($gz) || !self::isComplete($part)) {
            @unlink($part);
            throw new \RuntimeException('Kopia bazy jest niekompletna (zapis przerwany) — poprzednie kopie zostają nietknięte.');
        }
        rename($part, $file);
        @chmod($file, 0640);
        return $file;
    }

    /** Czy zrzut kończy się znacznikiem końca (cały plik da się rozpakować i nie jest ucięty). */
    public static function isComplete(string $file): bool
    {
        $gz = @gzopen($file, 'rb');
        if ($gz === false) {
            return false;
        }
        $last = '';
        while (($line = gzgets($gz)) !== false) {
            if (trim($line) !== '') {
                $last = rtrim($line, "\r\n");
            }
        }
        $ok = gzeof($gz);
        gzclose($gz);
        return $ok && $last === self::END_MARK;
    }

    /**
     * Odtworzenie bazy ze zrzutu (.sql.gz). UWAGA: nadpisuje bazę. Zwraca liczbę instrukcji.
     * Najpierw sprawdza cały plik (nic nie jest zmieniane, jeśli zrzut jest ucięty), potem usuwa wszystkie
     * tabele — także nowsze niż kopia — żeby migracje po odtworzeniu zaczynały od stanu z kopii.
     */
    public static function restoreDatabase(string $file): int
    {
        if (!self::isComplete($file)) {
            throw new \RuntimeException('Zrzut jest niekompletny (brak znacznika końca) — baza nie została zmieniona.');
        }
        $gz = gzopen($file, 'rb');
        if ($gz === false) {
            throw new \RuntimeException("Nie można otworzyć {$file}");
        }
        $pdo = DB::pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (DB::fetchAll("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'") as $row) {
            $pdo->exec('DROP TABLE ' . DB::ident((string) array_values($row)[0]));
        }
        $buffer = '';
        $count = 0;
        try {
            while (($line = gzgets($gz)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($buffer === '' && (str_starts_with($line, '-- ') || $line === '')) {
                    continue;
                }
                $buffer .= ($buffer === '' ? '' : "\n") . $line;
                if (str_ends_with($line, ';')) {
                    $pdo->exec($buffer);
                    $buffer = '';
                    $count++;
                }
            }
        } finally {
            gzclose($gz);
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
        return $count;
    }

    /** Archiwum storage/uploads (.tar.gz, format ustar, zapis strumieniowy). Zwraca ścieżkę albo null, gdy nie ma plików. */
    public static function archiveFiles(?string $file = null): ?string
    {
        $src = APP_ROOT . '/storage/uploads';
        if (!is_dir($src)) {
            return null;
        }
        $file ??= self::dir() . '/' . self::PREFIX_FILES . gmdate('Ymd-His') . '.tar.gz';
        $gz = gzopen($file . '.part', 'wb6');
        if ($gz === false) {
            throw new \RuntimeException("Nie można zapisać {$file}");
        }
        $count = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            if (!$f->isFile() || $f->getFilename() === '.gitkeep') {
                continue;
            }
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($src) + 1));
            gzwrite($gz, self::tarHeader($rel, (int) $f->getSize(), (int) $f->getMTime()));
            // Dokładnie tyle bajtów, ile podano w nagłówku — plik zmieniony w trakcie nie rozjedzie archiwum.
            $size = (int) $f->getSize();
            $in = fopen($f->getPathname(), 'rb');
            $left = $size;
            while ($in && $left > 0 && !feof($in)) {
                $chunk = (string) fread($in, min(1048576, $left));
                gzwrite($gz, $chunk);
                $left -= strlen($chunk);
            }
            $in && fclose($in);
            $left > 0 && gzwrite($gz, str_repeat("\0", $left)); // plik skrócił się w trakcie
            $pad = (512 - $size % 512) % 512;
            $pad > 0 && gzwrite($gz, str_repeat("\0", $pad));
            $count++;
        }
        gzwrite($gz, str_repeat("\0", 1024));
        gzclose($gz);
        if ($count === 0) {
            @unlink($file . '.part');
            return null;
        }
        rename($file . '.part', $file);
        @chmod($file, 0640);
        return $file;
    }

    /** Rozpakowanie archiwum plików do storage/uploads (istniejące pliki są nadpisywane). Zwraca liczbę plików. */
    public static function restoreFiles(string $file): int
    {
        $dest = APP_ROOT . '/storage/uploads';
        $gz = gzopen($file, 'rb');
        if ($gz === false) {
            throw new \RuntimeException("Nie można otworzyć {$file}");
        }
        $count = 0;
        try {
            while (strlen($h = (string) gzread($gz, 512)) === 512 && trim($h, "\0") !== '') {
                $name = rtrim(substr($h, 0, 100), "\0");
                $prefix = rtrim(substr($h, 345, 155), "\0");
                $rel = $prefix !== '' ? $prefix . '/' . $name : $name;
                $size = (int) octdec(trim(substr($h, 124, 12), "\0 "));
                $type = $h[156];
                if ($type !== '0' && $type !== "\0") {
                    // Katalogi, dowiązania itp. pomijamy (razem z ewentualną treścią).
                    $skip = $size + (512 - $size % 512) % 512;
                    $skip > 0 && gzread($gz, $skip);
                    continue;
                }
                // Ochrona przed „../” i ścieżkami bezwzględnymi w archiwum.
                if ($rel === '' || str_starts_with($rel, '/') || preg_match('#(^|/)\.\.(/|$)#', $rel)) {
                    throw new \RuntimeException("Niedozwolona ścieżka w archiwum: {$rel}");
                }
                $target = $dest . '/' . $rel;
                if (!is_dir(dirname($target))) {
                    mkdir(dirname($target), 0750, true);
                }
                $out = fopen($target, 'wb');
                $left = $size;
                while ($left > 0) {
                    $chunk = (string) gzread($gz, min(1048576, $left));
                    if ($chunk === '') {
                        throw new \RuntimeException('Archiwum jest uszkodzone (urwany plik).');
                    }
                    fwrite($out, $chunk);
                    $left -= strlen($chunk);
                }
                fclose($out);
                @chmod($target, 0640);
                $pad = (512 - $size % 512) % 512;
                $pad > 0 && gzread($gz, $pad);
                $count++;
            }
        } finally {
            gzclose($gz);
        }
        return $count;
    }

    private static function tarHeader(string $path, int $size, int $mtime): string
    {
        $prefix = '';
        $name = $path;
        if (strlen($name) > 100) {
            $cut = strrpos(substr($path, 0, 155), '/');
            if ($cut === false || strlen(substr($path, $cut + 1)) > 100) {
                throw new \RuntimeException("Za długa ścieżka do archiwum: {$path}");
            }
            $prefix = substr($path, 0, $cut);
            $name = substr($path, $cut + 1);
        }
        $h = str_pad($name, 100, "\0")
            . str_pad('0000640', 8, "\0") . str_pad('0000000', 8, "\0") . str_pad('0000000', 8, "\0")
            . str_pad(sprintf('%011o', $size), 12, "\0") . str_pad(sprintf('%011o', $mtime), 12, "\0")
            . '        ' . '0' . str_repeat("\0", 100)
            . "ustar\0" . '00' . str_repeat("\0", 32) . str_repeat("\0", 32)
            . str_repeat("\0", 8) . str_repeat("\0", 8) . str_pad($prefix, 155, "\0") . str_repeat("\0", 12);
        $sum = array_sum(array_map('ord', str_split($h)));
        return substr_replace($h, sprintf('%06o', $sum) . "\0 ", 148, 8);
    }

    /** Usuwa lokalne kopie starsze niż $days dni. Zwraca listę usuniętych plików. */
    public static function rotate(int $days): array
    {
        $removed = [];
        $limit = time() - $days * 86400;
        foreach (glob(self::dir() . '/{' . self::PREFIX_DB . ',' . self::PREFIX_FILES . '}*', GLOB_BRACE) ?: [] as $f) {
            if (filemtime($f) < $limit && @unlink($f)) {
                $removed[] = basename($f);
            }
        }
        return $removed;
    }

    /** Czas (unix) najnowszej kopii bazy albo null. */
    public static function latest(): ?int
    {
        $times = array_map('filemtime', glob(self::dir() . '/' . self::PREFIX_DB . '*.sql.gz') ?: []);
        return $times === [] ? null : max($times);
    }

    private static function orderBy(string $table, array $columns): string
    {
        $pk = array_values(array_filter($columns, static fn($c) => $c['Key'] === 'PRI'));
        $cols = $pk !== [] ? $pk : [$columns[0]];
        return implode(',', array_map(static fn($c) => DB::ident($c['Field']), $cols));
    }
}
