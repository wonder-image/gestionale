<?php

namespace Wonder\Plugin\Gestionale\Models\Promotions;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * I clienti a cui un coupon è assegnato. Se non ce n'è nessuno il coupon è
 * di tutti. Il cliente è un intero senza chiave esterna, come negli ordini.
 */
final class CouponCustomer extends Model
{
    public static string $table = 'gst_coupon_customers';
    public static string $folder = 'gestionale/models';
    public static string $icon = 'bi bi-person';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('coupon_id')->int()->null(false)->foreign(Coupon::$table),
            Column::key('customer_id')->int()->null(false),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_coupon' => ['index' => 'coupon_id'],
            'ind_customer' => ['index' => 'customer_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('coupon_id')->number()->decimals(0),
            Field::key('customer_id')->number()->decimals(0),
        ];
    }
}
