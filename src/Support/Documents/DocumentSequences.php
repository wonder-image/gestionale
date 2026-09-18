<?php

namespace Wonder\Plugin\Gestionale\Support\Documents;

use DateTimeImmutable;
use DateTimeInterface;
use Wonder\Plugin\Gestionale\Models\Documents\DocumentSequence;
use Wonder\Sql\Transaction;

/**
 * Assegna il numero al documento.
 *
 * La riga della sequenza si legge con `FOR UPDATE` dentro una transazione:
 * due documenti creati nello stesso istante aspettano l'uno l'altro invece di
 * prendere lo stesso numero. Se una transazione è già aperta il core ci
 * aggancia un savepoint, quindi chiamare questo metodo dentro il salvataggio
 * di un documento è la cosa giusta.
 *
 * Le fatture avranno una numerazione annuale con sezionale (G8): il tipo di
 * documento è già un parametro, quindi la classe non cambierà.
 */
final class DocumentSequences
{
    public static function next(string $type, ?DateTimeInterface $at = null): string
    {
        $type = trim($type);
        $at ??= new DateTimeImmutable();
        $year = (int) $at->format('Y');
        $month = (int) $at->format('n');

        return Transaction::run(static function () use ($type, $year, $month): string {
            $row = sqlSelectForUpdate(DocumentSequence::$table, [
                'document_type' => $type,
                'year' => $year,
                'month' => $month,
            ], 1)->row;

            $current = is_array($row) ? $row : [];
            $number = (int) ($current['last_number'] ?? 0) + 1;

            if ($current === []) {
                sqlInsert(DocumentSequence::$table, [
                    'document_type' => $type,
                    'year' => $year,
                    'month' => $month,
                    'last_number' => $number,
                ]);
            } else {
                sqlModify(DocumentSequence::$table, ['last_number' => $number], 'id', (int) $current['id']);
            }

            return DocumentNumber::format($year, $month, $number);
        });
    }
}
