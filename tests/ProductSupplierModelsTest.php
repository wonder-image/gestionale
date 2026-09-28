<?php
/** php tests/ProductSupplierModelsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelSupplier;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductSupplier;

$modelli = [ProductModelSupplier::class, ProductSupplier::class];

$colonne = static function (string $model = ProductSupplier::class): array {
    $colonne = [];

    foreach ($model::tableSchema() as $column) {
        $colonne[(string) $column->name] = $column;
    }

    return $colonne;
};

$campo = static function (string $key, string $model = ProductSupplier::class): ?object {
    foreach ($model::dataSchema() as $field) {
        if ((string) $field->key === $key) {
            return $field;
        }
    }

    return null;
};

check('le due tabelle hanno il prefisso del gestionale', fn () =>
    ProductModelSupplier::$table === 'gst_product_model_suppliers'
    && ProductSupplier::$table === 'gst_product_suppliers'
);

check('i costi dei fornitori non viaggiano con il deploy', fn () =>
    ProductModelSupplier::syncSchema() === null
    && ProductSupplier::syncSchema() === null
);

check('l\'articolo ha tutte e sole le colonne della spec', function () use ($colonne) {
    $nomi = array_keys($colonne(ProductModelSupplier::class));
    sort($nomi);

    return $nomi === ['cost', 'position', 'product_model_id', 'supplier_id', 'supplier_sku'];
});

check('l\'opzione ha tutte e sole le colonne della spec, senza preferito', function () use ($colonne) {
    $nomi = array_keys($colonne());
    sort($nomi);

    return $nomi === ['cost', 'position', 'product_id', 'supplier_id', 'supplier_sku'];
});

check('articolo e fornitore sono obbligatori e legati alle loro tabelle', function () use ($colonne) {
    $c = $colonne(ProductModelSupplier::class);

    return $c['product_model_id']->getSchema('foreign_table') === 'gst_product_models'
        && $c['supplier_id']->getSchema('foreign_table') === 'gst_contacts'
        && $c['product_model_id']->getSchema('null') === false
        && $c['supplier_id']->getSchema('null') === false;
});

check('opzione e fornitore sono obbligatori e legati alle loro tabelle', function () use ($colonne) {
    $c = $colonne();

    return $c['product_id']->getSchema('foreign_table') === 'gst_products'
        && $c['supplier_id']->getSchema('foreign_table') === 'gst_contacts'
        && $c['product_id']->getSchema('null') === false
        && $c['supplier_id']->getSchema('null') === false;
});

check('il codice del fornitore sta in cento caratteri, su tutte e due', function () use ($modelli, $colonne) {
    foreach ($modelli as $modello) {
        if ((string) $colonne($modello)['supplier_sku']->getSchema('length') !== '100') {
            return false;
        }
    }

    return true;
});

check('il costo ha quattro decimali e può restare vuoto, su tutte e due', function () use ($modelli, $colonne, $campo) {
    foreach ($modelli as $modello) {
        $costo = $colonne($modello)['cost'];

        // Vuoto vuol dire «non lo so»: uno zero farebbe del fornitore il più
        // conveniente e abbasserebbe il valore del magazzino.
        if ($costo->getSchema('type') !== 'DECIMAL'
            || $costo->getSchema('length') !== '12,4'
            || $costo->getSchema('null') === false
            || (int) ($campo('cost', $modello)?->getSchema('decimals') ?? 0) !== 4) {
            return false;
        }
    }

    return true;
});

check('articolo, opzione e fornitore hanno il loro indice, e nessun indice unico', function () {
    // Il repeater prima scrive e poi toglie: uno scambio di righe farebbe
    // inciampare un indice unico a metà salvataggio. I doppioni li rifiuta
    // la scheda.
    foreach ([ProductModelSupplier::tablePseudos(), ProductSupplier::tablePseudos()] as $pseudo) {
        foreach ($pseudo as $definizione) {
            if (isset($definizione['unique'])) {
                return false;
            }
        }
    }

    return (ProductModelSupplier::tablePseudos()['ind_model']['index'] ?? null) === 'product_model_id'
        && (ProductModelSupplier::tablePseudos()['ind_supplier']['index'] ?? null) === 'supplier_id'
        && (ProductSupplier::tablePseudos()['ind_product']['index'] ?? null) === 'product_id'
        && (ProductSupplier::tablePseudos()['ind_supplier']['index'] ?? null) === 'supplier_id';
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
