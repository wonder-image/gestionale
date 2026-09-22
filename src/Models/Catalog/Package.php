<?php

namespace Wonder\Plugin\Gestionale\Models\Catalog;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Columns;
use Wonder\Sql\TableSchema as Column;

/**
 * Una scatola del negozio: quanto è grande dentro e quanto pesa vuota.
 *
 * Il peso che parte è prodotto più scatola, e la scatola è una cosa del
 * negozio, non dell'articolo: due o tre imballaggi buoni descrivono tutto
 * quello che si spedisce. Le misure interne serviranno ai corrieri che
 * tariffano a volume.
 *
 * Non si sincronizza: le scatole di un negozio sono sue, come il resto del
 * catalogo.
 */
final class Package extends Model
{
    public static string $table = 'gst_packages';
    public static string $folder = 'gestionale/packages';
    public static string $icon = 'bi bi-box-seam';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('code')->length(100)->null(false)->unique(),
            Column::key('name'),
            // La tara si misura in grammi: con i due decimali del generatore
            // una busta da 50 grammi resterebbe 0,05 e una da 5 sparirebbe.
            Columns::decimal('weight'),
            Columns::decimal('length', '10,2'),
            Columns::decimal('width', '10,2'),
            Columns::decimal('height', '10,2'),
            Column::key('is_default')->enum(['true', 'false'])->default('false'),
            Column::key('position')->int(),
            Column::key('active')->enum(['true', 'false'])->default('true'),
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->slug()->readonlyOnUpdate()->immutableOnUpdate(),
            // Niente `sanitizeFirst()`: "Scatola media" non deve diventare
            // "Scatola Media", e "Busta imbottita" nemmeno.
            Field::key('name')->text(),
            Field::key('weight')->number()->decimals(3),
            Field::key('length')->number()->decimals(2),
            Field::key('width')->number()->decimals(2),
            Field::key('height')->number()->decimals(2),
            Field::key('is_default')->text()->sanitize(false),
            Field::key('position')->number()->decimals(0),
            Field::key('active')->text()->sanitize(false),
        ];
    }
}
