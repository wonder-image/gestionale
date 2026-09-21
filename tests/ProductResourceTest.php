<?php
/** php tests/ProductResourceTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

$campi = static function (): array {
    $campi = [];

    foreach (ProductResource::formSchema() as $field) {
        $campi[(string) $field->name] = $field;
    }

    return $campi;
};

check('la singola versione non sta nel menu', fn () =>
    ProductResource::$model === Product::class
    && ProductResource::path() === 'app/gestionale/versioni'
    && ProductResource::titleLabel() === 'Versioni in vendita'
    // Ci si arriva dalla scheda del prodotto, non dal menu: un elenco piatto
    // di articoli in vendita avrà senso con le giacenze.
    && (ProductResource::navigationSchema()->toArray()['enabled'] ?? true) === false
);

check('l\'elenco si filtra sull\'articolo', function () {
    $_GET['prodotto'] = '7';
    $condizione = (array) (ProductResource::querySchema()['condition'] ?? []);
    unset($_GET['prodotto']);

    return ($condizione['product_model_id'] ?? null) === 7;
});

check('senza articolo nell\'indirizzo si vede tutto', function () {
    unset($_GET['prodotto']);

    return !isset(ProductResource::querySchema()['condition']['product_model_id']);
});

check('un prodotto non si crea da qui', function () {
    $pagine = (array) (ProductResource::pageSchema()->toArray()['pages'] ?? []);

    return ($pagine['create'] ?? true) === false
        && ($pagine['store'] ?? true) === false
        && ($pagine['delete'] ?? true) === false
        && ($pagine['edit'] ?? false) === true
        && ($pagine['list'] ?? false) === true;
});

check('il magazzino non si vede ancora', function () use ($campi) {
    foreach (['min_stock_quantity', 'allow_backorder', 'backorder_lead_days'] as $chiave) {
        if (isset($campi()[$chiave])) {
            return false;
        }
    }

    return true;
});

check('modello e variante nell\'elenco si leggono, non sono numeri', function () {
    $colonne = [];

    foreach (ProductResource::tableSchema() as $column) {
        $colonne[(string) $column->name] = $column->toArray()['formatter'] ?? null;
    }

    return $colonne['product_model_id'] instanceof Closure
        && $colonne['name'] instanceof Closure;
});

check('un EAN storto si ferma con una frase', function () {
    try {
        ProductResource::mutateRequestValues(['ean' => '12345'], 'update', 'backend', ['id' => 1]);
    } catch (UserError $errore) {
        return str_contains($errore->getMessage(), '8 o 13');
    }

    return false;
});

check('i prezzi arrivano al database con il punto', function () {
    $valori = ProductResource::mutateRequestValues(
        ['price' => '19,90', 'sale_price' => '1.234,50', 'weight' => ''],
        'update',
        'backend',
        ['id' => 1]
    );

    return $valori['price'] === '19.90'
        && $valori['sale_price'] === '1234.50'
        && $valori['weight'] === null;
});

check('i campi degli attributi non finiscono nella query', function () {
    $valori = ProductResource::mutateRequestValues(
        ['sku' => 'X-1', 'attribute_7' => '12'],
        'update',
        'backend',
        ['id' => 1]
    );

    return !isset($valori['attribute_7']) && ($valori['sku'] ?? '') === 'X-1';
});

check('la scheda dice di che articolo si tratta', function () {
    $resource = new class extends ProductResource {
        public static function currentTitle(): string { return parent::currentTitle(); }
    };

    // Senza id nell'indirizzo resta il titolo generico.
    return $resource::currentTitle() === 'Prodotto';
});

summary();
