<?php
/** php tests/ProductExportTest.php — D64, l'elenco dei prodotti da scaricare */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductExport;

$dati = static fn (array $extra = []): array => $extra + [
    'models' => [
        ['id' => 1, 'type' => 'simple', 'sku' => 'MAG-1', 'name' => 'Maglia &amp; co', 'slug' => 'maglia', 'brand_id' => 7, 'visible' => 'true', 'visible_online' => 'false', 'weight' => '0.400', 'unit' => 'pz',
            'short_description' => 'Corta', 'description' => '&lt;p&gt;Cotone&lt;/p&gt;&lt;ul&gt;&lt;li&gt;Uno&lt;/li&gt;&lt;li&gt;Due&lt;/li&gt;&lt;/ul&gt;'],
        ['id' => 2, 'type' => 'bundle', 'sku' => 'KIT-1', 'name' => 'Kit', 'slug' => 'kit', 'brand_id' => 0, 'visible' => 'false', 'visible_online' => 'true', 'weight' => '', 'unit' => 'pz', 'short_description' => '', 'description' => ''],
    ],
    'products' => [
        ['id' => 11, 'product_model_id' => 1, 'product_variant_id' => 21, 'name' => 'Blu / S', 'sku' => 'MAG-1-BLU-S', 'ean' => '0012345678905', 'mpn' => '=SOMMA(A1)', 'price' => '29.90', 'sale_price' => '19.90', 'weight' => '', 'allow_backorder' => 'true', 'active' => 'true', 'position' => 2],
        ['id' => 12, 'product_model_id' => 1, 'product_variant_id' => 22, 'name' => 'Rosso / S', 'sku' => 'MAG-1-ROS-S', 'ean' => '', 'mpn' => '', 'price' => '29.90', 'sale_price' => '29.90', 'weight' => '0.5', 'allow_backorder' => 'false', 'active' => 'false', 'position' => 1],
        ['id' => 13, 'product_model_id' => 2, 'product_variant_id' => 0, 'name' => 'Kit', 'sku' => 'KIT-1', 'ean' => '2000040003007', 'mpn' => '', 'price' => '119', 'sale_price' => '', 'weight' => '', 'allow_backorder' => 'false', 'active' => 'true', 'position' => 1],
        ['id' => 99, 'product_model_id' => 404, 'product_variant_id' => 0, 'name' => 'Orfano', 'price' => '1'],
    ],
    'variants' => [
        ['id' => 21, 'product_model_id' => 1, 'slug' => 'blu', 'visible' => 'true'],
        ['id' => 22, 'product_model_id' => 1, 'slug' => 'rosso', 'visible' => 'true'],
    ],
    'images' => [
        ['id' => 1, 'product_model_id' => 1, 'product_variant_id' => 0, 'product_id' => 0, 'file' => '["maglia.jpg"]', 'position' => 1],
        ['id' => 2, 'product_model_id' => 1, 'product_variant_id' => 21, 'product_id' => 0, 'file' => '["maglia-blu.jpg"]', 'position' => 1],
    ],
    'categories' => [
        ['id' => 1, 'parent_id' => 0, 'name' => 'Abbigliamento', 'position' => 1],
        ['id' => 2, 'parent_id' => 1, 'name' => 'Maglie', 'position' => 1],
        ['id' => 3, 'parent_id' => 0, 'name' => 'Regali', 'position' => 2],
    ],
    'links' => [
        ['product_model_id' => 1, 'category_id' => 3, 'is_main' => 'false', 'position' => 1],
        ['product_model_id' => 1, 'category_id' => 2, 'is_main' => 'true', 'position' => 2],
    ],
    'brands' => [['id' => 7, 'name' => 'Aurora']],
    'stock' => [11 => 4.0, 12 => 0.0],
    'bundles' => [2 => 1.0e12],
    'backorders' => true,
    'base' => 'https://negozio.test/',
    'url' => 'https://negozio.test/prodotto/{slug}/',
    'variantUrl' => 'https://negozio.test/prodotto/{slug}/{variante}/',
];

$righe = ProductExport::rows($dati());
$perId = array_column($righe, null, 'id');

check('rows: una riga per opzione, nell\'ordine degli articoli e delle posizioni; gli orfani no', fn () =>
    array_column($righe, 'id') === ['12', '11', '13']
);

check('rows: codice articolo, EAN e nomi come li leggono i feed', fn () =>
    $perId['11']['group'] === 'MAG-1' && $perId['11']['ean'] === '0012345678905'
    && $perId['11']['article'] === 'Maglia & co' && $perId['11']['option'] === 'Blu / S'
    && $perId['13']['option'] === '' && $perId['13']['type'] === 'Multiprodotto'
);

check('rows: lo scontato solo quando sconta', fn () =>
    $perId['11']['price'] === 29.9 && $perId['11']['sale_price'] === 19.9
    && $perId['12']['sale_price'] === null && $perId['13']['sale_price'] === null
);

check('rows: categorie col percorso, la principale prima; il marchio', fn () =>
    $perId['11']['categories'] === 'Abbigliamento › Maglie | Regali' && $perId['11']['brand'] === 'Aurora' && $perId['13']['brand'] === ''
);

check('rows: disponibili, illimitato per un multiprodotto senza fine', fn () =>
    $perId['11']['available'] === 4.0 && $perId['12']['available'] === 0.0 && $perId['13']['available'] === null
);

check('rows: vendibile scoperto solo con la funzionalità accesa', fn () =>
    $perId['11']['backorder'] === 'Sì' && $perId['12']['backorder'] === 'No'
    && ProductExport::rows($dati(['backorders' => false]))[1]['backorder'] === 'No'
);

check('rows: stati di articolo e opzione', fn () =>
    $perId['11']['visible'] === 'Sì' && $perId['11']['visible_online'] === 'No' && $perId['12']['active'] === 'No'
    && $perId['13']['visible'] === 'No'
);

check('rows: il peso dell\'opzione, se no quello dell\'articolo', fn () =>
    $perId['12']['weight'] === 0.5 && $perId['11']['weight'] === 0.4 && $perId['13']['weight'] === null
);

check('rows: link con la variante quando ce ne sono due visibili, senza quando no', function () use ($dati, $perId) {
    $una = ProductExport::rows($dati(['variants' => [['id' => 21, 'product_model_id' => 1, 'slug' => 'blu', 'visible' => 'true'], ['id' => 22, 'product_model_id' => 1, 'slug' => 'rosso', 'visible' => 'false']]]));

    return $perId['11']['link'] === 'https://negozio.test/prodotto/maglia/blu/'
        && $perId['13']['link'] === 'https://negozio.test/prodotto/kit/'
        && $una[1]['link'] === 'https://negozio.test/prodotto/maglia/'
        && ProductExport::rows($dati(['url' => '', 'variantUrl' => '']))[0]['link'] === '';
});

check('rows: immagine della variante, se no dell\'articolo, con il dominio', fn () =>
    $perId['11']['image'] === 'https://negozio.test/assets/upload/app/gestionale/prodotti/maglia-blu.jpg'
    && $perId['12']['image'] === 'https://negozio.test/assets/upload/app/gestionale/prodotti/maglia.jpg'
    && $perId['13']['image'] === ''
);

check('rows: la descrizione senza HTML, anche quando è salvata con le entità', fn () =>
    $perId['11']['description'] === 'Cotone Uno Due'
);

check('csv: un .csv standard, virgola e senza BOM, intestazioni con l\'IVA', function () use ($righe) {
    $csv = ProductExport::csv($righe);
    $prima = strtok($csv, "\n");

    return !str_starts_with($csv, "\u{FEFF}") && str_starts_with($prima, 'ID,"Codice articolo",SKU,EAN')
        && str_contains($prima, '"Prezzo (IVA inclusa)"')
        && str_contains(ProductExport::csv([], false), 'Prezzo (IVA esclusa)');
});

check('csv: punto nei decimali, Illimitata, formule disinnescate, EAN intero', function () use ($righe) {
    $linee = array_map(static fn (string $l): array => str_getcsv($l, ',', '"', ''), explode("\n", trim(ProductExport::csv($righe))));
    $intest = array_flip($linee[0]);
    [, $rossa, $blu, $kit] = $linee;

    return $blu[$intest['Prezzo (IVA inclusa)']] === '29.90' && $blu[$intest['Prezzo scontato (IVA inclusa)']] === '19.90'
        && $rossa[$intest['Prezzo scontato (IVA inclusa)']] === '' && $blu[$intest['Peso (kg)']] === '0.4'
        && $kit[$intest['Disponibili']] === 'Illimitata' && $blu[$intest['Disponibili']] === '4'
        && $blu[$intest['MPN']] === "'=SOMMA(A1)" && $blu[$intest['EAN']] === '0012345678905';
});

check('xlsx: i codici restano testo, i prezzi numeri', function () use ($righe) {
    $file = tempnam(sys_get_temp_dir(), 'wi-export');
    file_put_contents($file, ProductExport::xlsx($righe));

    try {
        $foglio = IOFactory::load($file)->getActiveSheet();
    } finally {
        unlink($file);
    }

    $ean = $foglio->getCell('D3');
    $prezzo = $foglio->getCell('K3');

    return $foglio->getCell('A1')->getValue() === 'ID'
        && $ean->getValue() === '0012345678905' && $ean->getDataType() === DataType::TYPE_STRING
        && $prezzo->getDataType() === DataType::TYPE_NUMERIC && (float) $prezzo->getValue() === 29.9
        && $foglio->getCell('E3')->getValue() === '=SOMMA(A1)' && $foglio->getCell('E3')->getDataType() === DataType::TYPE_STRING
        && $foglio->getCell('M4')->getValue() === 'Illimitata';
});

check('fileName: la data nel nome', fn () =>
    ProductExport::fileName('csv', mktime(12, 0, 0, 10, 15, 2026)) === 'prodotti-2026-10-15.csv'
);

summary();
