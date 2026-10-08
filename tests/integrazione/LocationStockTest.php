<?php
/** php tests/integrazione/LocationStockTest.php */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\App\Models\Config\SocietyLocation;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Attribute;
use Wonder\Plugin\Gestionale\Models\Catalog\AttributeValue;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductSupplier;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Locations\Location;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\Skeleton;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Purchasing\ProductSuppliers;
use Wonder\Plugin\Gestionale\Support\Stock\Alerts;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Plugin\Gestionale\Support\Stock\Reasons;
use Wonder\Plugin\Gestionale\Support\Stock\Stock;
use Wonder\Plugin\Gestionale\Support\Stock\Thresholds;
use Wonder\Sql\Transaction;

/*
 * Giacenza e scorta minima per sede dalla scheda dell'articolo e da quella
 * dell'opzione, con due sedi (P100–P103): le righe «Giacenza per sede» si
 * salvano come soglie e rettifiche, una riga tolta porta via la soglia e
 * lascia i pezzi, le righe sbagliate si fermano prima, e l'eliminazione
 * porta via soglie e fornitori.
 */

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
    ProductModel::class, Product::class, ProductSupplier::class,
    Contact::class, Location::class, StockMovement::class,
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

/** Un articolo con il suo scheletro, come lo lascia `afterStore()` prima degli extra. */
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

/** Una sede di prova che tiene merce: la riga del core e quella del gestionale. */
$nuovaSede = static function (string $nome, int $posizione): int {
    $sede = SocietyLocation::create([
        'label' => $nome,
        'slug' => Slug::make($nome.'-'.uniqid()),
        'position' => $posizione,
        'visible' => 'true',
    ]);
    $coreId = (int) ($sede->insert_id ?? 0);
    $riga = Location::create(['society_location_id' => $coreId, 'has_stock' => 'true', 'active' => 'true']);

    return (int) ($riga->insert_id ?? 0);
};

$fornitore = static function (string $nome): int {
    $creato = Contact::create([
        'type' => 'business',
        'business_name' => $nome,
        'country' => 'IT',
        'is_customer' => 'false',
        'is_supplier' => 'true',
        'active' => 'true',
    ]);

    return (int) ($creato->insert_id ?? 0);
};

/** I pezzi di un prodotto per sede, `[sede => pezzi]`, in ordine di sede. */
$pezzi = static function (int $productId): array {
    $mappa = [];

    foreach (Levels::byLocation($productId) as $locationId => $level) {
        $mappa[(int) $locationId] = (float) $level['quantity'];
    }

    ksort($mappa);

    return $mappa;
};

/** Le soglie di un prodotto per sede, in ordine di sede: solo quelle sopra zero. */
$soglie = static function (int $productId): array {
    $mappa = Thresholds::forProduct($productId);
    ksort($mappa);

    return $mappa;
};

/** Causale e sede di ogni movimento di un prodotto, in ordine. @return list<array{string, int}> */
$movimenti = static function (int $productId): array {
    $rows = StockMovement::find('product_id = '.$productId." AND deleted = 'false'", null, 'id ASC');

    if (!is_array($rows) || $rows === []) {
        return [];
    }

    $rows = isset($rows['id']) ? [$rows] : array_filter($rows, 'is_array');

    return array_values(array_map(
        static fn (array $row): array => [(string) $row['reason'], (int) ($row['location_id'] ?? 0)],
        $rows
    ));
};

/** Le righe di una delle due tabelle dei fornitori, anche nel cestino. */
$legami = static function (string $model, string $chiave, int $id): int {
    $rows = $model::find([$chiave => $id, 'deleted' => ['true', 'false']]);

    if (!is_array($rows) || $rows === []) {
        return 0;
    }

    return isset($rows['id']) ? 1 : count(array_filter($rows, 'is_array'));
};

/** La chiave dell'errore con cui la scheda dell'articolo rifiuta le righe per sede, '' se le accetta. */
$rifiutoArticolo = static function (array $post, bool $conVarianti, int $modelId = 0): string {
    try {
        ProductModelResource::assertLocationRows($post, $conVarianti, $modelId);
    } catch (UserError $errore) {
        return $errore->key();
    }

    return '';
};

/** Lo stesso per la scheda dell'opzione, che legge le righe dalla richiesta. */
$rifiutoOpzione = static function (int $productId, array $righe): string {
    $_POST['locations'] = $righe;

    try {
        ProductResource::mutateRequestValues(['price' => '1,00'], 'update', 'backend', ['id' => $productId]);
    } catch (UserError $errore) {
        return $errore->key();
    } finally {
        unset($_POST['locations']);
    }

    return '';
};

// Le sedi si leggono una volta per richiesta, e la funzionalità pure: qui
// si accendono a mano, e le sedi nascono dentro la transazione.
Gestionale::feature('multi_location');
$features = new ReflectionProperty(Gestionale::class, 'features');
$primaFeatures = $features->getValue();
$accendi = static function (bool $avvisi, bool $arretrati = false) use ($features, $primaFeatures): void {
    $features->setValue(null, array_merge((array) $primaFeatures, [
        'multi_location' => true,
        'low_stock_alerts' => $avvisi,
        'backorders' => $arretrati,
    ]));
    Locations::reset();
};

try {
    Transaction::run(static function () use ($attributo, $articolo, $nuovaSede, $fornitore, $pezzi, $soglie, $movimenti, $legami, $rifiutoArticolo, $rifiutoOpzione, $accendi): void {
        $accendi(true);
        ProductModelResource::forgetCatalogCache();
        $centro = $nuovaSede('Prova Centro Sedi', 40);
        $deposito = $nuovaSede('Prova Deposito Sedi', 50);
        Locations::reset();
        $principale = Locations::mainId();

        check('le due sedi nuove hanno la loro colonna, e la giacenza non si scrive più in alto', function () use ($centro, $deposito, $principale) {
            $mostrate = array_column(Locations::shown(), 'id');

            return $centro > 0 && $deposito > 0 && $principale > 0
                && $centro !== $principale && $deposito !== $principale
                && in_array($centro, $mostrate, true)
                && in_array($deposito, $mostrate, true)
                && !ProductModelResource::stockIsWritable();
        });

        $nuovo = $articolo('SED-1');
        $prodotto = (int) (ProductModelResource::products($nuovo)[0]['id'] ?? 0);

        check('un articolo nuovo carica i pezzi di ogni riga per sede, e la soglia apre l\'avviso nella sua sede', function () use ($nuovo, $prodotto, $centro, $deposito, $principale, $pezzi, $soglie, $movimenti) {
            // Una riga per sede, la principale compresa; l'ultima è quella
            // aggiunta e lasciata vuota. La casella in alto con più sedi non
            // c'è: un numero arrivato lo stesso non carica niente.
            ProductModelResource::saveExtras($nuovo, [
                'has_variants' => 'false',
                'product_price' => '10,00',
                'product_stock' => '9',
                'locations' => [
                    ['location_id' => (string) $principale, 'stock' => '2', 'min_stock' => ''],
                    ['location_id' => (string) $centro, 'stock' => '5', 'min_stock' => '2'],
                    ['location_id' => (string) $deposito, 'stock' => '3', 'min_stock' => '4'],
                    ['location_id' => '', 'stock' => '', 'min_stock' => ''],
                ],
            ], 'SED-1', [], true);

            $attesi = [$principale => 2.0, $centro => 5.0, $deposito => 3.0];
            ksort($attesi);
            $causali = $movimenti($prodotto);

            return $prodotto > 0
                && $pezzi($prodotto) === $attesi
                && $soglie($prodotto) === [$centro => 2.0, $deposito => 4.0]
                && count($causali) === 3
                && array_unique(array_column($causali, 0)) === ['initial_stock']
                && Alerts::openRow($prodotto, $deposito) !== []
                && Alerts::openRow($prodotto, $centro) === [];
        });

        check('riaperto, il numero riscritto è una rettifica nella sua sede e la casella vuota lascia i pezzi', function () use ($nuovo, $prodotto, $centro, $deposito, $principale, $pezzi, $soglie, $movimenti) {
            ProductModelResource::saveExtras($nuovo, [
                'has_variants' => 'false',
                'locations' => [
                    ['location_id' => (string) $centro, 'stock' => '8', 'min_stock' => '2'],
                    ['location_id' => (string) $deposito, 'stock' => '', 'min_stock' => '6'],
                ],
            ], 'SED-1');

            $attesi = [$principale => 2.0, $centro => 8.0, $deposito => 3.0];
            ksort($attesi);
            $ultimo = $movimenti($prodotto)[3] ?? null;

            return $pezzi($prodotto) === $attesi
                && $soglie($prodotto) === [$centro => 2.0, $deposito => 6.0]
                && count($movimenti($prodotto)) === 4
                && $ultimo === [Reasons::DEFAULT, $centro]
                && Alerts::openRow($prodotto, $deposito) !== []
                && Alerts::openRow($prodotto, $centro) === [];
        });

        check('la scheda riaperta mostra una riga per sede con pezzi e soglia grezzi', function () use ($nuovo, $centro, $deposito) {
            $valori = ProductModelResource::mutateFormValues(['id' => $nuovo], 'edit');
            $righe = [];

            foreach ((array) ($valori['locations'] ?? []) as $riga) {
                $righe[(int) $riga['location_id']] = [$riga['stock'], $riga['min_stock']];
            }

            return !array_key_exists('product_stock', $valori)
                && !array_key_exists('product_min_stock', $valori)
                && ($righe[$centro] ?? null) === ['8', '2']
                && ($righe[$deposito] ?? null) === ['3', '6'];
        });

        check('una riga tolta porta via la sua soglia, chiude l\'avviso e lascia i pezzi dove sono', function () use ($nuovo, $prodotto, $centro, $deposito, $principale, $pezzi, $soglie, $movimenti) {
            ProductModelResource::saveExtras($nuovo, [
                'has_variants' => 'false',
                'locations' => [
                    ['location_id' => (string) $centro, 'stock' => '', 'min_stock' => '2'],
                ],
            ], 'SED-1');

            $attesi = [$principale => 2.0, $centro => 8.0, $deposito => 3.0];
            ksort($attesi);
            $righe = [];

            foreach ((array) (ProductModelResource::mutateFormValues(['id' => $nuovo], 'edit')['locations'] ?? []) as $riga) {
                $righe[(int) $riga['location_id']] = [$riga['stock'], $riga['min_stock']];
            }

            return $pezzi($prodotto) === $attesi
                && $soglie($prodotto) === [$centro => 2.0]
                && count($movimenti($prodotto)) === 4
                && Alerts::openRow($prodotto, $deposito) === []
                // La sede con i pezzi torna in riga da sola, senza soglia.
                && ($righe[$deposito] ?? null) === ['3', ''];
        });

        check('una sede ripetuta o sconosciuta ferma la scheda dell\'articolo prima di scrivere', function () use ($centro, $rifiutoArticolo) {
            $doppia = [
                ['location_id' => (string) $centro, 'stock' => '1', 'min_stock' => ''],
                ['location_id' => (string) $centro, 'stock' => '2', 'min_stock' => ''],
            ];
            $ignota = [['location_id' => '999999', 'stock' => '1', 'min_stock' => '']];
            $messaggio = '';

            try {
                ProductModelResource::assertLocationRows(['locations' => $doppia], false);
            } catch (UserError $errore) {
                $messaggio = $errore->getMessage();
            }

            return $rifiutoArticolo(['locations' => $doppia], false) === 'stock.location_duplicate'
                && str_contains($messaggio, 'Prova Centro Sedi')
                && $rifiutoArticolo(['locations' => $ignota], false) === 'stock.location_unknown'
                // Con le varianti le righe stanno nel JSON di ogni riga della
                // griglia, e una finestra mai aperta non ha niente da dire.
                && $rifiutoArticolo(['products' => ['0' => ['id' => '1', 'locations' => json_encode($doppia)]]], true) === 'stock.location_duplicate'
                && $rifiutoArticolo(['products' => ['0' => ['id' => '1', 'locations' => json_encode($ignota)]]], true) === 'stock.location_unknown'
                && $rifiutoArticolo(['products' => ['0' => ['id' => '1', 'locations' => '']]], true) === '';
        });

        check('la scheda dell\'opzione rifiuta le stesse righe', function () use ($prodotto, $centro, $rifiutoOpzione) {
            return $rifiutoOpzione($prodotto, [
                ['location_id' => (string) $centro, 'stock' => '1', 'min_stock' => ''],
                ['location_id' => (string) $centro, 'stock' => '2', 'min_stock' => ''],
            ]) === 'stock.location_duplicate'
                && $rifiutoOpzione($prodotto, [['location_id' => '999999', 'stock' => '1', 'min_stock' => '']]) === 'stock.location_unknown'
                && $rifiutoOpzione($prodotto, [['location_id' => (string) $centro, 'stock' => '1', 'min_stock' => '']]) === '';
        });

        check('la scheda dell\'opzione salva le righe per sede come quella dell\'articolo', function () use ($prodotto, $centro, $deposito, $principale, $pezzi, $soglie, $movimenti) {
            $_POST['locations'] = [
                ['location_id' => (string) $centro, 'stock' => '10', 'min_stock' => '1'],
                ['location_id' => (string) $deposito, 'stock' => '', 'min_stock' => ''],
            ];

            try {
                ProductResource::afterUpdate($prodotto, (object) [], []);
            } finally {
                unset($_POST['locations']);
            }

            $attesi = [$principale => 2.0, $centro => 10.0, $deposito => 3.0];
            ksort($attesi);
            $righe = [];

            foreach ((array) (ProductResource::mutateFormValues(['id' => $prodotto], 'edit')['locations'] ?? []) as $riga) {
                $righe[(int) $riga['location_id']] = [$riga['stock'], $riga['min_stock']];
            }

            return $pezzi($prodotto) === $attesi
                && $soglie($prodotto) === [$centro => 1.0]
                && count($movimenti($prodotto)) === 5
                && ($movimenti($prodotto)[4] ?? null) === [Reasons::DEFAULT, $centro]
                && ($righe[$centro] ?? null) === ['10', '1']
                && ($righe[$deposito][0] ?? null) === '3';
        });

        check('ad avvisi spenti le righe muovono i pezzi e le soglie salvate restano', function () use ($nuovo, $prodotto, $centro, $deposito, $principale, $pezzi, $soglie, $accendi) {
            $accendi(false);

            try {
                // Senza la colonna della scorta minima la riga arriva senza.
                ProductModelResource::saveExtras($nuovo, [
                    'has_variants' => 'false',
                    'locations' => [['location_id' => (string) $deposito, 'stock' => '4']],
                ], 'SED-1');
            } finally {
                $accendi(true);
            }

            $attesi = [$principale => 2.0, $centro => 10.0, $deposito => 4.0];
            ksort($attesi);

            return $pezzi($prodotto) === $attesi
                && $soglie($prodotto) === [$centro => 1.0];
        });

        check('una giacenza per sede sotto zero ferma le due schede prima di scrivere', function () use ($nuovo, $prodotto, $centro, $deposito, $pezzi, $movimenti, $rifiutoArticolo, $rifiutoOpzione, $accendi) {
            $sotto = [['location_id' => (string) $centro, 'stock' => '-3', 'min_stock' => '']];
            $primaPezzi = $pezzi($prodotto);
            $primaMovimenti = count($movimenti($prodotto));

            $rifiuti = [
                $rifiutoArticolo(['locations' => $sotto], false, $nuovo),
                $rifiutoArticolo(['products' => ['0' => ['id' => (string) $prodotto, 'locations' => json_encode($sotto)]]], true, $nuovo),
                $rifiutoOpzione($prodotto, $sotto),
                // Su una scheda che nasce non c'è niente con cui confrontare.
                $rifiutoArticolo(['locations' => $sotto], false),
            ];

            // Vale anche con la vendita senza giacenza accesa, come la casella
            // della sede sola: lì il numero scritto diventerebbe una rettifica
            // sotto zero.
            $accendi(true, true);

            try {
                $conArretrati = $rifiutoArticolo(['locations' => $sotto], false, $nuovo);
            } finally {
                $accendi(true);
            }

            return $rifiuti === array_fill(0, 4, 'product.stock_negative')
                && $conArretrati === 'product.stock_negative'
                && $pezzi($prodotto) === $primaPezzi
                && count($movimenti($prodotto)) === $primaMovimenti;
        });

        check('una sede già sotto zero per gli arretrati, riscritta com\'era, non ferma la scheda', function () use ($articolo, $centro, $deposito, $pezzi, $rifiutoArticolo, $rifiutoOpzione, $accendi) {
            $modello = $articolo('SED-2');
            $opzione = (int) (ProductModelResource::products($modello)[0]['id'] ?? 0);
            Product::update(['allow_backorder' => 'true'], $opzione);
            $accendi(true, true);

            try {
                // Una vendita in arretrato: due pezzi usciti da una sede vuota.
                Stock::apply([
                    'product_id' => $opzione,
                    'location_id' => $centro,
                    'quantity' => -2,
                    'reason' => Reasons::DEFAULT,
                    'note' => 'Prova',
                ]);
            } finally {
                $accendi(true);
            }

            $comEra = [
                ['location_id' => (string) $centro, 'stock' => '-2', 'min_stock' => ''],
                ['location_id' => (string) $deposito, 'stock' => '4', 'min_stock' => ''],
            ];
            $piuSotto = [['location_id' => (string) $centro, 'stock' => '-3', 'min_stock' => '']];

            return $opzione > 0
                && $pezzi($opzione) === [$centro => -2.0]
                && $rifiutoArticolo(['locations' => $comEra], false, $modello) === ''
                && $rifiutoOpzione($opzione, $comEra) === ''
                && $rifiutoArticolo(['locations' => $piuSotto], false, $modello) === 'product.stock_negative'
                && $rifiutoOpzione($opzione, $piuSotto) === 'product.stock_negative';
        });

        $colore = $attributo('Prova Colore Sedi', 'variant', 'color', ['Blu', 'Rosso']);
        ProductModelResource::forgetCatalogCache();
        $griglia = $articolo('SED-3');
        $spunte = [
            'has_variants' => 'true',
            'axes_order' => (string) $colore['id'],
            'option_'.$colore['id'] => array_map('strval', $colore['values']),
        ];
        $blu = (string) $colore['values'][0];
        $rosso = (string) $colore['values'][1];
        $idBlu = 0;
        $idRosso = 0;

        check('nella griglia il JSON della finestra «Giacenza» carica pezzi e soglie per sede della sua riga', function () use ($griglia, $spunte, $blu, $rosso, $centro, $deposito, $pezzi, $soglie, $movimenti, &$idBlu, &$idRosso) {
            ProductModelResource::saveExtras($griglia, $spunte + [
                'products' => [
                    // Finestra mai aperta: il campo nascosto è vuoto.
                    $blu => ['price' => '10,00', 'locations' => ''],
                    $rosso => ['price' => '10,00', 'locations' => json_encode([
                        ['location_id' => (string) $centro, 'stock' => '6', 'min_stock' => '1'],
                        ['location_id' => (string) $deposito, 'stock' => '2', 'min_stock' => '5'],
                    ])],
                ],
            ], 'SED-3', [], true);

            foreach (ProductModelResource::products($griglia) as $product) {
                if (str_contains((string) ($product['name'] ?? ''), 'Rosso')) {
                    $idRosso = (int) $product['id'];
                } else {
                    $idBlu = (int) $product['id'];
                }
            }

            $causali = $movimenti($idRosso);

            return $idBlu > 0 && $idRosso > 0
                && count(ProductModelResource::products($griglia)) === 2
                && $pezzi($idRosso) === [$centro => 6.0, $deposito => 2.0]
                && $soglie($idRosso) === [$centro => 1.0, $deposito => 5.0]
                && count($causali) === 2
                && array_unique(array_column($causali, 0)) === ['initial_stock']
                && $pezzi($idBlu) === []
                && $soglie($idBlu) === []
                && $movimenti($idBlu) === []
                && Alerts::openRow($idRosso, $deposito) !== []
                && Alerts::openRow($idRosso, $centro) === [];
        });

        check('riaperta la griglia, ogni riga rettifica le sue sedi e una sede tolta tiene i pezzi', function () use ($griglia, $spunte, $centro, $deposito, $pezzi, $soglie, $movimenti, &$idBlu, &$idRosso) {
            ProductModelResource::saveExtras($griglia, $spunte + [
                'products' => [
                    '0' => ['id' => (string) $idBlu, 'locations' => json_encode([
                        ['location_id' => (string) $centro, 'stock' => '3', 'min_stock' => ''],
                    ])],
                    '1' => ['id' => (string) $idRosso, 'locations' => json_encode([
                        ['location_id' => (string) $centro, 'stock' => '6', 'min_stock' => '1'],
                    ])],
                ],
            ], 'SED-3');

            return $pezzi($idBlu) === [$centro => 3.0]
                && $movimenti($idBlu) === [[Reasons::DEFAULT, $centro]]
                && $pezzi($idRosso) === [$centro => 6.0, $deposito => 2.0]
                && $soglie($idRosso) === [$centro => 1.0]
                && count($movimenti($idRosso)) === 2
                && Alerts::openRow($idRosso, $deposito) === [];
        });

        check('la griglia riaperta porta il JSON delle righe per sede e il riassunto accanto al bottone', function () use ($griglia, $centro, $deposito, &$idRosso) {
            $valori = ProductModelResource::mutateFormValues([
                'id' => $griglia,
                'products' => [['id' => (string) $idRosso]],
            ], 'edit');
            $riga = $valori['products'][0] ?? [];
            $perSede = [];

            foreach ((array) json_decode((string) ($riga['locations'] ?? ''), true) as $sede) {
                $perSede[(int) $sede['location_id']] = [(float) $sede['stock'], (float) $sede['min_stock']];
            }

            $bottone = (string) ($riga['stock_button'] ?? '');

            return ($perSede[$centro] ?? null) === [6.0, 1.0]
                && ($perSede[$deposito] ?? null) === [2.0, 0.0]
                && !array_key_exists('min_stock', $riga)
                && str_contains($bottone, 'Prova Centro Sedi 6')
                && str_contains($bottone, 'Prova Deposito Sedi 2');
        });

        check('dopo un errore il riassunto accanto al bottone dice le righe tornate dal form', function () use ($centro, $deposito, &$idRosso) {
            // Il core ridà al form quello che è arrivato, senza `id`: il
            // JSON scritto nella finestra resta, e il riassunto lo segue.
            $valori = ProductModelResource::mutateFormValues([
                'products' => [[
                    'id' => (string) $idRosso,
                    'locations' => json_encode([
                        ['location_id' => $centro, 'stock' => 20, 'min_stock' => ''],
                        ['location_id' => $deposito, 'stock' => -3, 'min_stock' => ''],
                    ]),
                ]],
            ], 'edit');
            $riga = $valori['products'][0] ?? [];
            $bottone = (string) ($riga['stock_button'] ?? '');

            return str_contains($bottone, 'Prova Centro Sedi 20')
                && str_contains($bottone, 'Prova Deposito Sedi -3')
                && str_contains((string) ($riga['locations'] ?? ''), '"stock":-3');
        });

        check('in creazione con più sedi la colonna «Giacenza» non carica niente: i pezzi arrivano dalla finestra', function () use ($articolo, $spunte, $blu, $rosso, $principale, $pezzi, $movimenti) {
            $modello = $articolo('SED-6');
            // La colonna è il totale da leggere; un numero arrivato lo stesso
            // non si contende la sede principale con la finestra.
            ProductModelResource::saveExtras($modello, $spunte + [
                'products' => [
                    $blu => ['price' => '10,00', 'stock' => '10', 'locations' => json_encode([
                        ['location_id' => (string) $principale, 'stock' => '5', 'min_stock' => ''],
                    ])],
                    $rosso => ['price' => '10,00', 'stock' => '7', 'locations' => ''],
                ],
            ], 'SED-6', [], true);

            $ids = [];

            foreach (ProductModelResource::products($modello) as $product) {
                $ids[str_contains((string) ($product['name'] ?? ''), 'Rosso') ? 'rosso' : 'blu'] = (int) $product['id'];
            }

            return count($ids) === 2
                && $pezzi($ids['blu']) === [$principale => 5.0]
                && $movimenti($ids['blu']) === [['initial_stock', $principale]]
                && $pezzi($ids['rosso']) === []
                && $movimenti($ids['rosso']) === [];
        });

        $nord = $fornitore('Prova Filati Sedi');

        check('eliminare l\'articolo porta via le soglie per sede e i fornitori delle sue opzioni', function () use ($articolo, $centro, $deposito, $nord, $soglie, $legami) {
            $modello = $articolo('SED-4');
            // Solo soglie: senza pezzi non c'è nessun movimento, e l'articolo
            // si può eliminare.
            ProductModelResource::saveExtras($modello, [
                'has_variants' => 'false',
                'locations' => [
                    ['location_id' => (string) $centro, 'stock' => '', 'min_stock' => '3'],
                    ['location_id' => (string) $deposito, 'stock' => '', 'min_stock' => '1'],
                ],
            ], 'SED-4', [], true);
            $prodotto = (int) (ProductModelResource::products($modello)[0]['id'] ?? 0);
            ProductSuppliers::sync($prodotto, [['supplier_id' => $nord, 'supplier_sku' => 'N-4', 'cost' => '2']]);

            $primaSoglie = $soglie($prodotto);
            $primaLegami = $legami(ProductSupplier::class, 'product_id', $prodotto);

            ProductModelResource::deleteRecord($modello);

            return $prodotto > 0
                && $primaSoglie === [$centro => 3.0, $deposito => 1.0]
                && $primaLegami === 1
                && $soglie($prodotto) === []
                && $legami(ProductSupplier::class, 'product_id', $prodotto) === 0
                && Alerts::openRows($prodotto) === [];
        });

        check('eliminare un\'opzione porta via le sue soglie per sede e i suoi fornitori', function () use ($articolo, $spunte, $blu, $rosso, $centro, $deposito, $nord, $soglie, $legami) {
            $modello = $articolo('SED-5');
            ProductModelResource::saveExtras($modello, $spunte + [
                'products' => [
                    $blu => ['price' => '10,00', 'locations' => ''],
                    $rosso => ['price' => '10,00', 'locations' => ''],
                ],
            ], 'SED-5', [], true);
            $ids = array_map(static fn (array $product): int => (int) $product['id'], ProductModelResource::products($modello));
            Thresholds::save($ids[0], [$centro => 2.0, $deposito => 1.0]);
            Thresholds::save($ids[1], [$centro => 4.0]);
            ProductSuppliers::sync($ids[0], [['supplier_id' => $nord, 'cost' => '2']]);
            ProductSuppliers::sync($ids[1], [['supplier_id' => $nord, 'cost' => '3']]);

            ProductResource::deleteRecord($ids[0]);

            return count($ids) === 2
                && $soglie($ids[0]) === []
                && $legami(ProductSupplier::class, 'product_id', $ids[0]) === 0
                && count(ProductModelResource::products($modello)) === 1
                // L'altra opzione non ne sa niente.
                && $soglie($ids[1]) === [$centro => 4.0]
                && $legami(ProductSupplier::class, 'product_id', $ids[1]) === 1;
        });

        throw new Annulla();
    });
} catch (Annulla) {
} finally {
    $features->setValue(null, $primaFeatures);
    Locations::reset();
    ProductModelResource::forgetCatalogCache();
}

check('dopo l\'annullamento il catalogo è come prima', fn () => $tabelle() === $prima);

summary();
