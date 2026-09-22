<?php
/** php tests/StockModelsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Stock\Stock;
use Wonder\Plugin\Gestionale\Models\Stock\StockAlert;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Models\Stock\StockReservation;
use Wonder\Plugin\Gestionale\Support\Codes;

$modelli = [Stock::class, StockMovement::class, StockReservation::class, StockAlert::class];

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

check('le quattro tabelle hanno il prefisso del gestionale', fn () =>
    Stock::$table === 'gst_stock'
    && StockMovement::$table === 'gst_stock_movements'
    && StockReservation::$table === 'gst_stock_reservations'
    && StockAlert::$table === 'gst_stock_alerts'
);

check('il magazzino non viaggia con il deploy', function () use ($modelli) {
    foreach ($modelli as $modello) {
        if ($modello::syncSchema() !== null) {
            return false;
        }
    }

    return true;
});

check('una giacenza è una sola riga per prodotto, sede, lotto e fornitore', function () {
    $unico = Stock::tablePseudos()['uni_stock']['unique'] ?? [];

    return $unico === ['product_id', 'location_id', 'batch_id', 'supplier_id'];
});

check('il movimento ha il suo prefisso', function () use ($campo) {
    return ($campo(StockMovement::class, 'code')?->getSchema('unique_code')['prefix'] ?? null)
        === Codes::STOCK_MOVEMENT;
});

check('l\'enum dei tipi nasce completo, anche per G3 e G4', fn () =>
    StockMovement::TYPES === [
        'sale', 'sale_cancel', 'return', 'purchase',
        'adjustment', 'transfer_in', 'transfer_out',
    ]
);

check('ogni tipo ha un\'etichetta in italiano', function () {
    $etichette = StockMovement::typeLabels();

    foreach (StockMovement::TYPES as $tipo) {
        if (trim((string) ($etichette[$tipo] ?? '')) === '') {
            return false;
        }
    }

    return count($etichette) === count(StockMovement::TYPES);
});

check('il movimento tiene prima, dopo e il segno', function () use ($colonne) {
    $c = $colonne(StockMovement::class);

    return isset($c['quantity'], $c['quantity_before'], $c['quantity_after']);
});

check('le colonne che valgono zero non hanno chiave esterna', function () use ($colonne) {
    // MySQL rifiuterebbe lo zero: batch e fornitore restano vuoti fino a G3,
    // e la soglia di scorta vale sul totale, non su una sede.
    foreach ([
        [Stock::class, 'batch_id'], [Stock::class, 'supplier_id'],
        [StockMovement::class, 'batch_id'], [StockMovement::class, 'supplier_id'],
        [StockAlert::class, 'location_id'],
        [StockReservation::class, 'order_id'], [StockReservation::class, 'order_item_id'],
    ] as [$modello, $nome]) {
        if (($colonne($modello)[$nome] ?? null)?->getSchema('foreign_table') !== null) {
            return false;
        }
    }

    return true;
});

check('prodotto e sede invece sono legati alle loro tabelle', function () use ($colonne) {
    $stock = $colonne(Stock::class);

    return $stock['product_id']->getSchema('foreign_table') === 'gst_products'
        && $stock['location_id']->getSchema('foreign_table') === 'gst_locations';
});

check('le quantità hanno tre decimali', function () use ($campo) {
    foreach ([
        [Stock::class, 'quantity'],
        [StockMovement::class, 'quantity'],
        [StockReservation::class, 'quantity'],
    ] as [$modello, $nome]) {
        if ((int) ($campo($modello, $nome)?->getSchema('decimals') ?? 0) !== 3) {
            return false;
        }
    }

    return true;
});

check('nessuna colonna usa una parola riservata di MySQL', function () use ($modelli, $colonne) {
    $riservate = ['key', 'group', 'order', 'index', 'default'];

    foreach ($modelli as $modello) {
        foreach (array_keys($colonne($modello)) as $nome) {
            if (in_array(strtolower((string) $nome), $riservate, true)) {
                return false;
            }
        }
    }

    return true;
});

summary();
