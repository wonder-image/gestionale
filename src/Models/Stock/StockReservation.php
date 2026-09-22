<?php

namespace Wonder\Plugin\Gestionale\Models\Stock;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Locations\Location;
use Wonder\Sql\TableSchema as Column;

/**
 * La merce impegnata da un carrello o da un ordine non ancora confermato.
 *
 * In G2b la tabella **nasce vuota**: la riempirà il checkout in G4. Esiste già
 * perché "disponibile" — giacenza meno prenotazioni attive — è la parola che
 * useranno l'elenco delle giacenze, la scheda e la vetrina, e deve voler dire
 * la stessa cosa da subito (G2b.4).
 *
 * `order_id` e `order_item_id` non hanno chiave esterna: le tabelle degli
 * ordini non esistono ancora.
 */
final class StockReservation extends Model
{
    public static string $table = 'gst_stock_reservations';
    public static string $folder = 'gestionale/stock';
    public static string $icon = 'bi bi-hourglass-split';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('product_id')->int()->null(false)->foreign(Product::$table),
            Column::key('location_id')->int()->null(false)->foreign(Location::$table),
            ...static::sqlColumnsFromDataSchema(['quantity']),
            Column::key('order_id')->int(),
            Column::key('order_item_id')->int(),
            Column::key('expires_at')->datetime(),
            Column::key('released_at')->datetime(),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_product' => ['index' => 'product_id'],
            'ind_order' => ['index' => 'order_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('product_id')->number()->decimals(0),
            Field::key('location_id')->number()->decimals(0),
            Field::key('quantity')->number()->decimals(3),
            Field::key('order_id')->number()->decimals(0),
            Field::key('order_item_id')->number()->decimals(0),
            Field::key('expires_at')->date(),
            Field::key('released_at')->date(),
        ];
    }
}
