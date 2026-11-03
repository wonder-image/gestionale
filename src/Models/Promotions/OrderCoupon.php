<?php

namespace Wonder\Plugin\Gestionale\Models\Promotions;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Support\Columns;
use Wonder\Sql\TableSchema as Column;

/**
 * I coupon applicati a un carrello o a un ordine, nell'ordine in cui il
 * cliente li ha scritti (gestionale#113). È la verità: `coupon_id` e
 * `coupon_code` dell'ordine ne sono solo lo specchio. `discount_amount` è
 * quanto ha tolto quel coupon all'ultimo ricalcolo.
 */
final class OrderCoupon extends Model
{
    public static string $table = 'gst_order_coupons';
    public static string $folder = 'gestionale/models';
    public static string $icon = 'bi bi-ticket-perforated';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('order_id')->int()->null(false)->foreign(Order::$table),
            Column::key('coupon_id')->int()->null(false)->foreign(Coupon::$table),
            Column::key('coupon_code_id')->int()->default(0),
            Column::key('code')->length(100),
            Column::key('position')->int()->default(0),
            Columns::decimal('discount_amount', '12,2'),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_order' => ['index' => 'order_id'],
            'ind_coupon' => ['index' => 'coupon_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('order_id')->number()->decimals(0),
            Field::key('coupon_id')->number()->decimals(0),
            Field::key('coupon_code_id')->number()->decimals(0),
            Field::key('code')->text()->sanitize(false),
            Field::key('position')->number()->decimals(0),
            Field::key('discount_amount')->number()->decimals(2),
        ];
    }
}
