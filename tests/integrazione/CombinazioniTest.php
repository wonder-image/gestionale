<?php
/** php tests/integrazione/CombinazioniTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Attribute;
use Wonder\Plugin\Gestionale\Models\Catalog\AttributeValue;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductImage;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductSupplier;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Stock\StockAlert;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;
use Wonder\Plugin\Gestionale\Seeding\CatalogDemo;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\Combinations;
use Wonder\Plugin\Gestionale\Support\Catalog\Generator;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductAttributes;
use Wonder\Plugin\Gestionale\Support\Catalog\Skeleton;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Purchasing\ProductSuppliers;
use Wonder\Plugin\Gestionale\Support\Stock\Alerts;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

$conta = static function (string $model): int {
    $rows = $model::find(['deleted' => 'false']);

    if (!is_array($rows) || $rows === []) {
        return 0;
    }

    return isset($rows['id']) ? 1 : count(array_filter($rows, 'is_array'));
};

/** Le tabelle che la prova tocca, contate prima e dopo l'annullamento. */
$tabelle = static fn (): array => array_map($conta, [
    ProductModel::class, ProductVariant::class, Product::class, ProductSupplier::class, Contact::class,
]);

$prima = $tabelle();

/** Un attributo con i suoi valori. @return array{id: int, values: list<int>} */
$attributo = static function (string $name, string $level, string $type, array $labels): array {
    $creato = Attribute::create([
        'code' => Code::make(Attribute::class, Codes::ATTRIBUTE),
        'name' => $name,
        'slug' => Slug::make($name.'-'.uniqid()),
        'type' => $type,
        'level' => $level,
        'unit' => '',
        'group_name' => '',
        'is_filterable' => 'true',
        'is_visible' => 'true',
        'position' => 90,
    ]);
    $id = (int) ($creato->insert_id ?? 0);
    $values = [];
    $position = 1;

    foreach ($labels as $label) {
        $valore = AttributeValue::create([
            'attribute_id' => $id,
            'label' => $label,
            'color' => '',
            'position' => $position++,
        ]);
        $values[] = (int) ($valore->insert_id ?? 0);
    }

    return ['id' => $id, 'values' => $values];
};

try {
    Transaction::run(static function () use ($conta, $prima, $attributo): void {
        $colore = $attributo('Prova colore', 'variant', 'color', ['Blu', 'Rosso']);
        $taglia = $attributo('Prova taglia', 'product', 'select', ['S', 'M', 'L']);

        $modello = ProductModel::create([
            'code' => Code::make(ProductModel::class, Codes::MODEL),
            'name' => 'Prova combinazioni',
            'slug' => Slug::make('prova-combinazioni-'.uniqid()),
            'sku' => 'CMB-1',
            'unit' => 'pz',
            'type' => 'simple',
            'visible' => 'true',
            'visible_online' => 'true',
            'position' => 1,
        ]);
        $modelId = (int) ($modello->insert_id ?? 0);
        Skeleton::forModel($modelId, 'Prova combinazioni', 'CMB-1');

        // Un elenco solo: chi compila spunta i valori e basta, il livello lo
        // ritrova `chosenAxes()` leggendo l'attributo.
        $spunte = [
            'option_'.$colore['id'] => array_map('strval', $colore['values']),
            'option_'.$taglia['id'] => array_map('strval', $taglia['values']),
        ];

        // Il catalogo si legge una volta per richiesta, e la lettura è già
        // avvenuta all'avvio del sito: qui gli attributi nascono dopo.
        ProductModelResource::forgetCatalogCache();
        $genera = static function (array $spunte) use ($modelId): void {
            $scelte = ProductModelResource::chosenAxes($spunte);
            Generator::run($modelId, $scelte['variant'], $scelte['axes'], 'CMB-1');
        };
        $genera($spunte);

        check('due colori e tre taglie fanno due varianti', fn () =>
            ProductModelResource::variantCount($modelId) === 2
        );

        check('e sei prodotti', fn () =>
            ProductModelResource::productCount($modelId) === 6
        );

        check('colore e taglia ora sono in uso, un attributo nuovo no', function () use ($colore, $taglia, $attributo) {
            $nuovo = $attributo('Prova materiale', 'model', 'select', ['Cotone']);

            return ProductAttributes::isUsed($colore['id'])
                && ProductAttributes::isUsed($taglia['id'])
                && !ProductAttributes::isUsed($nuovo['id']);
        });

        check('lo scheletro è stato riusato, non lasciato in giro', function () use ($modelId) {
            // Le due varianti sono Blu e Rosso: nessuna porta ancora il nome
            // del modello, perché la prima ha preso il posto dello scheletro.
            $nomi = array_column(ProductModelResource::variants($modelId), 'name');
            sort($nomi);

            return $nomi === ['Blu', 'Rosso'];
        });

        check('ogni prodotto ha lo SKU proposto', function () use ($modelId) {
            $sku = array_column(ProductModelResource::products($modelId), 'sku');
            sort($sku);

            return $sku === [
                'CMB-1-BLU-L', 'CMB-1-BLU-M', 'CMB-1-BLU-S',
                'CMB-1-ROSSO-L', 'CMB-1-ROSSO-M', 'CMB-1-ROSSO-S',
            ];
        });

        check('ogni prodotto ha il nome della sua combinazione', function () use ($modelId) {
            $nomi = array_column(ProductModelResource::products($modelId), 'name');
            sort($nomi);

            return $nomi === [
                'Blu / L', 'Blu / M', 'Blu / S',
                'Rosso / L', 'Rosso / M', 'Rosso / S',
            ];
        });

        check('rifarlo non crea niente', function () use ($modelId, $spunte, $genera) {
            $genera($spunte);

            return ProductModelResource::variantCount($modelId) === 2
                && ProductModelResource::productCount($modelId) === 6;
        });

        check('una taglia in più fa solo i due prodotti che mancano', function () use ($modelId, $spunte, $taglia, $genera) {
            $nuovo = AttributeValue::create([
                'attribute_id' => $taglia['id'],
                'label' => 'XL',
                'color' => '',
                'position' => 4,
            ]);

            $spunte['option_'.$taglia['id']][] = (string) ($nuovo->insert_id ?? 0);
            // La cache degli attributi vale per richiesta: qui si rilegge.
            ProductModelResource::forgetCatalogCache();
            $genera($spunte);

            return ProductModelResource::variantCount($modelId) === 2
                && ProductModelResource::productCount($modelId) === 8;
        });

        check('una versione nasce con quello che si è scritto nella griglia', function () use ($colore, $taglia) {
            $modello = ProductModel::create([
                'code' => Code::make(ProductModel::class, Codes::MODEL),
                'name' => 'Prova griglia',
                'slug' => Slug::make('prova-griglia-'.uniqid()),
                'sku' => 'CMB-2',
                'unit' => 'pz',
                'type' => 'simple',
                'visible' => 'true',
                'visible_online' => 'true',
                'position' => 1,
            ]);
            $nuovo = (int) ($modello->insert_id ?? 0);
            Skeleton::forModel($nuovo, 'Prova griglia', 'CMB-2');

            // La chiave è quella che calcola il browser: tutti gli id dei
            // valori spuntati, in ordine, uniti da un trattino.
            $chiave = Combinations::clientKey($colore['values'][0], [$taglia['values'][0]]);

            ProductModelResource::forgetCatalogCache();
            ProductModelResource::saveExtras($nuovo, [
                // La domanda in cima alla scheda: senza un sì le spunte non
                // si guardano nemmeno.
                'has_variants' => 'true',
                'axes_order' => $colore['id'].'-'.$taglia['id'],
                'option_'.$colore['id'] => [(string) $colore['values'][0]],
                'option_'.$taglia['id'] => [(string) $taglia['values'][0]],
                // Il riquadro in alto dice 9,90: non deve toccare la riga che
                // si è appena prezzata da sé.
                'product_price' => '9,90',
                'products' => [
                    $chiave => [
                        // Il nome lo scrive il sistema: quello che si scrive
                        // qui non arriva nemmeno, perché la casella non c'è.
                        'sku' => 'MIO-1',
                        'ean' => '4006381333931',
                        'price' => '31,50',
                        'stock' => '5',
                        'cost' => '12,00',
                    ],
                ],
            ], 'CMB-2');

            $prodotti = ProductModelResource::products($nuovo);
            $riga = $prodotti[0] ?? [];

            return count($prodotti) === 1
                // Il nome viene dagli attributi, sempre: "Blu / S".
                && (string) ($riga['name'] ?? '') === 'Blu / S'
                && (string) ($riga['sku'] ?? '') === 'MIO-1'
                && (string) ($riga['ean'] ?? '') === '4006381333931'
                && (float) ($riga['price'] ?? 0) === 31.5
                && Levels::of((int) ($riga['id'] ?? 0))['quantity'] === 5.0;
        });

        check('due opzioni con pagina propria vengono rifiutate', function () use ($attributo, $colore) {
            $gusto = $attributo('Prova gusto', 'variant', 'select', ['Fragola']);
            ProductModelResource::forgetCatalogCache();

            try {
                ProductModelResource::chosenAxes([
                    'option_'.$colore['id'] => [(string) $colore['values'][0]],
                    'option_'.$gusto['id'] => [(string) $gusto['values'][0]],
                ]);
            } catch (UserError $errore) {
                return $errore->key() === 'product.one_page_option';
            }

            return false;
        });

        check('le foto della testata vanno al colore giusto', function () use ($colore) {
            $modello = ProductModel::create([
                'code' => Code::make(ProductModel::class, Codes::MODEL),
                'name' => 'Prova foto colore',
                'slug' => Slug::make('prova-foto-colore-'.uniqid()),
                'sku' => 'CMB-3',
                'unit' => 'pz',
                'type' => 'simple',
                'visible' => 'true',
                'visible_online' => 'true',
                'position' => 1,
            ]);
            $nuovo = (int) ($modello->insert_id ?? 0);
            Skeleton::forModel($nuovo, 'Prova foto colore', 'CMB-3');

            $post = [
                'has_variants' => 'true',
                'axes_order' => (string) $colore['id'],
                'option_'.$colore['id'] => array_map('strval', $colore['values']),
                'product_price' => '10,00',
            ];

            ProductModelResource::forgetCatalogCache();
            ProductModelResource::saveExtras($nuovo, $post, 'CMB-3');

            $varianteDi = array_flip(ProductModelResource::variantValues($nuovo));
            $blu = (int) ($varianteDi[$colore['values'][0]] ?? 0);
            $rosso = (int) ($varianteDi[$colore['values'][1]] ?? 0);

            // Le righe si scrivono a mano: il salvataggio le deve solo
            // riordinare o togliere, e i nomi bastano a riconoscerle.
            foreach ([[$blu, 'blu-a.jpg', 1], [$blu, 'blu-b.jpg', 2], [$rosso, 'rosso-a.jpg', 1]] as [$variante, $file, $posizione]) {
                ProductImage::create([
                    'product_model_id' => $nuovo,
                    'product_variant_id' => $variante,
                    'file' => json_encode([$file]),
                    'alt' => '',
                    'position' => $posizione,
                    'status' => 'pending',
                    'attempts' => 0,
                ]);
            }

            ProductModelResource::saveExtras($nuovo, $post + [
                'group_images' => [
                    // Il blu tiene la seconda foto, che diventa la prima.
                    $colore['values'][0].'__wi_files' => json_encode(['blu-b.jpg']),
                    // Un valore senza variante non tocca niente.
                    '999999__wi_files' => json_encode([]),
                ],
            ], 'CMB-3');

            return $blu > 0 && $rosso > 0
                && ProductModelResource::imageNames($nuovo, $blu) === ['blu-b.jpg']
                // Il rosso non è arrivato dalla testata: le sue foto restano.
                && ProductModelResource::imageNames($nuovo, $rosso) === ['rosso-a.jpg']
                && ProductModelResource::colorPhotosInGroups($nuovo);
        });

        check('la scorta minima scritta nella griglia si salva, alla nascita e dopo', function () use ($colore, $taglia) {
            // Solo per questa prova: le altre funzionalità restano come le
            // ha il sito.
            Gestionale::feature('low_stock_alerts');
            $stato = new ReflectionProperty(Gestionale::class, 'features');
            $prima = $stato->getValue();
            $stato->setValue(null, array_merge((array) $prima, ['low_stock_alerts' => true]));

            try {
                $modello = ProductModel::create([
                    'code' => Code::make(ProductModel::class, Codes::MODEL),
                    'name' => 'Prova scorta griglia',
                    'slug' => Slug::make('prova-scorta-griglia-'.uniqid()),
                    'sku' => 'CMB-4',
                    'unit' => 'pz',
                    'type' => 'simple',
                    'visible' => 'true',
                    'visible_online' => 'true',
                    'position' => 1,
                ]);
                $nuovo = (int) ($modello->insert_id ?? 0);
                Skeleton::forModel($nuovo, 'Prova scorta griglia', 'CMB-4');
                $spunte = [
                    'has_variants' => 'true',
                    'axes_order' => $colore['id'].'-'.$taglia['id'],
                    'option_'.$colore['id'] => [(string) $colore['values'][0]],
                    'option_'.$taglia['id'] => [(string) $taglia['values'][0]],
                ];
                $chiave = Combinations::clientKey($colore['values'][0], [$taglia['values'][0]]);

                ProductModelResource::forgetCatalogCache();
                // Nasce con 2 pezzi e la soglia a 5: l'avviso c'è subito.
                ProductModelResource::saveExtras($nuovo, $spunte + [
                    'products' => [$chiave => ['price' => '10,00', 'stock' => '2', 'min_stock' => '5']],
                ], 'CMB-4');

                $prodotto = ProductModelResource::products($nuovo)[0] ?? [];
                $productId = (int) ($prodotto['id'] ?? 0);
                $allaNascita = (float) ($prodotto['min_stock_quantity'] ?? 0) === 5.0
                    && Alerts::openRow($productId) !== [];

                // Poi la si abbassa dalla riga, che adesso ha il suo id.
                ProductModelResource::saveExtras($nuovo, $spunte + [
                    'products' => ['0' => ['id' => (string) $productId, 'stock' => '2', 'min_stock' => '1']],
                ], 'CMB-4');

                $dopo = (float) (Product::findById($productId)['min_stock_quantity'] ?? 0) === 1.0
                    && Alerts::openRow($productId) === [];

                return $productId > 0 && $allaNascita && $dopo;
            } finally {
                $stato->setValue(null, $prima);
            }
        });

        /** Esegue la prova con gli avvisi di scorta minima accesi. */
        $conAvvisi = static function (callable $prova): mixed {
            Gestionale::feature('low_stock_alerts');
            $stato = new ReflectionProperty(Gestionale::class, 'features');
            $prima = $stato->getValue();
            $stato->setValue(null, array_merge((array) $prima, ['low_stock_alerts' => true]));

            try {
                return $prova();
            } finally {
                $stato->setValue(null, $prima);
            }
        };

        $articolo = static function (string $sku): int {
            $modello = ProductModel::create([
                'code' => Code::make(ProductModel::class, Codes::MODEL),
                'name' => 'Prova '.$sku,
                'slug' => Slug::make('prova-'.strtolower($sku).'-'.uniqid()),
                'sku' => $sku,
                'unit' => 'pz',
                'type' => 'simple',
                'visible' => 'true',
                'visible_online' => 'true',
                'position' => 1,
            ]);
            $nuovo = (int) ($modello->insert_id ?? 0);
            Skeleton::forModel($nuovo, 'Prova '.$sku, $sku);

            return $nuovo;
        };

        /** Tutte le righe d'avviso di un prodotto, anche quelle già chiuse. */
        $avvisi = static function (int $productId): int {
            $rows = StockAlert::find('product_id = '.$productId." AND deleted = 'false'");

            if (!is_array($rows) || $rows === []) {
                return 0;
            }

            return isset($rows['id']) ? 1 : count(array_filter($rows, 'is_array'));
        };

        check('accendendo le varianti la prima combinazione tiene scorta minima e giacenza della sua riga', function () use ($conAvvisi, $articolo, $colore, $taglia) {
            return $conAvvisi(static function () use ($articolo, $colore, $taglia): bool {
                $nuovo = $articolo('CMB-5');
                ProductModelResource::forgetCatalogCache();
                // Prima un articolo senza varianti: 5 pezzi, soglia 5.
                ProductModelResource::saveExtras($nuovo, [
                    'has_variants' => 'false',
                    'product_stock' => '5',
                    'product_min_stock' => '5',
                ], 'CMB-5');
                $scheletro = (int) (ProductModelResource::products($nuovo)[0]['id'] ?? 0);

                // Poi si accendono le varianti. La riga dello scheletro c'è
                // ancora nella griglia, con i suoi numeri; la combinazione
                // nuova ne ha altri, e sono quelli che valgono.
                $chiave = Combinations::clientKey($colore['values'][0], [$taglia['values'][0]]);
                ProductModelResource::saveExtras($nuovo, [
                    'has_variants' => 'true',
                    'axes_order' => $colore['id'].'-'.$taglia['id'],
                    'option_'.$colore['id'] => [(string) $colore['values'][0]],
                    'option_'.$taglia['id'] => [(string) $taglia['values'][0]],
                    'products' => [
                        '0' => ['id' => (string) $scheletro, 'stock' => '5', 'min_stock' => '5'],
                        $chiave => ['price' => '10,00', 'stock' => '3', 'min_stock' => '3'],
                    ],
                ], 'CMB-5');

                $prodotti = ProductModelResource::products($nuovo);
                $riga = $prodotti[0] ?? [];

                return $scheletro > 0
                    && count($prodotti) === 1
                    && (int) ($riga['id'] ?? 0) === $scheletro
                    && (float) ($riga['min_stock_quantity'] ?? 0) === 3.0
                    && Levels::of($scheletro)['quantity'] === 3.0;
            });
        });

        check('giacenza e soglia cambiate insieme: l\'avviso aperto resta quello e non ne nascono di finti', function () use ($conAvvisi, $articolo, $avvisi, $colore) {
            return $conAvvisi(static function () use ($articolo, $avvisi, $colore): bool {
                // Nella griglia: nasce con 3 pezzi e soglia 5, avviso aperto.
                $griglia = $articolo('CMB-6');
                $spunte = [
                    'has_variants' => 'true',
                    'axes_order' => (string) $colore['id'],
                    'option_'.$colore['id'] => [(string) $colore['values'][0]],
                ];
                ProductModelResource::forgetCatalogCache();
                ProductModelResource::saveExtras($griglia, $spunte + [
                    'products' => [(string) $colore['values'][0] => ['price' => '10,00', 'stock' => '3', 'min_stock' => '5']],
                ], 'CMB-6');
                $variante = (int) (ProductModelResource::products($griglia)[0]['id'] ?? 0);
                $aperto = (int) (Alerts::openRow($variante)['id'] ?? 0);

                // Salgono tutti e due, la soglia resta sopra: stesso avviso.
                ProductModelResource::saveExtras($griglia, $spunte + [
                    'products' => ['0' => ['id' => (string) $variante, 'stock' => '10', 'min_stock' => '12']],
                ], 'CMB-6');
                $grigliaTiene = $aperto > 0 && (int) (Alerts::openRow($variante)['id'] ?? 0) === $aperto;

                // Poi 20 pezzi e soglia 15, e giù a 3 pezzi con soglia 2: dopo
                // la chiusura nessuna riga nuova, nemmeno chiusa subito.
                ProductModelResource::saveExtras($griglia, $spunte + [
                    'products' => ['0' => ['id' => (string) $variante, 'stock' => '20', 'min_stock' => '15']],
                ], 'CMB-6');
                $righe = $avvisi($variante);
                ProductModelResource::saveExtras($griglia, $spunte + [
                    'products' => ['0' => ['id' => (string) $variante, 'stock' => '3', 'min_stock' => '2']],
                ], 'CMB-6');
                $grigliaPulita = $avvisi($variante) === $righe && Alerts::openRow($variante) === [];

                // Senza varianti: stessa regola per le due caselle in alto.
                $singolo = $articolo('CMB-7');
                ProductModelResource::saveExtras($singolo, [
                    'has_variants' => 'false',
                    'product_stock' => '3',
                    'product_min_stock' => '5',
                ], 'CMB-7', [], true);
                $prodotto = (int) (ProductModelResource::products($singolo)[0]['id'] ?? 0);
                $apertoSingolo = (int) (Alerts::openRow($prodotto)['id'] ?? 0);
                ProductModelResource::saveExtras($singolo, [
                    'has_variants' => 'false',
                    'product_stock' => '10',
                    'product_min_stock' => '12',
                ], 'CMB-7');
                $singoloTiene = $apertoSingolo > 0 && (int) (Alerts::openRow($prodotto)['id'] ?? 0) === $apertoSingolo;

                return $grigliaTiene && $grigliaPulita && $singoloTiene;
            });
        });

        /** Le causali dei movimenti di un prodotto, in ordine. */
        $causali = static function (int $productId): array {
            $rows = StockMovement::find('product_id = '.$productId." AND deleted = 'false'", null, 'id ASC');

            if (!is_array($rows) || $rows === []) {
                return [];
            }

            $rows = isset($rows['id']) ? [$rows] : array_filter($rows, 'is_array');

            return array_values(array_map(static fn (array $row): string => (string) $row['reason'], $rows));
        };

        check('un articolo che nasce con le varianti carica i pezzi di ogni combinazione, anche della prima', function () use ($articolo, $causali, $colore) {
            // Come `afterStore()`: lo scheletro nasce nella stessa richiesta,
            // e il generatore lo riprende per la prima combinazione.
            $nuovo = $articolo('CMB-8');
            $valori = array_slice($colore['values'], 0, 2);
            ProductModelResource::forgetCatalogCache();
            ProductModelResource::saveExtras($nuovo, [
                'has_variants' => 'true',
                'axes_order' => (string) $colore['id'],
                'option_'.$colore['id'] => array_map('strval', $valori),
                'products' => [
                    (string) $valori[0] => ['price' => '10,00', 'stock' => '10'],
                    (string) $valori[1] => ['price' => '10,00', 'stock' => '5'],
                ],
            ], 'CMB-8', [], true);

            $quante = [];

            foreach (ProductModelResource::products($nuovo) as $product) {
                $id = (int) $product['id'];
                $quante[] = [Levels::of($id)['quantity'], $causali($id)];
            }

            usort($quante, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

            return $quante === [[10.0, ['initial_stock']], [5.0, ['initial_stock']]];
        });

        check('accendendo le varianti, dove la riga nuova è vuota vale quella dello scheletro', function () use ($conAvvisi, $articolo, $colore) {
            return $conAvvisi(static function () use ($articolo, $colore): bool {
                $nuovo = $articolo('CMB-9');
                ProductModelResource::forgetCatalogCache();
                ProductModelResource::saveExtras($nuovo, [
                    'has_variants' => 'false',
                    'product_stock' => '5',
                    'product_min_stock' => '5',
                ], 'CMB-9');
                $scheletro = (int) (ProductModelResource::products($nuovo)[0]['id'] ?? 0);

                // La riga nuova nasce dal modello della griglia con le caselle
                // vuote; nella vecchia si è corretto qualcosa.
                ProductModelResource::saveExtras($nuovo, [
                    'has_variants' => 'true',
                    'axes_order' => (string) $colore['id'],
                    'option_'.$colore['id'] => [(string) $colore['values'][0]],
                    'products' => [
                        '0' => ['id' => (string) $scheletro, 'stock' => '7', 'min_stock' => '6'],
                        (string) $colore['values'][0] => ['price' => '10,00', 'stock' => '', 'min_stock' => ''],
                    ],
                ], 'CMB-9');

                $riga = ProductModelResource::products($nuovo)[0] ?? [];

                return (int) ($riga['id'] ?? 0) === $scheletro
                    && (float) ($riga['min_stock_quantity'] ?? 0) === 6.0
                    && Levels::of($scheletro)['quantity'] === 7.0;
            });
        });

        check('eliminate le righe fino a una, la griglia vale ancora: l\'interruttore spento non la butta', function () use ($conAvvisi, $articolo) {
            return $conAvvisi(static function () use ($articolo): bool {
                $nuovo = $articolo('CMB-10');
                // `mutateRequestValues()` ha già scritto «sì» sul modello: le
                // righe erano più d'una quando è partito il salvataggio.
                ProductModel::update(['has_variants' => 'true'], $nuovo);
                $prodotto = (int) (ProductModelResource::products($nuovo)[0]['id'] ?? 0);

                // L'interruttore disabilitato manda «no», e le caselle in alto,
                // nascoste, arrivano vuote.
                ProductModelResource::saveExtras($nuovo, [
                    'has_variants' => 'false',
                    'product_stock' => '',
                    'product_min_stock' => '',
                    'products' => ['0' => ['id' => (string) $prodotto, 'stock' => '4', 'min_stock' => '8']],
                ], 'CMB-10');

                $riga = ProductModelResource::products($nuovo)[0] ?? [];

                return (float) ($riga['min_stock_quantity'] ?? 0) === 8.0
                    && Levels::of($prodotto)['quantity'] === 4.0;
            });
        });

        /** Esegue la prova con gli acquisti accesi, o spenti. */
        $conAcquisti = static function (callable $prova, bool $acceso = true): mixed {
            Gestionale::feature('purchasing');
            $stato = new ReflectionProperty(Gestionale::class, 'features');
            $prima = $stato->getValue();
            $stato->setValue(null, array_merge((array) $prima, ['purchasing' => $acceso]));
            // I fornitori che la scheda propone si leggono una volta per
            // richiesta, e qui nascono durante la prova.
            ProductModelResource::forgetCatalogCache();

            try {
                return $prova();
            } finally {
                $stato->setValue(null, $prima);
                ProductModelResource::forgetCatalogCache();
            }
        };

        $fornitore = static function (string $nome, bool $attivo = true): int {
            $creato = Contact::create([
                'type' => 'business',
                'business_name' => $nome,
                'country' => 'IT',
                'is_customer' => 'false',
                'is_supplier' => 'true',
                'active' => $attivo ? 'true' : 'false',
            ]);

            return (int) ($creato->insert_id ?? 0);
        };

        /** I legami di un'opzione, in ordine: fornitore, codice, costo, preferito. */
        $legami = static function (int $productId): array {
            $rows = ProductSupplier::find(['product_id' => $productId, 'deleted' => ['true', 'false']], null, 'position', 'ASC');
            $rows = isset($rows['id']) ? [$rows] : array_values(array_filter((array) $rows, 'is_array'));

            return array_map(static fn (array $row): array => [
                (int) $row['supplier_id'],
                (string) $row['supplier_sku'],
                $row['cost'] === null ? null : round((float) $row['cost'], 4),
                (string) $row['is_preferred'],
            ], $rows);
        };

        /** Il JSON che la finestra «Costo» scrive nel campo nascosto. */
        $finestra = static fn (array $righe): string => json_encode($righe, JSON_THROW_ON_ERROR);

        // Con un fornitore solo da proporre la scheda ha le tre caselle: la
        // prova sceglie chi c'è, senza guardare i fornitori del sito.
        $unFornitore = new class extends ProductModelResource {
            /** @var array<int, string> */
            public static array $scelte = [];

            protected static function supplierChoices(int $modelId): array
            {
                return static::$scelte;
            }
        };

        $nord = $fornitore('Prova Filati Nord');
        $sud = $fornitore('Prova Lanificio Sud');
        $unFornitore::$scelte = [$nord => 'Prova Filati Nord'];

        check('con un fornitore solo, fornitore, codice e costo dell\'articolo si salvano e si rileggono', function () use ($conAcquisti, $articolo, $unFornitore, $nord, $legami) {
            return $conAcquisti(static function () use ($articolo, $unFornitore, $nord, $legami): bool {
                $nuovo = $articolo('CMB-11');
                $post = [
                    'has_variants' => 'false',
                    'product_supplier_id' => (string) $nord,
                    'product_supplier_sku' => 'FN-1',
                    'product_cost' => '12,50',
                ];
                $unFornitore::saveExtras($nuovo, $post, 'CMB-11');
                $prodotto = (int) ($unFornitore::products($nuovo)[0]['id'] ?? 0);
                $salvati = $legami($prodotto);
                $valori = $unFornitore::mutateFormValues(['id' => $nuovo], 'edit');

                // Il secondo salvataggio aggiorna il legame, non ne crea un altro.
                $unFornitore::saveExtras($nuovo, ['product_cost' => '13,00'] + $post, 'CMB-11');

                return $prodotto > 0
                    && $salvati === [[$nord, 'FN-1', 12.5, 'true']]
                    && ($valori['product_supplier_id'] ?? null) === (string) $nord
                    && ($valori['product_supplier_sku'] ?? null) === 'FN-1'
                    // Grezzo col punto: le cifre e la valuta le mette la casella.
                    && ($valori['product_cost'] ?? null) === '12.50'
                    && $legami($prodotto) === [[$nord, 'FN-1', 13.0, 'true']];
            });
        });

        check('con un fornitore solo, svuotare il fornitore lo stacca anche con codice e costo ancora scritti', function () use ($conAcquisti, $articolo, $unFornitore, $nord, $legami) {
            return $conAcquisti(static function () use ($articolo, $unFornitore, $nord, $legami): bool {
                $nuovo = $articolo('CMB-12');
                $post = [
                    'has_variants' => 'false',
                    'product_supplier_id' => (string) $nord,
                    'product_supplier_sku' => 'FN-2',
                    'product_cost' => '5,00',
                ];
                $unFornitore::saveExtras($nuovo, $post, 'CMB-12');
                $prodotto = (int) ($unFornitore::products($nuovo)[0]['id'] ?? 0);
                $legato = count($legami($prodotto)) === 1;

                $unFornitore::saveExtras($nuovo, ['product_supplier_id' => ''] + $post, 'CMB-12');
                $valori = $unFornitore::mutateFormValues(['id' => $nuovo], 'edit');

                return $legato
                    && $legami($prodotto) === []
                    && ($valori['product_supplier_id'] ?? null) === ''
                    && ($valori['product_supplier_sku'] ?? null) === ''
                    && ($valori['product_cost'] ?? null) === '';
            });
        });

        check('un costo con quattro decimali resta com\'è se la casella dice lo stesso numero', function () use ($conAcquisti, $articolo, $unFornitore, $nord, $legami) {
            return $conAcquisti(static function () use ($articolo, $unFornitore, $nord, $legami): bool {
                $nuovo = $articolo('CMB-13');
                $prodotto = (int) ($unFornitore::products($nuovo)[0]['id'] ?? 0);
                ProductSuppliers::sync($prodotto, [
                    ['supplier_id' => $nord, 'supplier_sku' => 'FN-3', 'cost' => 12.3456, 'is_preferred' => 'true'],
                ]);
                $valori = $unFornitore::mutateFormValues(['id' => $nuovo], 'edit');
                $post = [
                    'has_variants' => 'false',
                    'product_supplier_id' => (string) $nord,
                    'product_supplier_sku' => 'FN-3',
                    'product_cost' => '12,35',
                ];
                $unFornitore::saveExtras($nuovo, $post, 'CMB-13');
                $tenuto = $legami($prodotto);
                $unFornitore::saveExtras($nuovo, ['product_cost' => '12,40'] + $post, 'CMB-13');

                return ($valori['product_cost'] ?? null) === '12.35'
                    && $tenuto === [[$nord, 'FN-3', 12.3456, 'true']]
                    && $legami($prodotto) === [[$nord, 'FN-3', 12.4, 'true']];
            });
        });

        check('con un fornitore solo, le righe della griglia salvano il loro fornitore, nuove o già salvate', function () use ($conAcquisti, $articolo, $unFornitore, $nord, $legami, $colore) {
            return $conAcquisti(static function () use ($articolo, $unFornitore, $nord, $legami, $colore): bool {
                $nuovo = $articolo('CMB-14');
                $spunte = [
                    'has_variants' => 'true',
                    'axes_order' => (string) $colore['id'],
                    'option_'.$colore['id'] => array_map('strval', array_slice($colore['values'], 0, 2)),
                ];

                // Due righe nuove: una con il fornitore, una lasciata vuota.
                $unFornitore::saveExtras($nuovo, $spunte + ['products' => [
                    (string) $colore['values'][0] => ['price' => '10,00', 'supplier_id' => (string) $nord, 'supplier_sku' => 'G-1', 'cost' => '4,50'],
                    (string) $colore['values'][1] => ['price' => '10,00', 'supplier_id' => '', 'supplier_sku' => '', 'cost' => ''],
                ]], 'CMB-14');

                $ids = [];

                foreach ($unFornitore::products($nuovo) as $product) {
                    $ids[(string) ($product['name'] ?? '')] = (int) $product['id'];
                }

                $blu = $ids['Blu'] ?? 0;
                $rosso = $ids['Rosso'] ?? 0;
                $allaNascita = $legami($blu) === [[$nord, 'G-1', 4.5, 'true']] && $legami($rosso) === [];

                // Poi dalle righe salvate: il blu cambia costo, il rosso si lega.
                $unFornitore::saveExtras($nuovo, $spunte + ['products' => [
                    '0' => ['id' => (string) $blu, 'supplier_id' => (string) $nord, 'supplier_sku' => 'G-1', 'cost' => '4,00'],
                    '1' => ['id' => (string) $rosso, 'supplier_id' => (string) $nord, 'supplier_sku' => 'G-2', 'cost' => ''],
                ]], 'CMB-14');

                return $blu > 0 && $rosso > 0
                    && $allaNascita
                    && $legami($blu) === [[$nord, 'G-1', 4.0, 'true']]
                    && $legami($rosso) === [[$nord, 'G-2', null, 'true']];
            });
        });

        check('con più fornitori la finestra scrive i legami della riga, nuova o già salvata', function () use ($conAcquisti, $articolo, $colore, $nord, $sud, $legami, $finestra) {
            return $conAcquisti(static function () use ($articolo, $colore, $nord, $sud, $legami, $finestra): bool {
                $nuovo = $articolo('CMB-15');
                $spunte = [
                    'has_variants' => 'true',
                    'axes_order' => (string) $colore['id'],
                    'option_'.$colore['id'] => [(string) $colore['values'][0]],
                ];

                // La riga nasce con due fornitori; nessuno è segnato, e il
                // preferito diventa il primo.
                ProductModelResource::saveExtras($nuovo, $spunte + ['products' => [
                    (string) $colore['values'][0] => ['price' => '10,00', 'suppliers' => $finestra([
                        ['supplier_id' => $nord, 'supplier_sku' => 'N-1', 'cost' => '10.5', 'is_preferred' => 'false'],
                        ['supplier_id' => $sud, 'supplier_sku' => 'S-1', 'cost' => '', 'is_preferred' => 'false'],
                    ])],
                ]], 'CMB-15');
                $prodotto = (int) (ProductModelResource::products($nuovo)[0]['id'] ?? 0);
                $allaNascita = $legami($prodotto);

                // Dalla riga salvata: il nord se ne va, il sud cambia.
                ProductModelResource::saveExtras($nuovo, $spunte + ['products' => ['0' => [
                    'id' => (string) $prodotto,
                    'suppliers' => $finestra([
                        ['supplier_id' => $sud, 'supplier_sku' => 'S-2', 'cost' => '9', 'is_preferred' => 'true'],
                    ]),
                ]]], 'CMB-15');
                $dopo = $legami($prodotto);

                // Il campo vuoto è una riga che la finestra non ha toccato.
                ProductModelResource::saveExtras($nuovo, $spunte + ['products' => ['0' => [
                    'id' => (string) $prodotto,
                    'suppliers' => '',
                ]]], 'CMB-15');
                $intatti = $legami($prodotto);

                // Una finestra salvata senza nessuno toglie tutti i legami.
                ProductModelResource::saveExtras($nuovo, $spunte + ['products' => ['0' => [
                    'id' => (string) $prodotto,
                    'suppliers' => '[]',
                ]]], 'CMB-15');

                return $allaNascita === [[$nord, 'N-1', 10.5, 'true'], [$sud, 'S-1', null, 'false']]
                    && $dopo === [[$sud, 'S-2', 9.0, 'true']]
                    && $intatti === $dopo
                    && $legami($prodotto) === [];
            });
        });

        check('riaprendo la scheda la riga ha i suoi fornitori e il riassunto accanto al bottone', function () use ($conAcquisti, $articolo, $nord, $sud) {
            return $conAcquisti(static function () use ($articolo, $nord, $sud): bool {
                $nuovo = $articolo('CMB-16');
                $prodotto = (int) (ProductModelResource::products($nuovo)[0]['id'] ?? 0);
                ProductSuppliers::sync($prodotto, [
                    ['supplier_id' => $nord, 'supplier_sku' => 'N-1', 'cost' => 12.3456, 'is_preferred' => 'false'],
                    ['supplier_id' => $sud, 'supplier_sku' => '', 'cost' => '7', 'is_preferred' => 'true'],
                ]);

                $valori = ProductModelResource::mutateFormValues(
                    ['id' => $nuovo, 'products' => [['id' => (string) $prodotto]]],
                    'edit'
                );
                $riga = $valori['products'][0] ?? [];

                // Dopo un salvataggio rifiutato la riga torna con quello
                // scritto, e il riassunto lo segue.
                $rifiutata = ProductModelResource::mutateFormValues(
                    ['id' => $nuovo, 'products' => [['id' => (string) $prodotto, 'suppliers' => '[]']]],
                    'edit'
                );

                return json_decode((string) ($riga['suppliers'] ?? ''), true) === [
                        ['supplier_id' => $nord, 'supplier_sku' => 'N-1', 'cost' => '12.35', 'is_preferred' => 'false'],
                        ['supplier_id' => $sud, 'supplier_sku' => '', 'cost' => '7.00', 'is_preferred' => 'true'],
                    ]
                    && ($riga['cost_button'] ?? null) === 'Prova Lanificio Sud · 7,00 €'
                    // Senza varianti la tendina ha gli stessi dati.
                    && ($valori['product_suppliers'] ?? null) === ($riga['suppliers'] ?? null)
                    && ($valori['product_cost_button'] ?? null) === 'Prova Lanificio Sud · 7,00 €'
                    && ($rifiutata['products'][0]['suppliers'] ?? null) === '[]'
                    && ($rifiutata['products'][0]['cost_button'] ?? null) === 'Nessun fornitore';
            });
        });

        check('un fornitore che la scheda non propone, o uno ripetuto, si ferma prima del salvataggio', function () use ($conAcquisti, $articolo, $nord, $fornitore, $finestra) {
            return $conAcquisti(static function () use ($articolo, $nord, $fornitore, $finestra): array|bool {
                $nuovo = $articolo('CMB-17');
                $fermo = $fornitore('Prova Tintoria Ferma', false);
                $esiti = [];

                foreach ([
                    [['supplier_id' => $fermo, 'supplier_sku' => 'T-1', 'cost' => '1']],
                    [['supplier_id' => $nord, 'cost' => '1'], ['supplier_id' => $nord, 'cost' => '2']],
                ] as $righe) {
                    try {
                        ProductModelResource::assertSupplierCosts($nuovo, ['product_suppliers' => $finestra($righe)], false);
                        $esiti[] = '';
                    } catch (UserError $errore) {
                        $esiti[] = $errore->key();
                    }
                }

                return $esiti === ['product.supplier_invalid', 'product.supplier_duplicate'];
            });
        });

        // Fra l'apertura e il salvataggio un fornitore può diventare attivo, o
        // smettere di esserlo: la pagina posta nella forma che aveva, e quello
        // che si è scritto non deve sparire.
        check('un form nella forma dell\'altro modo si salva lo stesso', function () use ($conAcquisti, $articolo, $colore, $unFornitore, $nord, $legami, $finestra) {
            return $conAcquisti(static function () use ($articolo, $colore, $unFornitore, $nord, $legami, $finestra): bool {
                // Aperta con le tre caselle, salvata con più fornitori.
                $tre = $articolo('CMB-20');
                ProductModelResource::saveExtras($tre, [
                    'has_variants' => 'false',
                    'product_supplier_id' => (string) $nord,
                    'product_supplier_sku' => 'FN-20',
                    'product_cost' => '4,50',
                ], 'CMB-20');
                $prodottoTre = (int) (ProductModelResource::products($tre)[0]['id'] ?? 0);

                // Aperta con la finestra, salvata con un fornitore solo.
                $finestrata = $articolo('CMB-21');
                $unFornitore::saveExtras($finestrata, [
                    'has_variants' => 'false',
                    'product_suppliers' => $finestra([
                        ['supplier_id' => $nord, 'supplier_sku' => 'FN-21', 'cost' => '6', 'is_preferred' => 'true'],
                    ]),
                ], 'CMB-21');
                $prodottoFinestra = (int) ($unFornitore::products($finestrata)[0]['id'] ?? 0);

                // Lo stesso nelle righe della griglia.
                $griglia = $articolo('CMB-22');
                $spunte = [
                    'has_variants' => 'true',
                    'axes_order' => (string) $colore['id'],
                    'option_'.$colore['id'] => [(string) $colore['values'][0]],
                ];
                ProductModelResource::saveExtras($griglia, $spunte + ['products' => [
                    (string) $colore['values'][0] => ['price' => '10,00', 'supplier_id' => (string) $nord, 'supplier_sku' => 'FN-22', 'cost' => '7'],
                ]], 'CMB-22');
                $prodottoGriglia = (int) (ProductModelResource::products($griglia)[0]['id'] ?? 0);
                $unFornitore::saveExtras($griglia, $spunte + ['products' => ['0' => [
                    'id' => (string) $prodottoGriglia,
                    'suppliers' => $finestra([['supplier_id' => $nord, 'supplier_sku' => 'FN-23', 'cost' => '8', 'is_preferred' => 'true']]),
                ]]], 'CMB-22');

                return $prodottoTre > 0 && $prodottoFinestra > 0 && $prodottoGriglia > 0
                    && $legami($prodottoTre) === [[$nord, 'FN-20', 4.5, 'true']]
                    && $legami($prodottoFinestra) === [[$nord, 'FN-21', 6.0, 'true']]
                    && $legami($prodottoGriglia) === [[$nord, 'FN-23', 8.0, 'true']];
            });
        });

        // Un fornitore messo su «Non attivo» resta sulle opzioni che lo usano
        // (P92), e solo lì: non si lega a un'altra riga, a una riga nuova, a
        // un'opzione di un altro articolo, né a un articolo che nasce adesso.
        check('un fornitore non attivo resta sull\'opzione che lo usa, e non va sulle altre', function () use ($conAcquisti, $articolo, $colore, $fornitore, $finestra) {
            return $conAcquisti(static function () use ($articolo, $colore, $fornitore, $finestra): bool {
                $nuovo = $articolo('CMB-24');
                ProductModelResource::saveExtras($nuovo, [
                    'has_variants' => 'true',
                    'axes_order' => (string) $colore['id'],
                    'option_'.$colore['id'] => array_map('strval', array_slice($colore['values'], 0, 2)),
                    'product_price' => '10,00',
                ], 'CMB-24');
                [$blu, $rosso] = array_map(static fn (array $product): int => (int) $product['id'], ProductModelResource::products($nuovo));
                $solo = $articolo('CMB-25');
                $unico = (int) (ProductModelResource::products($solo)[0]['id'] ?? 0);
                $chiusa = $fornitore('Prova Tintoria Chiusa', false);
                ProductSuppliers::sync($blu, [['supplier_id' => $chiusa, 'supplier_sku' => 'T-1', 'cost' => '2']]);
                ProductSuppliers::sync($unico, [['supplier_id' => $chiusa, 'supplier_sku' => 'T-3', 'cost' => '2']]);
                ProductModelResource::forgetCatalogCache();

                $conChiusa = $finestra([['supplier_id' => $chiusa, 'supplier_sku' => 'T-2', 'cost' => '3', 'is_preferred' => 'true']]);
                $esito = static function (int $modelId, array $post, bool $conVarianti): string {
                    try {
                        ProductModelResource::assertSupplierCosts($modelId, $post, $conVarianti);

                        return '';
                    } catch (UserError $errore) {
                        return $errore->key();
                    }
                };

                return $esito($nuovo, ['products' => ['0' => ['id' => (string) $blu, 'suppliers' => $conChiusa]]], true) === ''
                    && $esito($nuovo, ['products' => ['1' => ['id' => (string) $rosso, 'suppliers' => $conChiusa]]], true) === 'product.supplier_invalid'
                    && $esito($nuovo, ['products' => ['99' => ['id' => '', 'suppliers' => $conChiusa]]], true) === 'product.supplier_invalid'
                    // L'opzione di un altro articolo non porta i suoi legami qui.
                    && $esito($nuovo, ['products' => ['0' => ['id' => (string) $unico, 'suppliers' => $conChiusa]]], true) === 'product.supplier_invalid'
                    // Senza varianti conta l'articolo unico.
                    && $esito($solo, ['product_suppliers' => $conChiusa], false) === ''
                    && $esito(0, ['product_suppliers' => $conChiusa], false) === 'product.supplier_invalid'
                    // Le tre caselle, con un fornitore solo da proporre.
                    && $esito($nuovo, ['products' => ['1' => ['id' => (string) $rosso, 'supplier_id' => (string) $chiusa]]], true) === 'product.supplier_invalid';
            });
        });

        // Accendendo le varianti la prima combinazione riprende lo scheletro,
        // ma la sua riga nasce dal template, con la finestra vuota: quello
        // che ci si scrive si aggiunge ai fornitori dello scheletro, non li
        // sostituisce. Per staccarne uno c'è la «x» sulla riga dello scheletro.
        check('accendendo le varianti, i fornitori della riga nuova si aggiungono a quelli dello scheletro', function () use ($conAcquisti, $articolo, $colore, $nord, $sud, $legami, $finestra) {
            return $conAcquisti(static function () use ($articolo, $colore, $nord, $sud, $legami, $finestra): bool {
                $accendi = static function (string $sku, ?string $rigaScheletro) use ($articolo, $colore, $nord, $sud, $finestra): int {
                    $nuovo = $articolo($sku);
                    $scheletro = (int) (ProductModelResource::products($nuovo)[0]['id'] ?? 0);
                    ProductSuppliers::sync($scheletro, [
                        ['supplier_id' => $nord, 'supplier_sku' => 'FN-1', 'cost' => '12', 'is_preferred' => 'true'],
                    ]);
                    ProductModelResource::forgetCatalogCache();
                    $righe = $rigaScheletro === null ? [] : ['0' => ['id' => (string) $scheletro, 'suppliers' => $rigaScheletro]];
                    $righe[(string) $colore['values'][0]] = [
                        'price' => '10,00',
                        'suppliers' => $finestra([['supplier_id' => $sud, 'supplier_sku' => 'IS-1', 'cost' => '9,00', 'is_preferred' => 'true']]),
                    ];
                    ProductModelResource::saveExtras($nuovo, [
                        'has_variants' => 'true',
                        'axes_order' => (string) $colore['id'],
                        'option_'.$colore['id'] => [(string) $colore['values'][0]],
                        'products' => $righe,
                    ], $sku);
                    $prodotti = ProductModelResource::products($nuovo);

                    return count($prodotti) === 1 && (int) $prodotti[0]['id'] === $scheletro ? $scheletro : 0;
                };

                // La riga dello scheletro com'è nella pagina, non toccata.
                $intatta = $accendi('CMB-26', $finestra([['supplier_id' => $nord, 'supplier_sku' => 'FN-1', 'cost' => '12.00', 'is_preferred' => 'true']]));
                // La riga dello scheletro non arriva: valgono i legami salvati.
                $assente = $accendi('CMB-27', null);
                // Sulla riga dello scheletro il nord è stato staccato con la «x».
                $staccato = $accendi('CMB-28', '[]');

                return $intatta > 0 && $assente > 0 && $staccato > 0
                    && $legami($intatta) === [[$nord, 'FN-1', 12.0, 'false'], [$sud, 'IS-1', 9.0, 'true']]
                    && $legami($assente) === [[$nord, 'FN-1', 12.0, 'false'], [$sud, 'IS-1', 9.0, 'true']]
                    && $legami($staccato) === [[$sud, 'IS-1', 9.0, 'true']];
            });
        });

        check('senza acquisti quello che arriva non si scrive, e i legami restano', function () use ($conAcquisti, $articolo, $nord, $legami, $finestra) {
            return $conAcquisti(static function () use ($articolo, $nord, $legami, $finestra): bool {
                $nuovo = $articolo('CMB-18');
                $prodotto = (int) (ProductModelResource::products($nuovo)[0]['id'] ?? 0);
                ProductSuppliers::sync($prodotto, [['supplier_id' => $nord, 'supplier_sku' => 'N-9', 'cost' => '3']]);

                ProductModelResource::saveExtras($nuovo, [
                    'has_variants' => 'false',
                    'product_supplier_id' => '',
                    'product_supplier_sku' => '',
                    'product_cost' => '',
                    'product_suppliers' => '[]',
                ], 'CMB-18');
                $valori = ProductModelResource::mutateFormValues(['id' => $nuovo], 'edit');
                $chiavi = ['product_supplier_id', 'product_supplier_sku', 'product_cost', 'product_suppliers', 'product_cost_button'];

                return $legami($prodotto) === [[$nord, 'N-9', 3.0, 'true']]
                    && array_intersect($chiavi, array_keys($valori)) === [];
            }, false);
        });

        check('un\'opzione tolta dalla griglia perde i suoi fornitori, le altre no', function () use ($conAcquisti, $articolo, $colore, $nord, $legami) {
            return $conAcquisti(static function () use ($articolo, $colore, $nord, $legami): bool {
                $nuovo = $articolo('CMB-19');
                $spunte = [
                    'has_variants' => 'true',
                    'axes_order' => (string) $colore['id'],
                    'option_'.$colore['id'] => array_map('strval', array_slice($colore['values'], 0, 2)),
                    'product_price' => '10,00',
                ];
                ProductModelResource::saveExtras($nuovo, $spunte, 'CMB-19');
                $ids = array_map(static fn (array $product): int => (int) $product['id'], ProductModelResource::products($nuovo));

                foreach ($ids as $id) {
                    ProductSuppliers::sync($id, [['supplier_id' => $nord, 'cost' => '3']]);
                }

                // Il repeater del core mette la riga tolta nel cestino prima
                // di `afterUpdate()`: dritto sulla tabella, come fa lui.
                Product::query()->Update(Product::$table, ['deleted' => 'true'], 'id', $ids[1]);
                $spunte['option_'.$colore['id']] = [(string) $colore['values'][0]];
                ProductModelResource::saveExtras($nuovo, $spunte + ['products' => ['0' => ['id' => (string) $ids[0]]]], 'CMB-19');

                return count($ids) === 2
                    && count($legami($ids[0])) === 1
                    && $legami($ids[1]) === [];
            });
        });

        check('eliminare l\'articolo porta via i fornitori delle sue opzioni', function () use ($conAcquisti, $articolo, $colore, $nord, $sud, $legami) {
            // Anche senza acquisti: la chiave esterna non guarda le funzionalità.
            return $conAcquisti(static function () use ($articolo, $colore, $nord, $sud, $legami): bool {
                $nuovo = $articolo('CMB-20');
                ProductModelResource::saveExtras($nuovo, [
                    'has_variants' => 'true',
                    'axes_order' => (string) $colore['id'],
                    'option_'.$colore['id'] => array_map('strval', array_slice($colore['values'], 0, 2)),
                    'product_price' => '10,00',
                ], 'CMB-20');
                $ids = array_map(static fn (array $product): int => (int) $product['id'], ProductModelResource::products($nuovo));

                foreach ($ids as $id) {
                    ProductSuppliers::sync($id, [['supplier_id' => $nord, 'cost' => '3'], ['supplier_id' => $sud, 'cost' => '4']]);
                }

                ProductModelResource::deleteRecord($nuovo);

                return count($ids) === 2
                    && $legami($ids[0]) === []
                    && $legami($ids[1]) === [];
            }, false);
        });

        check('la pulizia dei dati di prova conta anche i fornitori che se ne vanno con l\'articolo', function () use ($articolo, $colore, $nord, $sud) {
            $nuovo = $articolo('CMB-21');
            ProductModelResource::saveExtras($nuovo, [
                'has_variants' => 'true',
                'axes_order' => (string) $colore['id'],
                'option_'.$colore['id'] => array_map('strval', array_slice($colore['values'], 0, 2)),
                'product_price' => '10,00',
            ], 'CMB-21');
            $ids = array_map(static fn (array $product): int => (int) $product['id'], ProductModelResource::products($nuovo));
            ProductSuppliers::sync($ids[0], [['supplier_id' => $nord, 'cost' => '3'], ['supplier_id' => $sud, 'cost' => '4']]);
            ProductSuppliers::sync($ids[1], [['supplier_id' => $nord, 'cost' => '3']]);

            return (new ReflectionMethod(CatalogDemo::class, 'supplierLinksOf'))->invoke(null, $nuovo) === 3;
        });

        check('la pulizia dei dati di prova conta anche i fornitori delle opzioni nel cestino', function () use ($articolo, $colore, $nord, $sud) {
            $nuovo = $articolo('CMB-29');
            ProductModelResource::saveExtras($nuovo, [
                'has_variants' => 'true',
                'axes_order' => (string) $colore['id'],
                'option_'.$colore['id'] => array_map('strval', array_slice($colore['values'], 0, 2)),
                'product_price' => '10,00',
            ], 'CMB-29');
            $ids = array_map(static fn (array $product): int => (int) $product['id'], ProductModelResource::products($nuovo));
            ProductSuppliers::sync($ids[0], [['supplier_id' => $nord, 'cost' => '3']]);
            ProductSuppliers::sync($ids[1], [['supplier_id' => $nord, 'cost' => '3'], ['supplier_id' => $sud, 'cost' => '4']]);

            // `deleteRecord()` porta via anche i legami delle opzioni nel
            // cestino: la pulizia li deve contare.
            Product::query()->Update(Product::$table, ['deleted' => 'true'], 'id', $ids[1]);

            return (new ReflectionMethod(CatalogDemo::class, 'supplierLinksOf'))->invoke(null, $nuovo) === 3;
        });

        throw new Annulla();
    });
} catch (Annulla) {
}

check('dopo l\'annullamento il catalogo è come prima', fn () => $tabelle() === $prima);

summary();
