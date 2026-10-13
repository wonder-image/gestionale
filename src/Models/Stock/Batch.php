<?php

namespace Wonder\Plugin\Gestionale\Models\Stock;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Sql\TableSchema as Column;

/**
 * Il lotto di una versione (G3, funzionalità «Lotti e scadenze»).
 *
 * Nasce al carico, solo per gli articoli che gestiscono i lotti; il numero è
 * unico per versione (G3.10): lo stesso numero in un altro carico è lo stesso
 * lotto. La scadenza può mancare. `supplier_id` è il fornitore del primo
 * carico, 0 se sconosciuto.
 *
 * La giacenza del lotto non sta qui: sta nei secchi di `gst_stock` con il suo
 * `batch_id`, come tutto il resto del magazzino.
 */
final class Batch extends Model
{
    public static string $table = 'gst_batches';
    public static string $folder = 'gestionale/stock';
    public static string $icon = 'bi bi-upc';

    /** I lotti sono lavoro di chi vende, non configurazione. */
    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            ...static::sqlColumnsFromDataSchema(['code']),
            Column::key('product_id')->int()->null(false)->foreign(Product::$table),
            Column::key('number')->length(50),
            Column::key('expiry_date')->date()->null(true),
            Column::key('supplier_id')->int()->default(0),
            Column::key('note')->type('TEXT'),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'uni_batch' => ['unique' => ['product_id', 'number']],
            'ind_expiry' => ['index' => 'expiry_date'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->uniqueCode(Codes::BATCH),
            Field::key('product_id')->number()->decimals(0),
            Field::key('number')->text(),
            Field::key('expiry_date')->date(),
            Field::key('supplier_id')->number()->decimals(0),
            Field::key('note')->text(),
        ];
    }
}
