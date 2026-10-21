<?php

namespace Wonder\Plugin\Gestionale\Support\Export;

use Wonder\Backend\Support\SpreadsheetText;

/**
 * Un file a colonne — .csv con la virgola, .tsv con la tabulazione — come lo
 * leggono i programmi d'importazione: UTF-8 senza BOM, una riga di
 * intestazioni, virgolette solo dove servono.
 *
 * Classe pura: le celle arrivano già scritte come testo.
 */
final class Delimited
{
    public const CSV = ',';
    public const TSV = "\t";

    /**
     * @param list<string> $headers
     * @param iterable<list<string|int|float|null>> $rows celle nell'ordine delle intestazioni
     * @param bool $spreadsheetSafe disinnesca le celle che Excel leggerebbe come
     *   formula (=, +, -, @): giusto per chi apre il file a mano, sbagliato per
     *   un feed che un programma legge così com'è
     */
    public static function write(array $headers, iterable $rows, string $separator = self::CSV, bool $spreadsheetSafe = false): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        if ($separator === self::TSV) {
            // Il TSV non ha virgolette: tabulazioni e a capo dentro una cella
            // diventerebbero colonne e righe nuove.
            fwrite($handle, implode(self::TSV, array_map([self::class, 'tsvCell'], $headers))."\n");

            foreach ($rows as $row) {
                $cells = array_map(static fn ($cell): string => self::tsvCell(self::cell($cell, $spreadsheetSafe)), $row);
                fwrite($handle, implode(self::TSV, $cells)."\n");
            }
        } else {
            fputcsv($handle, array_map('strval', $headers), $separator, '"', '');

            foreach ($rows as $row) {
                fputcsv($handle, array_map(static fn ($cell): string => self::cell($cell, $spreadsheetSafe), $row), $separator, '"', '');
            }
        }

        rewind($handle);
        $text = (string) stream_get_contents($handle);
        fclose($handle);

        return $text;
    }

    /** La regola anti-formula è quella del core. */
    private static function cell(string|int|float|null $value, bool $spreadsheetSafe): string
    {
        return $spreadsheetSafe ? SpreadsheetText::cell($value) : (string) $value;
    }

    private static function tsvCell(string $value): string
    {
        return str_replace(["\t", "\r\n", "\r", "\n"], ' ', $value);
    }
}
