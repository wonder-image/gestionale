<?php
/** php tests/integrazione/ShippingZonesTest.php */

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';

use Wonder\Plugin\Gestionale\Models\Shipping\ShippingZone;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingZoneArea;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Shipping\ShippingZones;
use Wonder\Sql\Transaction;

/** Serve a buttare via tutto quello che la prova scrive. */
final class Annulla extends RuntimeException {}

/** Fa girare il corpo dentro una transazione e poi la annulla. */
function prova(callable $corpo): mixed
{
    $esito = null;

    try {
        Transaction::run(static function () use ($corpo, &$esito): void {
            // Il sito di prova può avere i dati demo: i test partono da zone vuote.
            \Wonder\Plugin\Gestionale\Seeding\ShippingDemo::clear();
            $esito = $corpo();

            throw new Annulla();
        });
    } catch (Annulla) {
        // Voluto: i dati della prova non restano.
    }

    return $esito;
}

/** Una zona con le sue aree: ogni area è [paese, provincia]. */
function zonaDiProva(string $nome, int $posizione, array $aree): int
{
    $riga = ShippingZone::create([
        'code' => Code::make(ShippingZone::class, Codes::SHIPPING_ZONE),
        'name' => $nome,
        'position' => $posizione,
    ]);
    $id = (int) ($riga->insert_id ?? 0);

    foreach ($aree as [$paese, $provincia]) {
        ShippingZoneArea::create(['shipping_zone_id' => $id, 'country' => $paese, 'province' => $provincia]);
    }

    return $id;
}

check('un paese senza zona non ha risposta', fn () => prova(static function (): bool {
    zonaDiProva('Italia', 1, [['IT', '']]);

    return ShippingZones::resolve('ZZ', '') === null;
}));

check('il paese porta alla sua zona', fn () => prova(static function (): bool {
    $it = zonaDiProva('Italia', 1, [['IT', '']]);

    return ShippingZones::resolve('IT', 'MI') === $it
        && ShippingZones::resolve('IT', '') === $it;
}));

check('la provincia con zona propria batte il solo paese', fn () => prova(static function (): bool {
    $it = zonaDiProva('Italia', 1, [['IT', '']]);
    $sardegna = zonaDiProva('Sardegna', 2, [['IT', 'CA'], ['IT', 'SS']]);

    return ShippingZones::resolve('IT', 'CA') === $sardegna
        && ShippingZones::resolve('IT', 'MI') === $it;
}));

check('la provincia senza zona propria ricade sul paese', fn () => prova(static function (): bool {
    $it = zonaDiProva('Italia', 1, [['IT', '']]);
    zonaDiProva('Sardegna', 2, [['IT', 'CA']]);

    return ShippingZones::resolve('IT', 'RM') === $it;
}));

check('la sigla in minuscolo e con spazi vale come in maiuscolo', fn () => prova(static function (): bool {
    $sardegna = zonaDiProva('Sardegna', 2, [['IT', 'CA']]);

    return ShippingZones::resolve(' it ', ' ca ') === $sardegna;
}));

check('a pari specificità vince la position minore', fn () => prova(static function (): bool {
    $seconda = zonaDiProva('Seconda', 5, [['IT', '']]);
    $prima = zonaDiProva('Prima', 2, [['IT', '']]);

    return ShippingZones::resolve('IT', 'MI') === $prima && $prima !== $seconda;
}));

check('overlaps segnala le altre zone con un\'area uguale', fn () => prova(static function (): bool {
    $a = zonaDiProva('Zona A', 1, [['IT', ''], ['FR', '']]);
    zonaDiProva('Zona B', 2, [['FR', '']]);
    zonaDiProva('Zona C', 3, [['DE', '']]);

    return ShippingZones::overlaps($a) === ['Zona B'];
}));

check('overlaps è vuoto se nessuna area coincide', fn () => prova(static function (): bool {
    $a = zonaDiProva('Zona A', 1, [['IT', '']]);
    zonaDiProva('Zona C', 3, [['IT', 'MI']]);

    return ShippingZones::overlaps($a) === [];
}));

check('una zona eliminata è ignorata', fn () => prova(static function (): bool {
    $it = zonaDiProva('Italia', 2, [['IT', '']]);
    $vecchia = zonaDiProva('Vecchia', 1, [['IT', '']]);
    ShippingZone::query()->Update(ShippingZone::$table, ['deleted' => 'true'], 'id', $vecchia);

    return ShippingZones::resolve('IT', 'MI') === $it
        && ShippingZones::overlaps($it) === [];
}));

summary();
