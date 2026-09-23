<?php

namespace Wonder\Plugin\Gestionale\Models\Tax;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * Tipo fiscale del prodotto: beni ordinari, alimentari, libri, servizi.
 *
 * Ogni modello del catalogo ne avrà uno; insieme al paese e al tipo di cliente
 * è quello che sceglie l'aliquota (`TaxRule`). Un articolo nuovo parte dal
 * tipo «Predefinito»; un tipo nascosto non si propone più, ma resta sugli
 * articoli che lo usano.
 */
final class TaxCategory extends Model
{
    public static string $table = 'gst_tax_categories';
    public static string $folder = 'gestionale/tax-categories';
    public static string $icon = 'bi bi-tags';

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
            Column::key('position')->int(),
            Column::key('visible')->enum(['true', 'false'])->default('true'),
            Column::key('is_default')->enum(['true', 'false'])->default('false'),
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->slug()->readonlyOnUpdate()->immutableOnUpdate(),
            Field::key('name')->text()->sanitizeFirst(),
            Field::key('description')->text(),
            Field::key('position')->number()->decimals(0),
            Field::key('visible')->text()->sanitize(false),
            Field::key('is_default')->text()->sanitize(false),
        ];
    }
}
