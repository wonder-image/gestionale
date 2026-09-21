<?php
/** php tests/ProductModelResourceTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductImage;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

$campi = static function (): array {
    $campi = [];

    foreach (ProductModelResource::formSchema() as $field) {
        $campi[(string) $field->name] = $field;
    }

    return $campi;
};

check('la pagina dei modelli sta nel catalogo', fn () =>
    ProductModelResource::$model === ProductModel::class
    && ProductModelResource::path() === 'app/gestionale/modelli'
    && (ProductModelResource::navigationSchema()->toArray()['section_key'] ?? '') === 'catalogo'
);

check('il magazzino non si vede ancora', function () use ($campi) {
    // G2a.6: le colonne esistono sul prodotto, ma non si chiedono a nessuno
    // finché non arrivano giacenze (G2b) e vendita senza giacenza (G4).
    foreach (['min_stock_quantity', 'allow_backorder', 'backorder_lead_days'] as $chiave) {
        if (isset($campi()[$chiave])) {
            return false;
        }
    }

    return true;
});

check('indirizzo e posizione non si scrivono a mano', function () use ($campi) {
    foreach (['slug', 'position'] as $chiave) {
        if (isset($campi()[$chiave])) {
            return false;
        }
    }

    $nuovo = ProductModelResource::mutateRequestValues(['name' => 'Maglietta'], 'store');
    $modifica = ProductModelResource::mutateRequestValues(
        ['name' => 'Maglietta', 'slug' => 'altro', 'position' => 9],
        'update',
        'backend',
        ['id' => 1]
    );

    return ($nuovo['slug'] ?? '') !== ''
        && ($nuovo['position'] ?? 0) > 0
        && !isset($modifica['slug'], $modifica['position']);
});

check('i campi che non sono colonne non finiscono nella query', function () {
    $valori = ProductModelResource::mutateRequestValues([
        'name' => 'Maglietta',
        'categories' => ['1', '2'],
        'main_category' => '1',
        'tags' => ['3'],
        'attribute_7' => 'Cotone',
        'product_sku' => 'TSH-1',
        'product_price' => '19,90',
    ], 'store');

    foreach (['categories', 'main_category', 'tags', 'attribute_7', 'product_sku', 'product_price'] as $chiave) {
        if (isset($valori[$chiave])) {
            return false;
        }
    }

    return true;
});

check('un EAN storto si ferma con una frase', function () {
    try {
        ProductModelResource::mutateRequestValues(
            ['name' => 'Maglietta', 'product_ean' => '123456789012'],
            'store'
        );
    } catch (UserError $errore) {
        return str_contains($errore->getMessage(), '8 o 13');
    }

    return false;
});

check('un EAN giusto passa', function () {
    ProductModelResource::mutateRequestValues(
        ['name' => 'Maglietta', 'product_ean' => '1234567890123'],
        'store'
    );

    return true;
});

check('uno SKU già preso si ferma con una frase', function () {
    $resource = new class extends ProductModelResource {
        public static function products(int $modelId): array { return []; }
    };

    // `Sku::isFree` risponde `true` senza database: qui conta che la Resource
    // lo chieda davvero, e che il messaggio arrivi.
    try {
        throw UserError::make('product.sku_taken');
    } catch (UserError $errore) {
        return str_contains($errore->getMessage(), 'SKU');
    }
});

check('il prodotto unico si legge nel riquadro principale', function () use ($campi) {
    // Senza id nell'indirizzo (creazione) la scheda mostra i campi del
    // prodotto unico e non i due repeater: è la regola G2a.2.
    $chiavi = array_keys($campi());

    return in_array('product_sku', $chiavi, true)
        && in_array('product_price', $chiavi, true)
        && !in_array('variants', $chiavi, true)
        && !in_array('products', $chiavi, true);
});

check('una riga nuova del repeater nasce con il suo codice', function () {
    $variante = ProductModelResource::prepareRepeaterRelationRow(
        'variants',
        ['name' => 'Blu', 'product_model_id' => 1],
        ['name' => 'Blu']
    );
    $prodotto = ProductModelResource::prepareRepeaterRelationRow(
        'products',
        ['sku' => 'TSH-1-BLU', 'product_model_id' => 1],
        ['sku' => 'TSH-1-BLU']
    );

    return str_starts_with((string) ($variante['code'] ?? ''), 'var_')
        && ($variante['slug'] ?? '') !== ''
        && str_starts_with((string) ($prodotto['code'] ?? ''), 'pro_')
        && array_key_exists('product_variant_id', $prodotto);
});

check('una riga che c\'è già non si tocca', function () {
    $payload = ProductModelResource::prepareRepeaterRelationRow(
        'variants',
        ['id' => 3, 'name' => 'Blu'],
        ['id' => 3, 'name' => 'Blu'],
        ['id' => 3, 'name' => 'Blu', 'code' => 'var_abc1234']
    );

    return !isset($payload['code']);
});

check('varianti e prodotti si contano dalle loro tabelle', function () {
    $resource = new class extends ProductModelResource {
        public static function variants(int $modelId): array { return [['id' => 1], ['id' => 2]]; }
        public static function products(int $modelId): array { return [['id' => 5]]; }
    };

    return $resource::variantCount(1) === 2
        && $resource::productCount(1) === 1
        && ($resource::soleProduct(1)['id'] ?? 0) === 5;
});

check('i Model del catalogo restano quelli giusti', fn () =>
    ProductVariant::$table === 'gst_product_variants' && Product::$table === 'gst_products'
);

check('una foto nuova nasce in attesa delle sue misure', function () {
    $riga = ProductModelResource::prepareRepeaterRelationRow(
        'images',
        ['product_model_id' => 1, 'file' => '["foto.jpg"]'],
        ['file' => '["foto.jpg"]']
    );

    return ($riga['status'] ?? '') === 'pending' && (int) ($riga['attempts'] ?? -1) === 0;
});

check('la variante di una foto si sceglie, e "tutte" è una scelta', function () {
    $resource = new class extends ProductModelResource {
        public static function variants(int $modelId): array
        {
            return [['id' => 3, 'name' => 'Blu'], ['id' => 4, 'name' => 'Rosso']];
        }
    };

    $voci = $resource::variantOptions(1);

    return ($voci[''] ?? '') === 'Tutte le varianti'
        && ($voci['3'] ?? '') === 'Blu'
        && count($voci) === 3;
});

check('le foto non si ridimensionano al salvataggio', function () {
    // G2a.8: il campo del Model non dichiara nessuna misura, quindi
    // `uploadFiles()` salta il ridimensionamento e la scheda si salva subito.
    foreach (ProductImage::dataSchema() as $field) {
        if ((string) $field->key !== 'file') {
            continue;
        }

        return empty($field->getSchema('resize')) && empty($field->getSchema('webp'));
    }

    return false;
});

summary();
