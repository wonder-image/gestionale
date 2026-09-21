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

check('il percorso mette insieme radice, cartella e nome', function () {
    $percorso = ProductImages::path(['file' => '["foto.jpg"]'], '/tmp/sito');

    return $percorso === '/tmp/sito/assets/upload'.ProductImages::DIR.'foto.jpg';
});

check('senza file non c\'è nessun percorso', fn () =>
    ProductImages::path(['file' => '[]'], '/tmp/sito') === ''
);

check('l\'indirizzo pubblico è quello della cartella degli upload', fn () =>
    ProductImages::url(['file' => '["foto.jpg"]']) === '/assets/upload'.ProductImages::DIR.'foto.jpg'
);

summary();
