<?php
/** php tests/ProductSupplierModelsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Catalog\ProductSupplier;

$colonne = static function (): array {
    $colonne = [];

    foreach (ProductSupplier::tableSchema() as $column) {
        $colonne[(string) $column->name] = $column;
    }

    return $colonne;
};

$campo = static function (string $key): ?object {
    foreach (ProductSupplier::dataSchema() as $field) {
        if ((string) $field->key === $key) {
            return $field;
        }
    }

    return null;
};

check('la tabella ha il prefisso del gestionale', fn () =>
    ProductSupplier::$table === 'gst_product_suppliers'
);

// I fornitori sono del prodotto (P107): la tabella dell'articolo non c'è più.
check('i fornitori stanno in una tabella sola', fn () =>
    !class_exists(\Wonder\Plugin\Gestionale\Models\Catalog\ProductModelSupplier::class)
);

check('i costi dei fornitori non viaggiano con il deploy', fn () =>
    ProductSupplier::syncSchema() === null
);

check('ha tutte e sole le colonne della spec, senza preferito', function () use ($colonne) {
    $nomi = array_keys($colonne());
    sort($nomi);

    return $nomi === ['cost', 'position', 'product_id', 'supplier_id', 'supplier_sku'];
});

check('opzione e fornitore sono obbligatori e legati alle loro tabelle', function () use ($colonne) {
    $c = $colonne();

    return $c['product_id']->getSchema('foreign_table') === 'gst_products'
        && $c['supplier_id']->getSchema('foreign_table') === 'gst_contacts'
        && $c['product_id']->getSchema('null') === false
        && $c['supplier_id']->getSchema('null') === false;
});

check('il codice del fornitore sta in cento caratteri', fn () =>
    (string) $colonne()['supplier_sku']->getSchema('length') === '100'
);

check('il costo ha quattro decimali e può restare vuoto', function () use ($colonne, $campo) {
    $costo = $colonne()['cost'];

    // Vuoto vuol dire «non lo so»: uno zero farebbe del fornitore il più
    // conveniente e abbasserebbe il valore del magazzino.
    return $costo->getSchema('type') === 'DECIMAL'
        && $costo->getSchema('length') === '12,4'
        && $costo->getSchema('null') !== false
        && (int) ($campo('cost')?->getSchema('decimals') ?? 0) === 4;
});

check('opzione e fornitore hanno il loro indice, e nessun indice unico', function () {
    // Il salvataggio prima scrive e poi toglie: uno scambio di righe farebbe
    // inciampare un indice unico a metà. I doppioni li rifiuta la scheda.
    foreach (ProductSupplier::tablePseudos() as $definizione) {
        if (isset($definizione['unique'])) {
            return false;
        }
    }

    return (ProductSupplier::tablePseudos()['ind_product']['index'] ?? null) === 'product_id'
        && (ProductSupplier::tablePseudos()['ind_supplier']['index'] ?? null) === 'supplier_id';
});

check('nessuna colonna usa una parola riservata di MySQL', function () use ($colonne) {
    $riservate = ['key', 'group', 'order', 'index', 'default'];

    foreach (array_keys($colonne()) as $nome) {
        if (in_array(strtolower((string) $nome), $riservate, true)) {
            return false;
        }
    }

    return true;
});

summary();
