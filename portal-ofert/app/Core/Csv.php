<?php

declare(strict_types=1);

namespace App\Core;

/** Eksport CSV, który otwiera się poprawnie w polskim Excelu (UTF-8 z BOM, separator „;”). */
final class Csv
{
    /** @param string[] $header @param iterable<array> $rows */
    public static function response(string $filename, array $header, iterable $rows): Response
    {
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, $header, ';', '"', '');
        foreach ($rows as $row) {
            // Ochrona przed „CSV injection”: komórki zaczynające się od = + - @ nie są formułami.
            fputcsv($fh, array_map(static fn($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v, array_values($row)), ';', '"', '');
        }
        rewind($fh);
        $body = (string) stream_get_contents($fh);
        fclose($fh);
        return new Response($body, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . preg_replace('/[^a-z0-9._-]/i', '_', $filename) . '"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
