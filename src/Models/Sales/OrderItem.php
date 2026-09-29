<?php

namespace Wonder\Plugin\Gestionale\Models\Sales;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Columns;
use Wonder\Sql\TableSchema as Column;

/**
 * Le righe dell'ordine (4.7).
 *
 * Nome, SKU, descrizione e unità sono **copiati** al momento dell'aggiunta: un
 * ordine di marzo deve continuare a dire cosa è stato venduto anche se il
 * prodotto oggi si chiama diversamente o non esiste più. Per la stessa ragione
 * `product_id` è un intero semplice, senza chiave esterna: le righe `text`,
 * `shipping` e `fee` non hanno prodotto, e un prodotto venduto non si elimina
 * comunque — lo impedisce già il controllo dei movimenti di magazzino.
 *
 * Componenti e opzioni di un prodotto composto sono righe figlie
 * (`parent_item_id`) a prezzo zero: servono allo scarico del magazzino e al DDT.
 */
final class OrderItem extends Model
{
    public static string $table = 'gst_order_items';
    public static string $folder = 'gestionale/sales';
    public static string $icon = 'bi bi-list-ul';

    public const TYPES = ['product', 'custom', 'text', 'shipping', 'fee'];
    public const PRICE_SOURCES = ['price_list', 'campaign', 'sale_price', 'base', 'manual'];
    public const DISCOUNT_TYPES = ['none', 'amount', 'percent'];

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('order_id')->int()->null(false)->foreign(Order::$table),
            ...static::sqlColumnsFromDataSchema([
                'list_price', 'unit_price', 'discount_value', 'order_discount_amount',
                'tax_rate', 'customization_surcharge', 'line_total',
            ]),
            Column::key('type')->enum(static::TYPES)->default('product'),
            Column::key('product_id')->int()->default(0),
            Column::key('parent_item_id')->int()->default(0),
            Column::key('position')->int(),
            // Copia del prodotto
            Column::key('sku')->length(100),
            Column::key('name'),
            Column::key('description')->type('TEXT'),
            Column::key('unit')->length(10),
            // Prezzo
            Columns::decimal('quantity'),
            Column::key('price_source')->enum(static::PRICE_SOURCES)->default('base'),
            Column::key('discount_type')->enum(static::DISCOUNT_TYPES)->default('none'),
            Column::key('price_list_id')->int()->default(0),
            Column::key('discount_campaign_id')->int()->default(0),
            // IVA
            Column::key('tax_category_id')->int()->default(0),
            Column::key('tax_id')->int()->default(0),
            Column::key('tax_nature')->length(10),
            // Personalizzazione (G5): campo, etichetta, valore, file, sovrapprezzo
            Column::key('customization')->json(),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_order' => ['index' => 'order_id'],
            'ind_product' => ['index' => 'product_id'],
            'ind_parent' => ['index' => 'parent_item_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('order_id')->number()->decimals(0),
            Field::key('type')->text()->sanitize(false),
            Field::key('product_id')->number()->decimals(0),
            Field::key('parent_item_id')->number()->decimals(0),
            Field::key('position')->number()->decimals(0),
            Field::key('sku')->text(),
            // Niente `sanitizeFirst()`: "XL" deve restare "XL".
            Field::key('name')->text(),
            Field::key('description')->text(),
            Field::key('unit')->text()->sanitize(false),
            Field::key('quantity')->number()->decimals(3),
            Field::key('list_price')->number()->decimals(2),
            Field::key('unit_price')->number()->decimals(2),
            Field::key('price_source')->text()->sanitize(false),
            Field::key('discount_type')->text()->sanitize(false),
            Field::key('discount_value')->number()->decimals(2),
            Field::key('order_discount_amount')->number()->decimals(2),
            Field::key('price_list_id')->number()->decimals(0),
            Field::key('discount_campaign_id')->number()->decimals(0),
            Field::key('tax_category_id')->number()->decimals(0),
            Field::key('tax_id')->number()->decimals(0),
            Field::key('tax_rate')->number()->decimals(2),
            Field::key('tax_nature')->text()->sanitize(false),
            Field::key('customization')->json(),
            Field::key('customization_surcharge')->number()->decimals(2),
            Field::key('line_total')->number()->decimals(2),
        ];
    }
}
