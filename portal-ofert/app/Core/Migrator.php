<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Migracje: pliki migrations/NNN_nazwa.sql wykonywane rosnąco, każdy raz.
 * MySQL wykonuje DDL z niejawnym commitem, więc migracja przerwana w połowie
 * NIE wycofa się sama — dlatego każda migracja powinna być mała, a przed
 * migracją na produkcji robimy kopię bazy (bin/migrate.php przypomina o tym).
 */
final class Migrator
{
    public function __construct(private string $dir)
    {
    }

    public function ensureTable(): void
    {
        DB::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
               version    VARCHAR(50) PRIMARY KEY,
               applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /** @return string[] wersje (nazwy plików bez .sql) w kolejności */
    public function available(): array
    {
        $files = glob(rtrim($this->dir, '/') . '/*.sql') ?: [];
        $versions = array_map(static fn($f) => basename($f, '.sql'), $files);
        $versions = array_values(array_filter($versions, static fn($v) => preg_match('/^\d{3,}_[a-z0-9_]+$/', $v)));
        sort($versions, SORT_STRING);
        return $versions;
    }

    public function applied(): array
    {
        $this->ensureTable();
        return array_column(DB::fetchAll('SELECT version FROM migrations ORDER BY version'), 'version');
    }

    public function pending(): array
    {
        return array_values(array_diff($this->available(), $this->applied()));
    }

    /**
     * @param callable(string $version, int $statements):void|null $onApplied
     * @return string[] zastosowane wersje
     */
    public function migrate(?callable $onApplied = null): array
    {
        $done = [];
        foreach ($this->pending() as $version) {
            $sql = (string) file_get_contents(rtrim($this->dir, '/') . '/' . $version . '.sql');
            $statements = self::split($sql);
            foreach ($statements as $i => $statement) {
                try {
                    DB::pdo()->exec($statement);
                } catch (\PDOException $e) {
                    throw new \RuntimeException(sprintf(
                        "Migracja %s przerwana na instrukcji %d/%d: %s\n--- instrukcja ---\n%s",
                        $version, $i + 1, count($statements), $e->getMessage(), mb_substr($statement, 0, 500)
                    ), 0, $e);
                }
            }
            DB::query('INSERT INTO migrations (version) VALUES (?)', [$version]);
            $done[] = $version;
            if ($onApplied) {
                $onApplied($version, count($statements));
            }
        }
        return $done;
    }

    /**
     * Dzieli plik SQL na instrukcje po średnikach, pomijając średniki w cudzysłowach,
     * backtickach i komentarzach (-- …, # …, /* … *\/). Komentarze są usuwane.
     *
     * @return string[]
     */
    public static function split(string $sql): array
    {
        $out = [];
        $buf = '';
        $len = strlen($sql);
        $quote = null;

        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($quote !== null) {
                $buf .= $ch;
                if ($ch === '\\' && $quote !== '`') {
                    $buf .= $next;
                    $i++;
                } elseif ($ch === $quote) {
                    if ($next === $quote) { // podwojony cudzysłów = znak literalny
                        $buf .= $next;
                        $i++;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }

            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
                $buf .= $ch;
                continue;
            }
            if (($ch === '-' && $next === '-' && in_array($sql[$i + 2] ?? ' ', [' ', "\t", "\n", "\r"], true)) || $ch === '#') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $len : $end;
                $buf .= "\n";
                continue;
            }
            if ($ch === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $len : $end + 1;
                $buf .= ' ';
                continue;
            }
            if ($ch === ';') {
                if (trim($buf) !== '') {
                    $out[] = trim($buf);
                }
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        if (trim($buf) !== '') {
            $out[] = trim($buf);
        }
        return $out;
    }
}
