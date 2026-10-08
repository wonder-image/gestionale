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
use Wonder\Plugin\Gestionale\Support\Catalog\VariantSlugs;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Purchasing\ProductSuppliers;
use Wonder\Plugin\Gestionale\Support\Stock\Alerts;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Plugin\Gestionale\Support\Stock\Thresholds;
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

// Queste prove parlano di un magazzino con una sede sola: «Più sedi» si
// spegne a mano, qualunque cosa abbia acceso il sito.
Gestionale::feature('multi_location');
$unaSede = new ReflectionProperty(Gestionale::class, 'features');
$unaSede->setValue(null, array_merge((array) $unaSede->getValue(), ['multi_location' => false]));
Locations::reset();

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

        check('le varianti prendono lo slug dal loro valore, unico nel modello', function () use ($modelId) {
            $slug = array_column(ProductModelResource::variants($modelId), 'slug');
            sort($slug);

            return $slug === ['blu', 'rosso'];
        });

        check('con gli slug già giusti il comando non ha niente da fare', fn () =>
            VariantSlugs::plan($modelId) === []
        );

        check('il comando rifà gli slug in stile vecchio, e la seconda volta non cambia nulla', function () use ($modelId) {
            foreach (ProductModelResource::variants($modelId) as $variante) {
                ProductVariant::update(['slug' => 'vecchio-'.$variante['id']], (int) $variante['id']);
            }

            $scritte = VariantSlugs::apply(VariantSlugs::plan($modelId));
            $slug = array_column(ProductModelResource::variants($modelId), 'slug');
            sort($slug);

            return $scritte === 2 && $slug === ['blu', 'rosso'] && VariantSlugs::plan($modelId) === [];
        });

        check('lo scheletro nasce senza slug: non ha una pagina sua', function () {
            $altro = ProductModel::create([
                'code' => Code::make(ProductModel::class, Codes::MODEL),
                'name' => 'Prova scheletro',
                'slug' => Slug::make('prova-scheletro-'.uniqid()),
                'sku' => 'SCH-1',
                'unit' => 'pz',
                'type' => 'simple',
                'visible' => 'true',
                'visible_online' => 'true',
                'position' => 1,
            ]);
            $creato = Skeleton::forModel((int) ($altro->insert_id ?? 0), 'Prova scheletro', 'SCH-1');
            $variante = ProductVariant::find(['id' => $creato['variant_id']], 1);

            return is_array($variante) && (string) ($variante['slug'] ?? '') === '';
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


        /**
         * La soglia di un'opzione nella sede principale, 0 se non c'è: non è
         * più una colonna di `gst_products` ma una riga di
         * `gst_stock_thresholds` (P100).
         */
        $soglia = static fn (int $productId): float => (float) (Thresholds::forProduct($productId)[Locations::mainId()] ?? 0);

        check('la scorta minima scritta nella griglia si salva, alla nascita e dopo', function () use ($colore, $taglia, $soglia) {
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

                $productId = (int) (ProductModelResource::products($nuovo)[0]['id'] ?? 0);
                $allaNascita = $soglia($productId) === 5.0
                    && Alerts::openRow($productId) !== [];

                // Poi la si abbassa dalla riga, che adesso ha il suo id.
                ProductModelResource::saveExtras($nuovo, $spunte + [
                    'products' => ['0' => ['id' => (string) $productId, 'stock' => '2', 'min_stock' => '1']],
                ], 'CMB-4');

                $dopo = $soglia($productId) === 1.0
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

        check('accendendo le varianti la prima combinazione tiene scorta minima e giacenza della sua riga', function () use ($conAvvisi, $articolo, $colore, $taglia, $soglia) {
            return $conAvvisi(static function () use ($articolo, $colore, $taglia, $soglia): bool {
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
                    && $soglia($scheletro) === 3.0
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

        check('accendendo le varianti, dove la riga nuova è vuota vale quella dello scheletro', function () use ($conAvvisi, $articolo, $colore, $soglia) {
            return $conAvvisi(static function () use ($articolo, $colore, $soglia): bool {
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
                    && $soglia($scheletro) === 6.0
                    && Levels::of($scheletro)['quantity'] === 7.0;
            });
        });

        check('eliminate le righe fino a una, la griglia vale ancora: l\'interruttore spento non la butta', function () use ($conAvvisi, $articolo, $soglia) {
            return $conAvvisi(static function () use ($articolo, $soglia): bool {
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

                return $soglia($prodotto) === 8.0
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

        /** Le righe dei fornitori di un'opzione, in ordine di posizione. */
        $righeDi = static function (string $model, string $chiave, int $id): array {
            $rows = $model::find([$chiave => $id, 'deleted' => ['true', 'false']], null, 'position', 'ASC');

            return isset($rows['id']) ? [$rows] : array_values(array_filter((array) $rows, 'is_array'));
        };

        /** Fornitore, codice e costo di ogni riga: un preferito non c'è più (P98). */
        $terne = static fn (array $rows): array => array_map(static fn (array $row): array => [
            (int) $row['supplier_id'],
            (string) $row['supplier_sku'],
            $row['cost'] === null ? null : round((float) $row['cost'], 4),
        ], $rows);

        /** I fornitori di un'opzione (`gst_product_suppliers`, P107), in ordine. */
        $legami = static fn (int $productId): array => $terne($righeDi(ProductSupplier::class, 'product_id', $productId));

        /** Gli id delle opzioni di un articolo, nell'ordine della griglia. */
        $opzioni = static fn (int $modelId): array => array_map(
            static fn (array $product): int => (int) $product['id'],
            ProductModelResource::products($modelId)
        );

        /** Quello che la finestra «Fornitori» scrive nel suo campo nascosto. */
        $json = static fn (array $righe): string => (string) json_encode($righe);

        /** La chiave dell'errore con cui la scheda rifiuta i fornitori, '' se li accetta. */
        $rifiuto = static function (int $modelId, array $post, bool $conVarianti = false): string {
            ProductModelResource::forgetCatalogCache();

            try {
                ProductModelResource::assertSupplierRows($modelId, $post, $conVarianti);
            } catch (UserError $errore) {
                return $errore->key();
            }

            return '';
        };

        /** La scheda riaperta: quello che il form riceve, fornitori compresi. */
        $riapri = static function (int $modelId): array {
            ProductModelResource::forgetCatalogCache();

            return ProductModelResource::mutateFormValues(
                ProductModelResource::hydrateRepeaterFormValues(['id' => $modelId], $modelId, [], []),
                'edit'
            );
        };

        /** Un articolo con due colori, e le sue due opzioni. @return array{0: int, 1: list<int>} */
        $conDueColori = static function (string $sku, array $post = []) use ($articolo, $colore, $opzioni): array {
            $nuovo = $articolo($sku);
            ProductModelResource::saveExtras($nuovo, $post + [
                'has_variants' => 'true',
                'axes_order' => (string) $colore['id'],
                'option_'.$colore['id'] => array_map('strval', array_slice($colore['values'], 0, 2)),
                'product_price' => '10,00',
            ], $sku);

            return [$nuovo, $opzioni($nuovo)];
        };

        // I fornitori del sito si mettono da parte: da quanti ne propone la
        // scheda dipende come si compilano (P109), e la prova conta i suoi.
        Contact::query()->Update(Contact::$table, ['active' => 'false'], 'is_supplier', 'true');

        $nord = $fornitore('Prova Filati Nord');

        // --- Un fornitore solo: due campi, codice e costo (P109).

        check('con un fornitore solo codice e costo si scrivono dai due campi, e vuoti lo tolgono', function () use ($conAcquisti, $articolo, $nord, $opzioni, $legami, $riapri) {
            return $conAcquisti(static function () use ($articolo, $nord, $opzioni, $legami, $riapri): bool {
                $nuovo = $articolo('CMB-11');
                $prodotto = $opzioni($nuovo)[0] ?? 0;
                ProductModelResource::saveExtras($nuovo, [
                    'has_variants' => 'false',
                    'product_price' => '10,00',
                    'product_supplier_sku' => ' FN-1 ',
                    'product_supplier_cost' => '12,50',
                ], 'CMB-11');
                $salvati = $legami($prodotto);
                $valori = $riapri($nuovo);

                // Solo il codice: il costo che non si sa resta NULL.
                ProductModelResource::saveExtras($nuovo, [
                    'has_variants' => 'false',
                    'product_supplier_sku' => 'FN-2',
                    'product_supplier_cost' => '',
                ], 'CMB-11');
                $senzaCosto = $legami($prodotto);

                // Una pagina che i due campi non li manda non tocca niente.
                ProductModelResource::saveExtras($nuovo, ['has_variants' => 'false', 'product_price' => '11,00'], 'CMB-11');
                $intatti = $legami($prodotto);

                ProductModelResource::saveExtras($nuovo, [
                    'has_variants' => 'false',
                    'product_supplier_sku' => '',
                    'product_supplier_cost' => '',
                ], 'CMB-11');

                return $salvati === [[$nord, 'FN-1', 12.5]]
                    // Grezzo col punto e due decimali: cifre e valuta le
                    // mette la casella.
                    && ($valori['product_supplier_sku'] ?? null) === 'FN-1'
                    && ($valori['product_supplier_cost'] ?? null) === '12.50'
                    && !array_key_exists('product_suppliers', $valori)
                    && $senzaCosto === [[$nord, 'FN-2', null]]
                    && $intatti === $senzaCosto
                    && $legami($prodotto) === [];
            });
        });

        check('la scheda riaperta ha il prezzo scontato e non le vecchie caselle del fornitore', function () use ($conAcquisti, $articolo, $riapri) {
            return $conAcquisti(static function () use ($articolo, $riapri): bool {
                $nuovo = $articolo('CMB-12');
                ProductModelResource::saveExtras($nuovo, [
                    'has_variants' => 'false',
                    'product_price' => '10,00',
                    'product_sale_price' => '8,00',
                ], 'CMB-12');
                $valori = $riapri($nuovo);
                // La tendina del fornitore, la finestra «Costo» e il riquadro
                // a righe dell'articolo: non esistono più (P107).
                $vecchie = ['product_supplier_id', 'product_cost', 'product_cost_button', 'suppliers'];

                return ($valori['product_price'] ?? null) === '10.00'
                    && ($valori['product_sale_price'] ?? null) === '8.00'
                    && array_intersect($vecchie, array_keys($valori)) === []
                    && ($valori['product_supplier_sku'] ?? null) === ''
                    && ($valori['product_supplier_cost'] ?? null) === '';
            });
        });

        check('con un fornitore solo ogni riga della griglia ha i suoi due campi', function () use ($conAcquisti, $conDueColori, $nord, $legami, $riapri) {
            return $conAcquisti(static function () use ($conDueColori, $nord, $legami, $riapri): bool {
                [$nuovo, $ids] = $conDueColori('CMB-14');
                ProductModelResource::saveExtras($nuovo, [
                    'has_variants' => 'true',
                    // Nascosti, arrivano lo stesso: con le varianti non si guardano.
                    'product_supplier_sku' => 'NO',
                    'product_supplier_cost' => '99',
                    'products' => [
                        '0' => ['id' => (string) $ids[0], 'supplier_sku' => 'FN-BLU', 'supplier_cost' => '3,10'],
                        '1' => ['id' => (string) $ids[1], 'supplier_sku' => '', 'supplier_cost' => '4'],
                    ],
                ], 'CMB-14');
                $righe = array_values((array) ($riapri($nuovo)['products'] ?? []));

                return count($ids) === 2
                    && $legami($ids[0]) === [[$nord, 'FN-BLU', 3.1]]
                    && $legami($ids[1]) === [[$nord, '', 4.0]]
                    && ($righe[0]['supplier_sku'] ?? null) === 'FN-BLU'
                    && ($righe[0]['supplier_cost'] ?? null) === '3.10'
                    && ($righe[1]['supplier_cost'] ?? null) === '4.00';
            });
        });

        check('accendendo le varianti le opzioni prendono il fornitore dell\'articolo, e la riga scritta vince', function () use ($conAcquisti, $articolo, $colore, $nord, $opzioni, $legami) {
            return $conAcquisti(static function () use ($articolo, $colore, $nord, $opzioni, $legami): bool {
                $nuovo = $articolo('CMB-22');
                ProductModelResource::saveExtras($nuovo, [
                    'has_variants' => 'false',
                    'product_price' => '10,00',
                    'product_supplier_sku' => 'FN-UNO',
                    'product_supplier_cost' => '5,00',
                ], 'CMB-22');
                $scheletro = $opzioni($nuovo)[0] ?? 0;

                // Tre colori non ci sono: due colori, e il secondo con la sua
                // riga già compilata.
                ProductModelResource::saveExtras($nuovo, [
                    'has_variants' => 'true',
                    'axes_order' => (string) $colore['id'],
                    'option_'.$colore['id'] => array_map('strval', array_slice($colore['values'], 0, 2)),
                    'product_price' => '10,00',
                    'product_supplier_sku' => 'FN-UNO',
                    'product_supplier_cost' => '5,00',
                ], 'CMB-22');
                $ids = $opzioni($nuovo);

                $altro = $articolo('CMB-23');
                ProductModelResource::saveExtras($altro, [
                    'has_variants' => 'false',
                    'product_supplier_sku' => 'FN-DUE',
                    'product_supplier_cost' => '6,00',
                ], 'CMB-23');
                ProductModelResource::saveExtras($altro, [
                    'has_variants' => 'true',
                    'axes_order' => (string) $colore['id'],
                    'option_'.$colore['id'] => array_map('strval', array_slice($colore['values'], 0, 2)),
                    'product_supplier_sku' => 'FN-DUE',
                    'product_supplier_cost' => '6,00',
                    'products' => [
                        (string) $colore['values'][1] => ['price' => '10,00', 'supplier_sku' => 'FN-ROSSO', 'supplier_cost' => '7,00'],
                    ],
                ], 'CMB-23');
                $suoi = $opzioni($altro);

                return count($ids) === 2
                    && in_array($scheletro, $ids, true)
                    && $legami($ids[0]) === [[$nord, 'FN-UNO', 5.0]]
                    && $legami($ids[1]) === [[$nord, 'FN-UNO', 5.0]]
                    && count($suoi) === 2
                    && $legami($suoi[0]) === [[$nord, 'FN-DUE', 6.0]]
                    && $legami($suoi[1]) === [[$nord, 'FN-ROSSO', 7.0]];
            });
        });

        check('i due campi si fermano su un costo che non regge', function () use ($conAcquisti, $articolo, $opzioni, $rifiuto) {
            return $conAcquisti(static function () use ($articolo, $opzioni, $rifiuto): bool {
                $nuovo = $articolo('CMB-26');
                $prodotto = $opzioni($nuovo)[0] ?? 0;

                return $rifiuto($nuovo, ['product_supplier_sku' => 'A', 'product_supplier_cost' => '1,50']) === ''
                    && $rifiuto($nuovo, ['product_supplier_sku' => '', 'product_supplier_cost' => '']) === ''
                    && $rifiuto($nuovo, ['product_supplier_sku' => 'A', 'product_supplier_cost' => '-1']) === 'product.supplier_cost_negative'
                    && $rifiuto($nuovo, ['product_supplier_sku' => 'A', 'product_supplier_cost' => 'caro']) === 'product.supplier_cost_invalid'
                    // Con le varianti contano le righe, non il riquadro…
                    && $rifiuto($nuovo, [
                        'products' => ['0' => ['id' => (string) $prodotto, 'supplier_sku' => 'A', 'supplier_cost' => '-1']],
                    ], true) === 'product.supplier_cost_negative'
                    // …tranne che per l'articolo con un prodotto solo (P115).
                    && $rifiuto($nuovo, ['product_supplier_sku' => 'A', 'product_supplier_cost' => '-1'], true) === 'product.supplier_cost_negative'
                    // Un articolo che nasce adesso.
                    && $rifiuto(0, ['product_supplier_sku' => 'A', 'product_supplier_cost' => '2']) === '';
            });
        });

        // --- Due fornitori o più: il bottone e la finestra (P109, P110).

        $sud = $fornitore('Prova Lanificio Sud');

        check('con più fornitori quelli dell\'articolo arrivano dalla finestra, in ordine, e il costo vuoto a NULL', function () use ($conAcquisti, $articolo, $nord, $sud, $opzioni, $legami, $riapri, $json) {
            return $conAcquisti(static function () use ($articolo, $nord, $sud, $opzioni, $legami, $riapri, $json): bool {
                $nuovo = $articolo('CMB-13');
                $prodotto = $opzioni($nuovo)[0] ?? 0;
                ProductModelResource::saveExtras($nuovo, [
                    'has_variants' => 'false',
                    'product_suppliers' => $json([
                        ['supplier_id' => (string) $sud, 'supplier_sku' => ' LS-1 ', 'cost' => '12,50'],
                        ['supplier_id' => (string) $nord, 'supplier_sku' => '', 'cost' => ''],
                        // Aggiunta e lasciata vuota: non è un fornitore.
                        ['supplier_id' => '', 'supplier_sku' => '', 'cost' => ''],
                    ]),
                ], 'CMB-13');
                $salvati = $legami($prodotto);
                $valori = $riapri($nuovo);
                $righe = ProductSuppliers::fromJson($valori['product_suppliers'] ?? null) ?? [];

                // Finestra mai aperta: il campo nascosto arriva vuoto, e i
                // legami restano.
                ProductModelResource::saveExtras($nuovo, ['has_variants' => 'false', 'product_suppliers' => ''], 'CMB-13');
                $intatti = $legami($prodotto);

                // Il sud non c'è più: se ne va davvero.
                ProductModelResource::saveExtras($nuovo, [
                    'has_variants' => 'false',
                    'product_suppliers' => $json([['supplier_id' => $nord, 'supplier_sku' => 'FN-1', 'cost' => '13.00']]),
                ], 'CMB-13');
                $dopo = $legami($prodotto);

                ProductModelResource::saveExtras($nuovo, ['has_variants' => 'false', 'product_suppliers' => '[]'], 'CMB-13');

                return $salvati === [[$sud, 'LS-1', 12.5], [$nord, '', null]]
                    && array_map('intval', array_column($righe, 'supplier_id')) === [$sud, $nord]
                    && ($righe[0]['cost'] ?? null) === '12.50'
                    && ($righe[1]['cost'] ?? null) === ''
                    && ($valori['product_suppliers_button'] ?? null) === 'Prova Lanificio Sud 12,50 € · Prova Filati Nord'
                    && !array_key_exists('product_supplier_sku', $valori)
                    && $intatti === $salvati
                    && $dopo === [[$nord, 'FN-1', 13.0]]
                    && $legami($prodotto) === [];
            });
        });

        check('un costo con quattro decimali resta com\'è se la casella dice lo stesso numero', function () use ($conAcquisti, $articolo, $nord, $opzioni, $legami, $riapri, $json) {
            return $conAcquisti(static function () use ($articolo, $nord, $opzioni, $legami, $riapri, $json): bool {
                $nuovo = $articolo('CMB-15');
                $prodotto = $opzioni($nuovo)[0] ?? 0;
                ProductSuppliers::sync($prodotto, [['supplier_id' => $nord, 'supplier_sku' => 'FN-3', 'cost' => 12.3456]]);
                $righe = ProductSuppliers::fromJson($riapri($nuovo)['product_suppliers'] ?? null) ?? [];
                $riga = ['supplier_id' => $nord, 'supplier_sku' => 'FN-3', 'cost' => '12.35'];

                ProductModelResource::saveExtras($nuovo, ['has_variants' => 'false', 'product_suppliers' => $json([$riga])], 'CMB-15');
                $tenuto = $legami($prodotto);
                ProductModelResource::saveExtras($nuovo, ['has_variants' => 'false', 'product_suppliers' => $json([['cost' => '12,40'] + $riga])], 'CMB-15');

                return ($righe[0]['cost'] ?? null) === '12.35'
                    && $tenuto === [[$nord, 'FN-3', 12.3456]]
                    && $legami($prodotto) === [[$nord, 'FN-3', 12.4]];
            });
        });

        check('con le varianti ogni riga della griglia ha i suoi fornitori', function () use ($conAcquisti, $conDueColori, $articolo, $nord, $sud, $opzioni, $legami, $riapri, $json) {
            return $conAcquisti(static function () use ($conDueColori, $articolo, $nord, $sud, $opzioni, $legami, $riapri, $json): bool {
                [$nuovo, $ids] = $conDueColori('CMB-16');
                $altrui = $opzioni($articolo('CMB-27'))[0] ?? 0;
                ProductSuppliers::sync($altrui, [['supplier_id' => $sud, 'supplier_sku' => 'SUO', 'cost' => '1']]);

                ProductModelResource::saveExtras($nuovo, [
                    'has_variants' => 'true',
                    // Nascosto, arriva lo stesso: con le varianti non si guarda.
                    'product_suppliers' => $json([['supplier_id' => $sud, 'supplier_sku' => 'NO', 'cost' => '99']]),
                    'products' => [
                        '0' => ['id' => (string) $ids[0], 'suppliers' => $json([
                            ['supplier_id' => $nord, 'supplier_sku' => 'FN-BLU', 'cost' => '3,10'],
                            ['supplier_id' => $sud, 'supplier_sku' => 'LS-BLU', 'cost' => '2,90'],
                        ])],
                        '1' => ['id' => (string) $ids[1], 'suppliers' => $json([
                            ['supplier_id' => $sud, 'supplier_sku' => 'LS-ROSSO', 'cost' => ''],
                        ])],
                        // Un form ritoccato: l'opzione è di un altro articolo.
                        '2' => ['id' => (string) $altrui, 'suppliers' => '[]'],
                    ],
                ], 'CMB-16');
                $scritti = [$legami($ids[0]), $legami($ids[1])];
                $righe = array_values((array) ($riapri($nuovo)['products'] ?? []));

                // La finestra della seconda non si apre: resta com'è.
                ProductModelResource::saveExtras($nuovo, [
                    'has_variants' => 'true',
                    'products' => [
                        '0' => ['id' => (string) $ids[0], 'suppliers' => '[]'],
                        '1' => ['id' => (string) $ids[1], 'suppliers' => ''],
                    ],
                ], 'CMB-16');

                return count($ids) === 2
                    && $altrui > 0
                    && $scritti[0] === [[$nord, 'FN-BLU', 3.1], [$sud, 'LS-BLU', 2.9]]
                    && $scritti[1] === [[$sud, 'LS-ROSSO', null]]
                    && $legami($altrui) === [[$sud, 'SUO', 1.0]]
                    && ($righe[0]['suppliers_button'] ?? null) === 'Prova Filati Nord 3,10 € · Prova Lanificio Sud 2,90 €'
                    && ($righe[1]['suppliers_button'] ?? null) === 'Prova Lanificio Sud'
                    && $legami($ids[0]) === []
                    && $legami($ids[1]) === $scritti[1];
            });
        });

        check('accendendo le varianti le opzioni copiano i fornitori dell\'articolo (P115)', function () use ($conAcquisti, $articolo, $colore, $nord, $sud, $opzioni, $legami, $json) {
            return $conAcquisti(static function () use ($articolo, $colore, $nord, $sud, $opzioni, $legami, $json): bool {
                $nuovo = $articolo('CMB-28');
                $scheletro = $opzioni($nuovo)[0] ?? 0;
                $suoi = $json([
                    ['supplier_id' => $nord, 'supplier_sku' => 'FN-1', 'cost' => '5,00'],
                    ['supplier_id' => $sud, 'supplier_sku' => 'LS-1', 'cost' => '4,50'],
                ]);
                ProductModelResource::saveExtras($nuovo, ['has_variants' => 'false', 'product_suppliers' => $suoi], 'CMB-28');
                ProductModelResource::saveExtras($nuovo, [
                    'has_variants' => 'true',
                    'axes_order' => (string) $colore['id'],
                    'option_'.$colore['id'] => array_map('strval', array_slice($colore['values'], 0, 2)),
                    'product_price' => '10,00',
                    'product_suppliers' => $suoi,
                ], 'CMB-28');
                $ids = $opzioni($nuovo);
                $attesi = [[$nord, 'FN-1', 5.0], [$sud, 'LS-1', 4.5]];

                // Un colore in più, dopo: le varianti c'erano già, e chi
                // nasce adesso non copia niente.
                $terzo = AttributeValue::create(['attribute_id' => $colore['id'], 'label' => 'Verde', 'color' => '', 'position' => 3]);
                ProductModelResource::forgetCatalogCache();
                ProductModelResource::saveExtras($nuovo, [
                    'has_variants' => 'true',
                    'axes_order' => (string) $colore['id'],
                    'option_'.$colore['id'] => array_merge(
                        array_map('strval', array_slice($colore['values'], 0, 2)),
                        [(string) ($terzo->insert_id ?? 0)]
                    ),
                    'products' => [
                        '0' => ['id' => (string) $ids[0]],
                        '1' => ['id' => (string) $ids[1]],
                    ],
                ], 'CMB-28');
                $dopo = $opzioni($nuovo);
                $nata = array_values(array_diff($dopo, $ids));

                return count($ids) === 2
                    && in_array($scheletro, $ids, true)
                    && $legami($ids[0]) === $attesi
                    && $legami($ids[1]) === $attesi
                    && count($nata) === 1
                    && $legami($nata[0]) === [];
            });
        });

        check('un fornitore che la scheda non propone, o uno ripetuto, si ferma prima del salvataggio', function () use ($conAcquisti, $articolo, $nord, $sud, $opzioni, $fornitore, $rifiuto, $json) {
            return $conAcquisti(static function () use ($articolo, $nord, $sud, $opzioni, $fornitore, $rifiuto, $json): bool {
                $nuovo = $articolo('CMB-17');
                $prodotto = $opzioni($nuovo)[0] ?? 0;
                $fermo = $fornitore('Prova Tintoria Ferma', false);
                $una = static fn (array $righe): array => ['product_suppliers' => $json($righe)];
                $doppio = [
                    ['supplier_id' => $nord, 'cost' => '1'],
                    ['supplier_id' => $nord, 'cost' => '2'],
                ];

                return $rifiuto($nuovo, $una([['supplier_id' => $nord, 'supplier_sku' => 'N-1', 'cost' => '1']])) === ''
                    && $rifiuto($nuovo, $una([])) === ''
                    && $rifiuto($nuovo, ['product_suppliers' => '']) === ''
                    && $rifiuto($nuovo, $una([['supplier_id' => $fermo, 'supplier_sku' => 'T-1', 'cost' => '1']])) === 'product.supplier_invalid'
                    && $rifiuto($nuovo, $una([['supplier_id' => '', 'supplier_sku' => 'T-1', 'cost' => '1']])) === 'product.supplier_missing'
                    && $rifiuto($nuovo, $una([['supplier_id' => $sud, 'cost' => '-0,01']])) === 'product.supplier_cost_negative'
                    && $rifiuto($nuovo, $una($doppio)) === 'product.supplier_duplicate'
                    // Con le varianti il riquadro è nascosto: contano le righe.
                    && $rifiuto($nuovo, [
                        'products' => ['0' => ['id' => (string) $prodotto, 'suppliers' => $json($doppio)]],
                    ], true) === 'product.supplier_duplicate'
                    // I due campi, rimasti da una pagina aperta quando il
                    // fornitore era uno: compilati non si sa di chi siano.
                    && $rifiuto($nuovo, ['product_supplier_sku' => 'A', 'product_supplier_cost' => '1']) === 'product.supplier_invalid'
                    && $rifiuto($nuovo, ['product_supplier_sku' => '', 'product_supplier_cost' => '']) === '';
            });
        });

        // Un fornitore messo su «Non attivo» resta sull'opzione che lo usa
        // (P92), e solo lì: non si lega a un'altra opzione, né a un altro
        // articolo, né a uno che nasce adesso.
        check('un fornitore non attivo resta sull\'opzione che lo usa, e non va sulle altre', function () use ($conAcquisti, $conDueColori, $articolo, $fornitore, $rifiuto, $json) {
            return $conAcquisti(static function () use ($conDueColori, $articolo, $fornitore, $rifiuto, $json): bool {
                [$usa, $ids] = $conDueColori('CMB-24');
                $altro = $articolo('CMB-25');
                $chiusa = $fornitore('Prova Tintoria Chiusa', false);
                ProductSuppliers::sync($ids[0], [['supplier_id' => $chiusa, 'supplier_sku' => 'T-1', 'cost' => '2']]);
                $suoi = $json([['supplier_id' => $chiusa, 'supplier_sku' => 'T-2', 'cost' => '3']]);
                $riga = static fn (int $id): array => ['products' => ['0' => ['id' => (string) $id, 'suppliers' => $suoi]]];

                return count($ids) === 2
                    && $rifiuto($usa, $riga($ids[0]), true) === ''
                    && $rifiuto($usa, $riga($ids[1]), true) === 'product.supplier_invalid'
                    && $rifiuto($altro, ['product_suppliers' => $suoi]) === 'product.supplier_invalid'
                    && $rifiuto(0, ['product_suppliers' => $suoi]) === 'product.supplier_invalid';
            });
        });

        check('senza acquisti quello che arriva non si scrive, e i fornitori restano', function () use ($conAcquisti, $articolo, $nord, $opzioni, $legami, $riapri, $rifiuto, $json) {
            return $conAcquisti(static function () use ($articolo, $nord, $opzioni, $legami, $riapri, $rifiuto, $json): bool {
                $nuovo = $articolo('CMB-18');
                $prodotto = $opzioni($nuovo)[0] ?? 0;
                ProductSuppliers::sync($prodotto, [['supplier_id' => $nord, 'supplier_sku' => 'N-9', 'cost' => '3']]);

                // I campi non ci sono: quello che arriva non si guarda.
                $post = [
                    'has_variants' => 'false',
                    'product_suppliers' => $json([['supplier_id' => '999999', 'cost' => '1']]),
                    'product_supplier_sku' => 'X',
                    'product_supplier_cost' => '-5',
                ];
                ProductModelResource::saveExtras($nuovo, $post, 'CMB-18');
                $valori = $riapri($nuovo);
                $campi = ['product_suppliers', 'product_suppliers_button', 'product_supplier_sku', 'product_supplier_cost'];

                return $legami($prodotto) === [[$nord, 'N-9', 3.0]]
                    && array_intersect($campi, array_keys($valori)) === []
                    && $rifiuto($nuovo, $post) === '';
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

                $prima = count($legami($ids[0])) + count($legami($ids[1]));
                ProductModelResource::deleteRecord($nuovo);

                return count($ids) === 2
                    && $prima === 4
                    && $legami($ids[0]) === []
                    && $legami($ids[1]) === [];
            }, false);
        });

        check('la pulizia dei dati di prova conta i fornitori di tutte le opzioni dell\'articolo', function () use ($articolo, $colore, $nord, $sud) {
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
