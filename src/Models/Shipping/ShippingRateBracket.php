<?php

namespace Wonder\Plugin\Gestionale\Models\Shipping;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Columns;
use Wonder\Sql\TableSchema as Column;

/**
 * Una riga di un listino. `price`: fino a `max_weight` kg si paga `amount`.
 * `excess`: oltre l'ultimo scaglione si paga `amount` euro al kg (il
 * `max_weight` non conta).
 */
final class ShippingRateBracket extends Model
{
    public static string $table = 'gst_shipping_rate_brackets';
    public static string $folder = 'gestionale/models';
    public static string $icon = 'bi bi-list-ol';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('shipping_rate_id')->int()->null(false)->foreign(ShippingRate::$table),
            Column::key('type')->enum(['price', 'excess'])->default('price'),
            Columns::decimal('max_weight', '10,3'),
            Columns::decimal('amount', '12,2'),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_rate' => ['index' => 'shipping_rate_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('shipping_rate_id')->number()->decimals(0),
            Field::key('type')->text()->sanitize(false),
            Field::key('max_weight')->number()->decimals(3),
            Field::key('amount')->number()->decimals(2),
        ];
    }
}
