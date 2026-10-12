<?php
/** php tests/StockDocumentModelsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Stock\StockDocument;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocumentItem;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocumentStatusLog;
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

check('le tre tabelle dei documenti hanno il prefisso del gestionale', fn () =>
    StockDocument::$table === 'gst_stock_documents'
    && StockDocumentItem::$table === 'gst_stock_document_items'
    && StockDocumentStatusLog::$table === 'gst_stock_document_status_logs'
);

check('i documenti non viaggiano con il deploy', fn () =>
    StockDocument::syncSchema() === null
    && StockDocumentItem::syncSchema() === null
    && StockDocumentStatusLog::syncSchema() === null
);

check('il documento ha il suo prefisso di codice', fn () =>
    ($campo(StockDocument::class, 'code')?->getSchema('unique_code')['prefix'] ?? null) === Codes::STOCK_DOCUMENT
);

check('tipi e stati nascono completi, trasferimento e «in viaggio» compresi (G3.16)', fn () =>
    StockDocument::TYPES === ['receipt', 'issue', 'stocktake', 'transfer']
    && StockDocument::STATUSES === ['draft', 'in_transit', 'completed', 'cancelled']
);

check('la testata tiene tutte le colonne di 4.1, anche quelle di G3', function () use ($colonne) {
    $attese = [
        'code', 'type', 'number', 'date', 'location_id', 'to_location_id',
        'supplier_id', 'supplier_document_number', 'supplier_document_date',
        'reason', 'status', 'total_cost', 'note', 'user_id',
    ];

    return array_diff($attese, array_keys($colonne(StockDocument::class))) === [];
});

check('la sede è obbligatoria e legata alle sedi; sede d\'arrivo e fornitore no', function () use ($colonne) {
    $c = $colonne(StockDocument::class);

    return $c['location_id']->getSchema('foreign_table') === 'gst_locations'
        && $c['location_id']->getSchema('null') === false
        && $c['to_location_id']->getSchema('foreign_table') === null
        && $c['supplier_id']->getSchema('foreign_table') === null
        && $c['user_id']->getSchema('foreign_table') === null;
});

check('la riga tiene quantità, atteso, lotto, fornitore, costi e posizione', function () use ($colonne) {
    $attese = [
        'stock_document_id', 'product_id', 'batch_id', 'supplier_id',
        'quantity', 'expected_quantity', 'unit_cost', 'line_total', 'position',
    ];

    return array_diff($attese, array_keys($colonne(StockDocumentItem::class))) === [];
});

check('quantità e atteso hanno tre decimali e possono restare vuoti', function () use ($colonne) {
    $c = $colonne(StockDocumentItem::class);

    return $c['quantity']->getSchema('length') === '10,3'
        && $c['quantity']->getSchema('null') === true
        && $c['expected_quantity']->getSchema('length') === '10,3'
        && $c['expected_quantity']->getSchema('null') === true
        && $c['unit_cost']->getSchema('length') === '10,4';
});

check('la riga è legata al documento e alla versione', function () use ($colonne) {
    $c = $colonne(StockDocumentItem::class);

    return $c['stock_document_id']->getSchema('foreign_table') === 'gst_stock_documents'
        && $c['product_id']->getSchema('foreign_table') === 'gst_products'
        && $c['batch_id']->getSchema('foreign_table') === null;
});

check('il log punta al documento e porta le colonne comuni', function () use ($colonne) {
    $c = $colonne(StockDocumentStatusLog::class);

    return StockDocumentStatusLog::entityColumn() === 'stock_document_id'
        && StockDocumentStatusLog::entityTable() === 'gst_stock_documents'
        && $c['stock_document_id']->getSchema('foreign_table') === 'gst_stock_documents'
        && isset($c['field'], $c['from_value'], $c['to_value'], $c['source'], $c['response']);
});

summary();
