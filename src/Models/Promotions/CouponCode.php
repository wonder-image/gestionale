<?php

namespace Wonder\Plugin\Gestionale\Models\Promotions;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * Un codice generato in blocco sotto un coupon modello (gestionale#113): le
 * regole sono del coupon, il codice vale **una volta**. È usato se ha un
 * utilizzo non rilasciato con il suo `coupon_code_id`.
 *
 * Il codice è unico insieme a `gst_coupons.code`: lo controlla
 * `CouponCodes::generate`, l'indice unico è l'ultima difesa.
 */
final class CouponCode extends Model
{
    public static string $table = 'gst_coupon_codes';
    public static string $folder = 'gestionale/models';
    public static string $icon = 'bi bi-upc';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('coupon_id')->int()->null(false)->foreign(Coupon::$table),
            Column::key('code')->length(100)->null(false)->unique(),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_coupon' => ['index' => 'coupon_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('coupon_id')->number()->decimals(0),
            Field::key('code')->text()->sanitize(false),
        ];
    }
}
