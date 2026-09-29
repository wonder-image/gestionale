<?php

namespace Wonder\Plugin\Gestionale\Models\Sales;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Models\Locations\Location;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Sql\TableSchema as Column;

/**
 * La testata del reso (4.10).
 *
 * L'enum degli stati nasce completo: in G4 il commerciante registra un reso
 * già arrivato e parte da `received`, ma `requested`, `approved` e `rejected`
 * esistono da subito perché li accenderà il reso online dall'area cliente,
 * senza toccare una tabella piena.
 *
 * `location_id` è la sede che **riceve** la merce, ed è obbligatoria: senza
 * sapere dove rientra la merce non si può scrivere il movimento `return`.
 * `customer_id` invece può valere zero — un reso registrato al banco non ha per
 * forza un cliente in anagrafica — e resta senza chiave esterna.
 */
final class SalesReturn extends Model
{
    public static string $table = 'gst_sales_returns';
    public static string $folder = 'gestionale/sales';
    public static string $icon = 'bi bi-arrow-return-left';

    public const CHANNELS = ['online', 'office', 'pos'];

    public const STATUSES = [
        'requested', 'approved', 'rejected', 'received', 'completed', 'cancelled',
    ];

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            ...static::sqlColumnsFromDataSchema(['code']),
            Column::key('number')->length(20),
            Column::key('order_id')->int()->null(false)->foreign(Order::$table),
            Column::key('customer_id')->int()->default(0),
            Column::key('channel')->enum(static::CHANNELS)->default('office'),
            Column::key('status')->enum(static::STATUSES)->default('received'),
            // Dove rientra la merce: serve al movimento `return`, sempre valorizzata.
            Column::key('location_id')->int()->null(false)->foreign(Location::$table),
            Column::key('requested_at')->datetime(),
            Column::key('approved_at')->datetime(),
            Column::key('received_at')->datetime(),
            Column::key('completed_at')->datetime(),
            Column::key('customer_note')->type('TEXT'),
            Column::key('internal_note')->type('TEXT'),
            Column::key('user_id')->int(),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_order' => ['index' => 'order_id'],
            'ind_status' => ['index' => 'status'],
            'ind_number' => ['index' => 'number'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->uniqueCode(Codes::SALES_RETURN),
            Field::key('number')->text()->sanitize(false),
            Field::key('order_id')->number()->decimals(0),
            Field::key('customer_id')->number()->decimals(0),
            Field::key('channel')->text()->sanitize(false),
            Field::key('status')->text()->sanitize(false),
            Field::key('location_id')->number()->decimals(0),
            Field::key('requested_at')->date(),
            Field::key('approved_at')->date(),
            Field::key('received_at')->date(),
            Field::key('completed_at')->date(),
            Field::key('customer_note')->text(),
            Field::key('internal_note')->text(),
            Field::key('user_id')->number()->decimals(0),
        ];
    }
}
