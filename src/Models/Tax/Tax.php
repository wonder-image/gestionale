<?php

namespace Wonder\Plugin\Gestionale\Models\Tax;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * Aliquota IVA: valore, natura per le operazioni a zero e descrizione da
 * riportare in fattura.
 *
 * Le aliquote non si eliminano, si mostrano o si nascondono: un documento già
 * emesso continua a puntare alla sua. `nature` si compila solo quando `rate` è
 * zero (esenti, non imponibili, inversione contabile).
 *
 * Il codice è parlante (`22`, `n3-2`), non generato: è una tabella di
 * configurazione e viaggia con il deploy.
 */
final class Tax extends Model
{
    public static string $table = 'gst_taxes';
    public static string $folder = 'gestionale/taxes';
    public static string $icon = 'bi bi-percent';

    public static function syncSchema(): ?SyncSchema
    {
        return SyncSchema::multiRow()->keepIds()->localOnly();
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('code')->length(100)->null(false)->unique(),
            Column::key('name'),
            Column::key('description')->type('TEXT'),
            Column::key('invoice_description'),
            ...static::sqlColumnsFromDataSchema(['rate']),
            Column::key('nature')->length(10),
            Column::key('visible')->enum(['true', 'false'])->default('true'),
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->slug()->readonlyOnUpdate()->immutableOnUpdate(),
            Field::key('name')->text()->sanitizeFirst(),
            Field::key('description')->text(),
            Field::key('invoice_description')->text(),
            Field::key('rate')->number()->decimals(2),
            Field::key('nature')->text()->sanitize(false),
            Field::key('visible')->text()->sanitize(false),
        ];
    }
}
