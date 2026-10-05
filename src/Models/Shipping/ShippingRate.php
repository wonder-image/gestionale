<?php

namespace Wonder\Plugin\Gestionale\Models\Shipping;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Columns;
use Wonder\Sql\TableSchema as Column;

/**
 * Il listino di un metodo per una zona: uno per coppia (metodo, zona). Gli
 * scaglioni di peso stanno in `ShippingRateBracket`.
 *
 * I campi facoltativi (`volumetric_divisor`, `rounding_step`,
 * `free_over_amount`, `free_under_weight`) sono NULL quando non impostati, e
 * NULL non è zero: `free_over_amount` a 0 vorrebbe dire «gratuita sempre».
 * Il margine può essere negativo. Carburante, margine, minimo e contrassegno
 * a zero non fanno niente.
 *
 * Non si sincronizza: è lavoro del commerciante.
 */
final class ShippingRate extends Model
{
    public static string $table = 'gst_shipping_rates';
    public static string $folder = 'gestionale/models';
    public static string $icon = 'bi bi-receipt';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('shipping_method_id')->int()->null(false)->foreign(ShippingMethod::$table),
            Column::key('shipping_zone_id')->int()->null(false)->foreign(ShippingZone::$table),
            Column::key('volumetric_divisor')->int(),
            Column::key('excess_mode')->enum(['total_weight', 'excess_only'])->default('total_weight'),
            Columns::decimal('fuel_surcharge_percent', '7,2')->default('0'),
            Columns::decimal('markup_percent', '7,2')->default('0'),
            Columns::decimal('rounding_step', '12,2'),
            Columns::decimal('min_price', '12,2')->default('0'),
            Columns::decimal('free_over_amount', '12,2'),
            Columns::decimal('free_under_weight', '10,3'),
            Columns::decimal('cod_fee', '12,2')->default('0'),
            Column::key('active')->enum(['true', 'false'])->default('true'),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'uni_method_zone' => ['unique' => ['shipping_method_id', 'shipping_zone_id']],
            'ind_zone' => ['index' => 'shipping_zone_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('shipping_method_id')->number()->decimals(0),
            Field::key('shipping_zone_id')->number()->decimals(0),
            Field::key('volumetric_divisor')->number()->decimals(0),
            Field::key('excess_mode')->text()->sanitize(false),
            Field::key('fuel_surcharge_percent')->number()->decimals(2),
            Field::key('markup_percent')->number()->decimals(2),
            Field::key('rounding_step')->number()->decimals(2),
            Field::key('min_price')->number()->decimals(2),
            Field::key('free_over_amount')->number()->decimals(2),
            Field::key('free_under_weight')->number()->decimals(3),
            Field::key('cod_fee')->number()->decimals(2),
            Field::key('active')->text()->sanitize(false),
        ];
    }
}
