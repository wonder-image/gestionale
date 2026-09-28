<?php
/** php tests/integrazione/StockLevelsTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\App\Models\Config\SocietyLocation;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Locations\Location;
use Wonder\Plugin\Gestionale\Models\Stock\Stock as StockRow;
use Wonder\Plugin\Gestionale\Models\Stock\StockReservation;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\Skeleton;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

check('la sede principale del magazzino esiste', fn () => Locations::mainId() > 0);

$locationId = Locations::mainId();

try {
    Transaction::run(static function () use ($locationId): void {
        // Un articolo di prova, cancellato con la transazione. Modello e
        // variante servono davvero: le colonne del prodotto sono legate alle
        // loro tabelle, e uno zero non passerebbe.
        $modello = ProductModel::create([
            'code' => Code::make(ProductModel::class, Codes::MODEL),
            'name' => 'Prova livelli',
            'slug' => Slug::make('prova-livelli-'.uniqid()),
            'sku' => 'TST-LEV',
            'unit' => 'pz',
            'type' => 'simple',
            'visible' => 'true',
            'position' => 1,
        ]);
        $scheletro = Skeleton::forModel((int) ($modello->insert_id ?? 0), 'Prova livelli', 'TST-LEV');
        $productId = $scheletro['product_id'];

        check('l\'articolo di prova è nato con la sua versione', fn () => $productId > 0);

        check('senza righe di giacenza i livelli sono a zero', fn () =>
            Levels::of($productId) === ['quantity' => 0.0, 'reserved' => 0.0, 'available' => 0.0]
        );

        check('e per sede non c\'è nessuna riga', fn () => Levels::byLocation($productId) === []);

        StockRow::create([
            'product_id' => $productId,
            'location_id' => $locationId,
            'batch_id' => 0,
            'supplier_id' => 0,
            'quantity' => '12.000',
        ]);

        check('la giacenza è la somma delle righe del prodotto', fn () =>
            Levels::of($productId)['quantity'] === 12.0
        );

        StockReservation::create([
            'product_id' => $productId,
            'location_id' => $locationId,
            'quantity' => '2.000',
            'expires_at' => date('Y-m-d H:i:s', strtotime('+1 hour')),
        ]);

        check('una prenotazione attiva abbassa il disponibile ma non la giacenza', function () use ($productId) {
            $livelli = Levels::of($productId);

            return $livelli['quantity'] === 12.0
                && $livelli['reserved'] === 2.0
                && $livelli['available'] === 10.0;
        });

        StockReservation::create([
            'product_id' => $productId,
            'location_id' => $locationId,
            'quantity' => '5.000',
            'expires_at' => date('Y-m-d H:i:s', strtotime('-1 hour')),
        ]);

        check('una prenotazione scaduta non toglie niente', fn () =>
            Levels::of($productId)['available'] === 10.0
        );

        check('i livelli si leggono anche sede per sede', function () use ($productId, $locationId) {
            $perSede = Levels::byLocation($productId);

            return array_keys($perSede) === [$locationId]
                && $perSede[$locationId]['quantity'] === 12.0
                && $perSede[$locationId]['reserved'] === 2.0
                && $perSede[$locationId]['available'] === 10.0;
        });

        // Una seconda sede, con i suoi pezzi: la riga del core e quella del
        // gestionale, cancellate con la transazione.
        $sede = SocietyLocation::create([
            'label' => 'Prova Deposito livelli',
            'slug' => Slug::make('prova-deposito-livelli-'.uniqid()),
            'position' => 9001,
            'visible' => 'true',
        ]);
        $deposito = Location::create([
            'society_location_id' => (int) ($sede->insert_id ?? 0),
            'has_stock' => 'true',
            'active' => 'true',
        ]);
        $depositoId = (int) ($deposito->insert_id ?? 0);

        StockRow::create([
            'product_id' => $productId,
            'location_id' => $depositoId,
            'batch_id' => 0,
            'supplier_id' => 0,
            'quantity' => '3.000',
        ]);

        check('una seconda sede ha la sua riga, e il totale le somma', function () use ($productId, $locationId, $depositoId) {
            $perSede = Levels::byLocation($productId);

            return $depositoId > 0
                && count($perSede) === 2
                && $perSede[$locationId]['quantity'] === 12.0
                && $perSede[$depositoId] === ['quantity' => 3.0, 'reserved' => 0.0, 'available' => 3.0]
                && Levels::of($productId)['quantity'] === 15.0
                && Levels::of($productId)['available'] === 13.0;
        });

        check('più prodotti si leggono per sede in un colpo solo', function () use ($productId, $depositoId) {
            $livelli = Levels::byLocationForProducts([$productId, 0]);

            return ($livelli[$productId][$depositoId]['quantity'] ?? null) === 3.0
                && array_key_exists(0, $livelli)
                && $livelli[0] === [];
        });

        check('più prodotti si leggono in un colpo solo', function () use ($productId) {
            $livelli = Levels::forProducts([$productId, 0]);

            return ($livelli[$productId]['available'] ?? null) === 13.0
                && array_key_exists(0, $livelli)
                && $livelli[0]['quantity'] === 0.0;
        });

        throw new Annulla();
    });
} catch (Annulla) {
}

check('dopo l\'annullamento non resta niente dell\'articolo di prova', function () {
    $riga = ProductModel::find(['sku' => 'TST-LEV', 'deleted' => 'false'], 1);
    $versione = Product::find(['sku' => 'TST-LEV', 'deleted' => 'false'], 1);

    return (!is_array($riga) || $riga === []) && (!is_array($versione) || $versione === []);
});

summary();
