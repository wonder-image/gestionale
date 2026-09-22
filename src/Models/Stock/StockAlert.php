<?php

namespace Wonder\Plugin\Gestionale\Models\Stock;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Sql\TableSchema as Column;

/**
 * L'avviso di scorta minima: una riga aperta per prodotto sceso sotto soglia.
 *
 * `resolved_at` vuoto vuol dire aperto; finché lo è, l'avviso **non si
 * ripete**. `notified_at` vuoto vuol dire che l'email non è ancora partita: la
 * manda l'attività dello scheduler del piano 4, mai il salvataggio.
 *
 * `location_id` resta a zero perché la soglia vale sul disponibile totale del
 * prodotto, somma di tutte le sedi: per questo non ha chiave esterna.
 */
final class StockAlert extends Model
{
    public static string $table = 'gst_stock_alerts';
    public static string $folder = 'gestionale/stock';
    public static string $icon = 'bi bi-exclamation-triangle';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('product_id')->int()->null(false)->foreign(Product::$table),
            Column::key('location_id')->int()->default(0),
            ...static::sqlColumnsFromDataSchema(['threshold', 'quantity_at_alert']),
            Column::key('notified_at')->datetime(),
            Column::key('resolved_at')->datetime(),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_product' => ['index' => 'product_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('product_id')->number()->decimals(0),
            Field::key('location_id')->number()->decimals(0),
            Field::key('threshold')->number()->decimals(3),
            Field::key('quantity_at_alert')->number()->decimals(3),
            Field::key('notified_at')->date(),
            Field::key('resolved_at')->date(),
        ];
    }
}
