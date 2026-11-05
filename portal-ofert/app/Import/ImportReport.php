<?php

declare(strict_types=1);

namespace App\Import;

/** Raport importu w Markdown — do przejrzenia z klientem przed startem. */
final class ImportReport
{
    public static function markdown(array $r, bool $dryRun): string
    {
        $l = [];
        $l[] = '# Raport importu z WordPressa — ' . gmdate('Y-m-d H:i') . ' UTC' . ($dryRun ? ' (PRÓBA — nic nie zapisano)' : '');
        $l[] = '';
        $l[] = '| Co | Nowe | Już były | Pominięte |';
        $l[] = '|---|---:|---:|---:|';
        $l[] = "| Konta | {$r['users']['imported']} | {$r['users']['existing']} | " . count($r['users']['skipped']) . ' |';
        $l[] = "| Oferty | {$r['listings']['imported']} | {$r['listings']['existing']} | " . count($r['listings']['skipped']) . ' |';
        $l[] = '';
        $l[] = "Zdjęcia: {$r['media']['images']}, dokumenty: {$r['media']['attachments']}" . ($dryRun ? ' (w próbie pliki nie są kopiowane — sprawdzana jest tylko ich obecność)' : '') . '.';
        $sections = [
            'Pominięte oferty' => $r['listings']['skipped'],
            'Pominięte / zmienione konta' => $r['users']['skipped'],
            'Odrzucone wartości pól (walidacja jak w formularzu)' => $r['values']['rejected'],
            'Brakujące pliki' => $r['media']['missing'],
            'Odrzucone pliki' => $r['media']['rejected'],
        ];
        foreach ($sections as $title => $items) {
            if ($items === []) {
                continue;
            }
            $l[] = '';
            $l[] = "## {$title} (" . count($items) . ')';
            foreach ($items as $i) {
                $l[] = '- ' . $i;
            }
        }
        if ($r['values']['unknown_options'] !== []) {
            $l[] = '';
            $l[] = '## Wartości bez odpowiednika w opcjach pola — dodaj je do „values” w mapowaniu albo jako opcje w panelu';
            foreach ($r['values']['unknown_options'] as $k => $n) {
                $l[] = "- `{$k}` ({$n}×)";
            }
        }
        if ($r['unmapped_terms'] !== []) {
            $l[] = '';
            $l[] = '## Kategorie WordPressa bez odpowiednika („category.map”)';
            foreach ($r['unmapped_terms'] as $k => $n) {
                $l[] = "- `{$k}` ({$n}×)";
            }
        }
        if ($r['unmapped_meta'] !== []) {
            $l[] = '';
            $l[] = '## Pola WordPressa, których nie przeniesiono (brak w „fields”/„core”)';
            $l[] = '| Klucz | Ofert | Przykład |';
            $l[] = '|---|---:|---|';
            foreach ($r['unmapped_meta'] as $k => $m) {
                $l[] = "| `{$k}` | {$m['count']} | " . str_replace(['|', "\n"], ['\\|', ' '], (string) $m['sample']) . ' |';
            }
        }
        $l[] = '';
        $l[] = 'Przekierowania 301: ' . count(array_filter($r['redirects'], static fn($x) => $x['type'] === 'path')) . ' adresów' . ($dryRun ? '' : ' → storage/import/przekierowania-wp.htaccess') . '.';
        return implode("\n", $l) . "\n";
    }
}
