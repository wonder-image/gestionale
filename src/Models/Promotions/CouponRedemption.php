<?php

namespace Wonder\Plugin\Gestionale\Models\Promotions;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Support\Columns;
use Wonder\Sql\TableSchema as Column;

/**
 * Un uso di un coupon: l'ordine che lo ha consumato, chi lo ha usato e quanto
 * ha tolto. Si crea alla creazione dell'ordine. `released_at` si riempie se
 * l'ordine viene annullato: l'uso non conta più e il coupon torna disponibile.
 *
 * `customer_id` vale zero per un ospite; per lui vale l'email.
 */
final class CouponRedemption extends Model
{
    public static string $table = 'gst_coupon_redemptions';
    public static string $folder = 'gestionale/models';
    public static string $icon = 'bi bi-ticket-perforated';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('coupon_id')->int()->null(false)->foreign(Coupon::$table),
            Column::key('order_id')->int()->null(false)->foreign(Order::$table),
            Column::key('customer_id')->int()->default(0),
            Column::key('email'),
            Columns::decimal('discount_amount', '12,2'),
            Column::key('redeemed_at')->datetime(),
            Column::key('released_at')->datetime(),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_coupon' => ['index' => 'coupon_id'],
            'ind_order' => ['index' => 'order_id'],
            'ind_customer' => ['index' => 'customer_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('coupon_id')->number()->decimals(0),
            Field::key('order_id')->number()->decimals(0),
            Field::key('customer_id')->number()->decimals(0),
            Field::key('email')->text()->sanitize(false),
            Field::key('discount_amount')->number()->decimals(2),
            Field::key('redeemed_at')->date(),
            Field::key('released_at')->date(),
        ];
    }
}
