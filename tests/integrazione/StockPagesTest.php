<?php
/** php tests/integrazione/StockPagesTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\App\Models\Config\SocietyLocation;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelCategory;
use Wonder\Plugin\Gestionale\Models\Locations\Location;
use Wonder\Plugin\Gestionale\Models\Stock\Stock as StockRow;
use Wonder\Plugin\Gestionale\Models\Stock\StockAlert;
use Wonder\Plugin\Gestionale\Models\Stock\StockReservation;
use Wonder\Plugin\Gestionale\Resources\Stock\StockLevelResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\Skeleton;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Plugin\Gestionale\Support\Stock\LevelsSql;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

/** Una riga di `gst_products` letta con le colonne calcolate date. */
$leggi = static function (int $productId, string $columns): array {
    $riga = Product::find(['id' => $productId], 1, null, null, '`gst_products`.`id`, '.$columns);

    return is_array($riga) ? $riga : [];
};

/** Vero se la versione esce con questa condizione, come la userebbe un filtro. */
$trovata = static function (int $productId, string $condition): bool {
    if ($condition === '') {
        return false;
    }

    $riga = Product::find('WHERE `gst_products`.`id` = '.$productId." AND `gst_products`.`deleted` = 'false' AND ".$condition, 1);

    return is_array($riga) && (int) ($riga['id'] ?? 0) === $productId;
};

/** Una sede di prova: la riga del core e quella del gestionale. */
$nuovaSede = static function (string $nome, int $posizione, string $merce): int {
    $sede = SocietyLocation::create([
        'label' => $nome,
        'slug' => Slug::make($nome.'-'.uniqid()),
        'position' => $posizione,
        'visible' => 'true',
    ]);
    $coreId = (int) ($sede->insert_id ?? 0);
    $riga = Location::create(['society_location_id' => $coreId, 'has_stock' => $merce, 'active' => 'true']);

    return (int) ($riga->insert_id ?? 0);
};

$features = new ReflectionProperty(Gestionale::class, 'features');
$primaFeatures = $features->getValue();

try {
    Transaction::run(static function () use ($leggi, $trovata, $nuovaSede, $features): void {
        $modello = ProductModel::create([
            'code' => Code::make(ProductModel::class, Codes::MODEL),
            'name' => 'Prova giacenze',
            'slug' => Slug::make('prova-giacenze-'.uniqid()),
            'sku' => 'TST-LVL',
            'unit' => 'pz',
            'type' => 'simple',
            'visible' => 'true',
            'position' => 1,
        ]);
        $modelId = (int) ($modello->insert_id ?? 0);
        $productId = Skeleton::forModel($modelId, 'Prova giacenze', 'TST-LVL')['product_id'];

        // Posizioni alte: le sedi di prova vanno in fondo, dopo quelle vere.
        $centro = $nuovaSede('Prova Centro', 9001, 'true');
        $deposito = $nuovaSede('Prova Deposito', 9002, 'false');
        $ufficio = $nuovaSede('Prova Ufficio', 9003, 'false');

        // Due righe nella stessa sede (fornitori diversi), una nel deposito
        // che non gestisce merce, una cancellata che non conta.
        foreach ([
            [$centro, 0, 0, '5.000', 'false'],
            [$centro, 0, 1, '2.500', 'false'],
            [$deposito, 0, 0, '3.000', 'false'],
            [$centro, 7, 0, '100.000', 'true'],
        ] as [$sede, $lotto, $fornitore, $quantita, $cancellata]) {
            $riga = StockRow::create([
                'product_id' => $productId,
                'location_id' => $sede,
                'batch_id' => $lotto,
                'supplier_id' => $fornitore,
                'quantity' => $quantita,
            ]);

            // `create` e `update` non scrivono `deleted`: si passa dalla query.
            if ($cancellata === 'true') {
                StockRow::query()->Update(StockRow::$table, ['deleted' => 'true'], 'id', (int) ($riga->insert_id ?? 0));
            }
        }

        // Impegnati: senza scadenza e con scadenza futura sì; rilasciata e
        // scaduta no.
        foreach ([
            ['1.000', null, null],
            ['0.500', date('Y-m-d H:i:s', strtotime('+1 hour')), null],
            ['4.000', null, date('Y-m-d H:i:s', strtotime('-1 hour'))],
            ['8.000', date('Y-m-d H:i:s', strtotime('-1 hour')), null],
        ] as [$quantita, $scade, $rilasciata]) {
            StockReservation::create(array_filter([
                'product_id' => $productId,
                'location_id' => $centro,
                'quantity' => $quantita,
                'expires_at' => $scade,
                'released_at' => $rilasciata,
            ], static fn ($valore): bool => $valore !== null));
        }

        $adesso = date('Y-m-d H:i:s');

        check('giacenza, impegnati e disponibili come Levels', function () use ($leggi, $productId, $adesso) {
            $riga = $leggi($productId, LevelsSql::quantity().' AS q, '.LevelsSql::reserved($adesso).' AS r, '.LevelsSql::available($adesso).' AS a');
            $livelli = Levels::of($productId);

            return $livelli === ['quantity' => 10.5, 'reserved' => 1.5, 'available' => 9.0]
                && (float) ($riga['q'] ?? -1) === $livelli['quantity']
                && (float) ($riga['r'] ?? -1) === $livelli['reserved']
                && (float) ($riga['a'] ?? -1) === $livelli['available'];
        });

        check('sede per sede, le colonne sommano al totale', function () use ($leggi, $productId, $centro, $deposito, $ufficio) {
            $riga = $leggi($productId, LevelsSql::quantityAt($centro).' AS c, '.LevelsSql::quantityAt($deposito).' AS d, '
                .LevelsSql::quantityAt($ufficio).' AS u, '.LevelsSql::quantity().' AS t');

            return (float) $riga['c'] === 7.5
                && (float) $riga['d'] === 3.0
                && (float) $riga['u'] === 0.0
                && (float) $riga['c'] + (float) $riga['d'] + (float) $riga['u'] === (float) $riga['t'];
        });

        check('un avviso risolto non è sotto scorta, uno aperto sì', function () use ($leggi, $productId) {
            StockAlert::create(['product_id' => $productId, 'threshold' => '20.000', 'resolved_at' => date('Y-m-d H:i:s')]);
            $prima = (int) ($leggi($productId, LevelsSql::openAlert().' AS al')['al'] ?? -1);

            StockAlert::create(['product_id' => $productId, 'threshold' => '20.000']);
            $dopo = (int) ($leggi($productId, LevelsSql::openAlert().' AS al')['al'] ?? -1);

            return $prima === 0 && $dopo === 1;
        });

        check('il nome dell\'articolo arriva dalla query', fn () =>
            strtolower((string) ($leggi($productId, LevelsSql::modelName().' AS n')['n'] ?? '')) === 'prova giacenze'
        );

        $features->setValue(null, ['multi_location' => true, 'low_stock_alerts' => true, 'orders' => true]);
        Locations::reset();

        check('le colonne: la sede che gestisce merce e quella che ha pezzi, non quella vuota', function () use ($centro, $deposito, $ufficio) {
            $sedi = array_column(Locations::shown(), 'label', 'id');

            return ($sedi[$centro] ?? '') === 'Prova Centro'
                && ($sedi[$deposito] ?? '') === 'Prova Deposito'
                && !isset($sedi[$ufficio]);
        });

        check('la select completa gira sul database', function () use ($leggi, $productId, $centro) {
            $riga = $leggi($productId, StockLevelResource::select());

            return (float) ($riga['stock_quantity'] ?? -1) === 10.5
                && (float) ($riga['stock_loc_'.$centro] ?? -1) === 7.5
                && (float) ($riga['stock_available'] ?? -1) === 9.0
                && (int) ($riga['stock_alert'] ?? -1) === 1;
        });

        $marchio = Brand::create([
            'code' => Code::make(Brand::class, Codes::BRAND),
            'name' => 'Prova Marchio Giacenze',
            'slug' => Slug::unique('Prova Marchio Giacenze', Brand::class),
            'position' => 1,
            'visible' => 'true',
        ]);
        $marchioId = (int) ($marchio->insert_id ?? 0);
        ProductModel::update(['brand_id' => $marchioId], $modelId);

        check('il filtro Marchio trova la versione del suo marchio e basta', fn () =>
            $trovata($productId, StockLevelResource::brandCondition([(string) $marchioId]))
            && !$trovata($productId, StockLevelResource::brandCondition([(string) ($marchioId + 1000)]))
        );

        $categoria = static function (string $nome, int $padre): int {
            $riga = Category::create([
                'code' => Code::make(Category::class, Codes::CATEGORY),
                'name' => $nome,
                'slug' => Slug::unique($nome, Category::class),
                'parent_id' => $padre,
                'position' => 900,
                'visible' => 'true',
            ]);

            return (int) ($riga->insert_id ?? 0);
        };
        $padre = $categoria('Prova Padre Giacenze', 0);
        $figlia = $categoria('Prova Figlia Giacenze', $padre);
        $altra = $categoria('Prova Altra Giacenze', 0);
        ProductModelCategory::create(['product_model_id' => $modelId, 'category_id' => $figlia, 'is_main' => 'true', 'position' => 1]);

        check('il filtro Categoria trova anche gli articoli delle sottocategorie', fn () =>
            $trovata($productId, StockLevelResource::categoryCondition([(string) $padre]))
            && $trovata($productId, StockLevelResource::categoryCondition([(string) $figlia]))
            && !$trovata($productId, StockLevelResource::categoryCondition([(string) $altra]))
        );

        throw new Annulla();
    });
} catch (Annulla) {
} finally {
    $features->setValue(null, $primaFeatures);
    Locations::reset();
}

check('dopo l\'annullamento non resta niente', function () {
    $riga = ProductModel::find(['sku' => 'TST-LVL', 'deleted' => 'false'], 1);
    $sede = SocietyLocation::find(['label' => 'Prova Centro', 'deleted' => 'false'], 1);

    return (!is_array($riga) || $riga === []) && (!is_array($sede) || $sede === []);
});

summary();
