<?php

namespace Wonder\Plugin\Gestionale\Models\Catalog;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Columns;
use Wonder\Sql\TableSchema as Column;

/**
 * Un campo che il cliente compila comprando: un testo (l'incisione) o una
 * scelta fra opzioni (la confezione).
 *
 * `max_length` vale solo per `text`; le opzioni di una `choice` stanno in
 * `gst_customization_options`. Il sovrapprezzo di una scelta è quello della
 * personalizzazione più quello dell'opzione. Il collegamento agli articoli sta
 * in `gst_product_model_customizations`.
 *
 * Non si sincronizza: è lavoro del commerciante.
 */
final class Customization extends Model
{
    public static string $table = 'gst_customizations';
    public static string $folder = 'gestionale/customizations';
    public static string $icon = 'bi bi-pencil-square';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            ...static::sqlColumnsFromDataSchema(['code']),
            Column::key('name'),
            Column::key('label'),
            Column::key('help_text')->type('TEXT'),
            Column::key('kind')->enum(['text', 'choice'])->default('text'),
            Column::key('max_length')->int(),
            Columns::decimal('surcharge', '12,2'),
            Column::key('active')->enum(['true', 'false'])->default('true'),
            Column::key('position')->int(),
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->uniqueCode(Codes::CUSTOMIZATION),
            Field::key('name')->text()->sanitizeFirst(),
            Field::key('label')->text(),
            Field::key('help_text')->text(),
            Field::key('kind')->text()->sanitize(false),
            Field::key('max_length')->number()->decimals(0),
            Field::key('surcharge')->number()->decimals(2),
            Field::key('active')->text()->sanitize(false),
            Field::key('position')->number()->decimals(0),
        ];
    }
}
