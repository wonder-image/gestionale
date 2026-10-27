<?php

namespace Wonder\Plugin\Gestionale\Models\Pricing;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Support\Columns;
use Wonder\Sql\TableSchema as Column;

/**
 * Una riga di listino: il prezzo di un prodotto vendibile da una quantità in su.
 *
 * `min_quantity` 1 è il prezzo normale, di più è uno scaglione: vale la riga
 * con la quantità minima più alta che non supera quella ordinata. Il prezzo è
 * IVA inclusa o esclusa come dice il listino.
 */
final class PriceListItem extends Model
{
    public static string $table = 'gst_price_list_items';
    public static string $folder = 'gestionale/pricing';
    public static string $icon = 'bi bi-journal-text';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('price_list_id')->int()->null(false)->foreign(PriceList::$table),
            Column::key('product_id')->int()->null(false)->foreign(Product::$table),
            Columns::decimal('min_quantity')->default(1),
            Columns::decimal('price', '12,2'),
            Column::key('position')->int(),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_price_list_product' => ['index' => ['price_list_id', 'product_id']],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('price_list_id')->number()->decimals(0),
            Field::key('product_id')->number()->decimals(0),
            Field::key('min_quantity')->number()->decimals(3),
            Field::key('price')->number()->decimals(2),
            Field::key('position')->number()->decimals(0),
        ];
    }
}
