<?php

namespace Wonder\Plugin\Gestionale\Models\Catalog;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * Valore di un attributo a elenco: "Blu", "M", "Cotone".
 *
 * Esiste solo per i tipi `select` e `color`; `text` e `number` scrivono il
 * valore sul collegamento del prodotto. `color` tiene il codice esadecimale per
 * il pallino in vetrina, `image` la fantasia quando un colore non basta.
 */
final class AttributeValue extends Model
{
    public static string $table = 'gst_attribute_values';
    public static string $folder = 'gestionale/attributes';
    public static string $icon = 'bi bi-palette';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('attribute_id')->int()->null(false)->foreign(Attribute::$table),
            Column::key('label'),
            Column::key('color')->length(20),
            Column::key('image')->json(),
            Column::key('position')->int(),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_attribute' => ['index' => 'attribute_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('attribute_id')->number()->decimals(0),
            // Niente `sanitizeFirst()`: un'etichetta è spesso una sigla, e
            // "XL" non deve diventare "Xl".
            Field::key('label')->text(),
            Field::key('color')->text(),
            Field::key('image')
                ->image()
                ->extensions(['png', 'jpg', 'jpeg', 'webp'])
                ->maxSize(2)
                ->maxFile(1)
                ->dir('/catalogo/attributi/')
                ->name('{label}'),
            Field::key('position')->number()->decimals(0),
        ];
    }
}
