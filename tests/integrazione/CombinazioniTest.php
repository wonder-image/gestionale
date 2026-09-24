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
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;
use Wonder\Plugin\Gestionale\Models\Stock\StockAlert;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\Combinations;
use Wonder\Plugin\Gestionale\Support\Catalog\Generator;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductAttributes;
use Wonder\Plugin\Gestionale\Support\Catalog\Skeleton;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
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

$prima = [$conta(ProductModel::class), $conta(ProductVariant::class), $conta(Product::class)];

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

        throw new Annulla();
    });
} catch (Annulla) {
}

check('dopo l\'annullamento il catalogo è come prima', fn () =>
    [$conta(ProductModel::class), $conta(ProductVariant::class), $conta(Product::class)] === $prima
);

summary();
