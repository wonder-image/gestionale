<?php

namespace Wonder\Plugin\Gestionale\Models\Catalog;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Sql\TableSchema as Column;

/**
 * Attributo del catalogo: colore, taglia, materiale, peso.
 *
 * `level` dice dove vive l'attributo e decide tutto il resto: `model` descrive
 * l'articolo, `variant` distingue le varianti, `product` distingue i prodotti
 * dentro una variante. `type` dice come si scrive il valore: `select` e `color`
 * pescano da `gst_attribute_values`, `text` e `number` scrivono direttamente
 * sul collegamento del prodotto.
 *
 * Il nome macchina sta in `slug` e il gruppo in `group_name`: `key` e `group`
 * sono parole riservate di MySQL e il costruttore di query del core mette le
 * virgolette ai nomi solo in INSERT, UPDATE e WHERE, non nell'ORDER BY.
 *
 * Il catalogo non si sincronizza: è lavoro del commerciante.
 */
final class Attribute extends Model
{
    public static string $table = 'gst_attributes';
    public static string $folder = 'gestionale/attributes';
    public static string $icon = 'bi bi-sliders';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            ...static::sqlColumnsFromDataSchema(['code']),
            Column::key('slug')->length(100)->unique(),
            Column::key('name'),
            Column::key('type')->enum(['select', 'color', 'text', 'number'])->default('select'),
            Column::key('level')->enum(['model', 'variant', 'product'])->default('product'),
            Column::key('unit')->length(20),
            Column::key('group_name'),
            Column::key('is_filterable')->enum(['true', 'false'])->default('true'),
            Column::key('is_visible')->enum(['true', 'false'])->default('true'),
            Column::key('position')->int(),
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->uniqueCode(Codes::ATTRIBUTE),
            Field::key('slug')->text()->slug()->readonlyOnUpdate()->immutableOnUpdate(),
            Field::key('name')->text()->sanitizeFirst(),
            Field::key('type')->text()->sanitize(false),
            Field::key('level')->text()->sanitize(false),
            Field::key('unit')->text(),
            Field::key('group_name')->text()->sanitizeFirst(),
            Field::key('is_filterable')->text()->sanitize(false),
            Field::key('is_visible')->text()->sanitize(false),
            Field::key('position')->number()->decimals(0),
        ];
    }
}
