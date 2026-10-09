<?php
/** php tests/integrazione/PickupPointsTest.php */

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';
require __DIR__.'/supporto/spedizioni.php';

use Wonder\Plugin\Gestionale\Models\Locations\Location;
use Wonder\Plugin\Gestionale\Support\Locations\PickupPoints;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

function prova(callable $corpo): mixed
{
    $esito = null;

    try {
        Transaction::run(static function () use ($corpo, &$esito): void {
            $esito = $corpo();

            throw new Annulla();
        });
    } catch (Annulla) {
    }

    return $esito;
}

/** @return list<int> */
function idDisponibili(): array
{
    return array_column(PickupPoints::all(), 'id');
}

check('una sede di ritiro attiva compare, con nome e indirizzo', fn () => prova(static function (): bool {
    $sede = sede(true, true, 'true', ['street' => 'Via Roma', 'number' => '3', 'cap' => '20100', 'city' => 'Milano']);
    $trovate = array_values(array_filter(PickupPoints::all(), static fn (array $punto): bool => $punto['id'] === $sede));

    return count($trovate) === 1
        && $trovate[0]['name'] === 'Prova ritiro'
        && $trovate[0]['address'] === 'Via Roma 3, 20100 Milano'
        && (int) PickupPoints::find($sede)['location']['id'] === $sede;
}));

check('una sede non di ritiro, spenta o eliminata non compare', fn () => prova(static function (): bool {
    $nonRitiro = sede(false);
    $spenta = sede(true, true, 'false');
    $eliminata = sede();
    sqlModify(Location::$table, ['deleted' => 'true'], 'id', $eliminata);

    return array_intersect([$nonRitiro, $spenta, $eliminata], idDisponibili()) === []
        && PickupPoints::find($nonRitiro) === null
        && PickupPoints::find($spenta) === null
        && PickupPoints::find($eliminata) === null
        && PickupPoints::find(0) === null;
}));

check('una sede di ritiro fuori orario compare lo stesso', fn () => prova(static function (): bool {
    $chiusa = sede(true, false);

    return in_array($chiusa, idDisponibili(), true) && PickupPoints::find($chiusa) !== null;
}));

summary();
