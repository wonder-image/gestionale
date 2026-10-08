<?php
/** php tests/ProductImagesTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Catalog\ProductImage;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductImages;

$colonne = static function (string $model): array {
    $colonne = [];

    foreach ($model::tableSchema() as $column) {
        $colonne[(string) $column->name] = $column;
    }

    return $colonne;
};

$immagini = [
    ['id' => 1, 'product_variant_id' => 0, 'file' => '["modello-1.jpg"]', 'position' => 2, 'status' => 'ready'],
    ['id' => 2, 'product_variant_id' => 0, 'file' => '["modello-2.jpg"]', 'position' => 1, 'status' => 'ready'],
    ['id' => 3, 'product_variant_id' => 7, 'file' => '["blu.jpg"]', 'position' => 1, 'status' => 'pending'],
];

check('la tabella delle immagini ha il prefisso del gestionale', fn () =>
    ProductImage::$table === 'gst_product_images' && ProductImage::syncSchema() === null
);

check('un\'immagine appartiene a un modello e, se serve, a una variante', function () use ($colonne) {
    $righe = $colonne(ProductImage::class);

    return $righe['product_model_id']->getSchema('foreign_table') === ProductModel::$table
        && $righe['product_variant_id']->getSchema('foreign_table') === ProductVariant::$table
        // Senza variante l'immagine vale per tutte: la colonna resta vuota.
        && $righe['product_variant_id']->getSchema('null') !== false;
});

check('lo stato nasce in attesa', function () use ($colonne) {
    $stato = $colonne(ProductImage::class)['status'];

    return $stato->getSchema('enum') === ['pending', 'ready', 'failed']
        && $stato->getSchema('default') === 'pending';
});

check('i tentativi si contano', function () use ($colonne) {
    return isset($colonne(ProductImage::class)['attempts']);
});

check('una variante con le sue immagini usa quelle', function () use ($immagini) {
    $sue = ProductImages::for($immagini, 7);

    return count($sue) === 1 && (int) $sue[0]['id'] === 3;
});

check('una variante senza immagini prende quelle del modello', function () use ($immagini) {
    $sue = ProductImages::for($immagini, 99);

    return count($sue) === 2 && array_column($sue, 'id') === [2, 1];
});

check('senza variante si vedono solo quelle del modello', function () use ($immagini) {
    return array_column(ProductImages::for($immagini, null), 'id') === [2, 1];
});

check('il nome del file si legge dal JSON dell\'upload', fn () =>
    ProductImages::fileName(['file' => '["foto.jpg"]']) === 'foto.jpg'
    && ProductImages::fileName(['file' => 'foto.jpg']) === 'foto.jpg'
    && ProductImages::fileName(['file' => '[]']) === ''
    && ProductImages::fileName([]) === ''
);

check('il percorso mette insieme radice, cartelle e nome', function () {
    $percorso = ProductImages::path(['file' => '["foto.jpg"]'], '/tmp/sito');

    // Scrittura e lettura devono usare la stessa cartella, altrimenti
    // l'anteprima di una foto già caricata non si vede.
    return $percorso === '/tmp/sito/assets/upload/app/gestionale/prodotti/foto.jpg'
        && ProductImages::folder() === ProductImage::$folder;
});

check('senza file non c\'è nessun percorso', fn () =>
    ProductImages::path(['file' => '[]'], '/tmp/sito') === ''
);

check('l\'indirizzo pubblico è quello della cartella degli upload', fn () =>
    ProductImages::url(['file' => '["foto.jpg"]'])
        === '/assets/upload/app/gestionale/prodotti/foto.jpg'
);

check('le foto non si fanno ridimensionare al salvataggio', function () {
    // Con il core che conosce `deferResize()` il campo non chiede misure.
    foreach (ProductImage::dataSchema() as $field) {
        if ((string) $field->key === 'file') {
            return !method_exists($field, 'deferResize')
                || ($field->getSchema('resize_deferred') ?? false) === true;
        }
    }

    return false;
});

check('la foto di un\'opzione vince su quella del suo colore', function () {
    $images = [
        ['id' => 1, 'product_variant_id' => 0, 'product_id' => 0, 'position' => 1],
        ['id' => 2, 'product_variant_id' => 7, 'product_id' => 0, 'position' => 1],
        ['id' => 3, 'product_variant_id' => 7, 'product_id' => 42, 'position' => 1],
    ];

    return array_column(ProductImages::for($images, 7, 42), 'id') === [3];
});

check('senza foto sue, l\'opzione mostra quelle del colore', function () {
    $images = [
        ['id' => 1, 'product_variant_id' => 0, 'product_id' => 0, 'position' => 1],
        ['id' => 2, 'product_variant_id' => 7, 'product_id' => 0, 'position' => 1],
        ['id' => 3, 'product_variant_id' => 7, 'product_id' => 99, 'position' => 1],
    ];

    return array_column(ProductImages::for($images, 7, 42), 'id') === [2];
});

check('la foto di un\'opzione non vale per il colore né per l\'articolo', function () {
    $images = [
        ['id' => 1, 'product_variant_id' => 0, 'product_id' => 0, 'position' => 1],
        ['id' => 3, 'product_variant_id' => 7, 'product_id' => 42, 'position' => 1],
    ];

    return array_column(ProductImages::for($images, 7), 'id') === [1]
        && array_column(ProductImages::for($images, null), 'id') === [1];
});

check('il nome del file dice di chi è la foto: modello, variante, opzione', function () {
    return ProductImages::filePrefix('maglietta-girocollo') === 'maglietta-girocollo-'
        && ProductImages::filePrefix('maglietta-girocollo', 'blu') === 'maglietta-girocollo-blu-'
        && ProductImages::filePrefix('maglietta-girocollo', 'blu', 'Blu / S / Cotone') === 'maglietta-girocollo-blu-s-cotone-'
        // Un nome scritto a mano non comincia col colore: resta intero.
        && ProductImages::filePrefix('maglietta-girocollo', 'blu', 'Maglia leggera') === 'maglietta-girocollo-blu-maglia-leggera-'
        // Lo scheletro non ha slug: la foto si chiama come quelle del modello.
        && ProductImages::filePrefix('maglietta-girocollo', '', '') === 'maglietta-girocollo-'
        && ProductImages::filePrefix('') === '';
});

check('il campo e la scheda usano il prefisso nel nome del file', function () {
    $root = dirname(__DIR__);
    $model = (string) file_get_contents($root.'/src/Models/Catalog/ProductImage.php');
    $resource = (string) file_get_contents($root.'/src/Resources/Catalog/ProductModelResource.php');

    return str_contains($model, "->name('{file_prefix}{rand}')")
        && str_contains($resource, "'file_prefix' => ProductImages::filePrefix(");
});

summary();
