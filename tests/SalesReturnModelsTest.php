<?php
/** php tests/SalesReturnModelsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Payments\PaymentStatusLog;
use Wonder\Plugin\Gestionale\Models\Sales\OrderStatusLog;
use Wonder\Plugin\Gestionale\Models\Sales\SalesReturn;
use Wonder\Plugin\Gestionale\Models\Sales\SalesReturnItem;
use Wonder\Plugin\Gestionale\Models\Sales\SalesReturnStatusLog;
use Wonder\Plugin\Gestionale\Models\System\StatusLog;
use Wonder\Plugin\Gestionale\Support\Codes;

$colonne = static function (string $model): array {
    $colonne = [];

    foreach ($model::tableSchema() as $column) {
        $colonne[(string) $column->name] = $column;
    }

    return $colonne;
};

$campo = static function (string $model, string $key): ?object {
    foreach ($model::dataSchema() as $field) {
        if ((string) $field->key === $key) {
            return $field;
        }
    }

    return null;
};

check('le tabelle dei resi e i tre log hanno il prefisso del gestionale', fn () =>
    SalesReturn::$table === 'gst_sales_returns'
    && SalesReturnItem::$table === 'gst_sales_return_items'
    && OrderStatusLog::$table === 'gst_order_status_logs'
    && PaymentStatusLog::$table === 'gst_payment_status_logs'
    && SalesReturnStatusLog::$table === 'gst_sales_return_status_logs'
);

check('resi e log non viaggiano con il deploy', fn () =>
    SalesReturn::syncSchema() === null
    && SalesReturnItem::syncSchema() === null
    && OrderStatusLog::syncSchema() === null
    && PaymentStatusLog::syncSchema() === null
    && SalesReturnStatusLog::syncSchema() === null
);

check('il reso ha il suo prefisso', function () use ($campo) {
    return ($campo(SalesReturn::class, 'code')?->getSchema('unique_code')['prefix'] ?? null)
        === Codes::SALES_RETURN;
});

check('la testata del reso tiene tutte le colonne di 4.10', function () use ($colonne) {
    $attese = [
        'code', 'number', 'order_id', 'customer_id', 'channel', 'status',
        'location_id', 'requested_at', 'approved_at', 'received_at', 'completed_at',
        'customer_note', 'internal_note', 'user_id',
    ];

    return array_diff($attese, array_keys($colonne(SalesReturn::class))) === [];
});

check('gli stati del reso nascono completi, anche quelli del reso online', fn () =>
    SalesReturn::STATUSES === [
        'requested', 'approved', 'rejected', 'received', 'completed', 'cancelled',
    ]
    && SalesReturn::CHANNELS === ['online', 'office', 'pos']
);

check('la riga del reso tiene quantità, motivo, rientro e nota', function () use ($colonne) {
    $attese = ['sales_return_id', 'order_item_id', 'quantity', 'reason', 'restock', 'note'];

    return array_diff($attese, array_keys($colonne(SalesReturnItem::class))) === [];
});

check('i motivi del reso sono i sette di 4.10', fn () =>
    SalesReturnItem::REASONS === [
        'damaged', 'defective', 'wrong_item', 'not_as_described',
        'changed_mind', 'wrong_size', 'other',
    ]
);

check('la quantità resa tiene tre decimali', function () use ($colonne, $campo) {
    return $colonne(SalesReturnItem::class)['quantity']->getSchema('length') === '10,3'
        && (int) ($campo(SalesReturnItem::class, 'quantity')?->getSchema('decimals') ?? 0) === 3;
});

check('il reso è legato all\'ordine e alla sede che riceve la merce', function () use ($colonne) {
    $c = $colonne(SalesReturn::class);

    return $c['order_id']->getSchema('foreign_table') === 'gst_orders'
        && $c['order_id']->getSchema('null') === false
        && $c['location_id']->getSchema('foreign_table') === 'gst_locations'
        && $c['location_id']->getSchema('null') === false;
});

check('la riga del reso è legata al reso e alla riga venduta', function () use ($colonne) {
    $c = $colonne(SalesReturnItem::class);

    return $c['sales_return_id']->getSchema('foreign_table') === 'gst_sales_returns'
        && $c['order_item_id']->getSchema('foreign_table') === 'gst_order_items';
});

check('un reso da ufficio non ha cliente: la colonna resta senza chiave esterna', function () use ($colonne) {
    return $colonne(SalesReturn::class)['customer_id']->getSchema('foreign_table') === null
        && $colonne(SalesReturn::class)['user_id']->getSchema('foreign_table') === null;
});

check('i tre log puntano al loro documento e portano le colonne comuni', function () use ($colonne) {
    foreach ([
        [OrderStatusLog::class, 'order_id', 'gst_orders'],
        [PaymentStatusLog::class, 'payment_id', 'gst_payments'],
        [SalesReturnStatusLog::class, 'sales_return_id', 'gst_sales_returns'],
    ] as [$modello, $colonna, $tabella]) {
        $c = $colonne($modello);

        if ($modello::entityColumn() !== $colonna
            || $modello::entityTable() !== $tabella
            || ($c[$colonna] ?? null)?->getSchema('foreign_table') !== $tabella
            || !isset($c['field'], $c['from_value'], $c['to_value'], $c['source'], $c['response'])) {
            return false;
        }
    }

    return true;
});

check('i log non riscrivono le colonne comuni: le prendono dalla base', function () {
    $comuni = array_map(
        static fn (object $column): string => (string) $column->name,
        StatusLog::commonColumns()
    );
    $log = array_map(
        static fn (object $column): string => (string) $column->name,
        OrderStatusLog::tableSchema()
    );

    // La colonna del documento più le comuni, niente altro.
    return count($log) === count($comuni) + 1 && $log[0] === 'order_id';
});

summary();
