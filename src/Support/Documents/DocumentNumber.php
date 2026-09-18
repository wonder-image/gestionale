<?php

namespace Wonder\Plugin\Gestionale\Support\Documents;

/**
 * Formato del numero dei documenti: `{YYYY}/{mm}{nnnn}`, per esempio
 * `2026/090001`. Il progressivo riparte ogni mese e ogni tipo di documento ha
 * la propria sequenza; oltre i 9999 documenti nel mese il numero continua con
 * una cifra in più invece di ricominciare.
 *
 * Classe pura: qui c'è solo il formato, il progressivo lo assegna
 * `DocumentSequences`.
 */
final class DocumentNumber
{
    /** Cifre minime del progressivo. */
    private const DIGITS = 4;

    public static function format(int $year, int $month, int $number): string
    {
        return sprintf(
            '%04d/%02d%s',
            $year,
            $month,
            str_pad((string) $number, self::DIGITS, '0', STR_PAD_LEFT)
        );
    }

    /** @return array{year: int, month: int, number: int}|null */
    public static function parse(string $number): ?array
    {
        if (preg_match('#^(\d{4})/(\d{2})(\d{'.self::DIGITS.',})$#', trim($number), $matches) !== 1) {
            return null;
        }

        return [
            'year' => (int) $matches[1],
            'month' => (int) $matches[2],
            'number' => (int) $matches[3],
        ];
    }
}
