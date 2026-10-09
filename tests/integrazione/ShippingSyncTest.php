<?php
/** php tests/integrazione/ShippingSyncTest.php */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\App\Support\TableSync;
use Wonder\Plugin\Gestionale\Models\Shipping\Carrier;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingMethod;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingRate;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingRateBracket;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingZone;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingZoneArea;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Support\Shipping\ShippingSync;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

const TABELLE = [
    'gst_carriers',
    'gst_shipping_methods',
    'gst_shipping_zones',
    'gst_shipping_zone_areas',
    'gst_shipping_rates',
    'gst_shipping_rate_brackets',
];

/** Fa girare il corpo dentro una transazione e poi la annulla. */
function prova(callable $corpo): mixed
{
    $esito = null;

    try {
        Transaction::run(static function () use ($corpo, &$esito): void {
            $esito = $corpo();

            throw new Annulla();
        });
    } catch (Annulla) {
        // Voluto: i dati della prova non restano.
    } finally {
        TableSync::resetCache();
    }

    return $esito;
}

/** Accende o spegne l'interruttore e fa riscoprire le tabelle. */
function interruttore(string $valore): void
{
    sqlModify(Setting::$table, ['shipping_sync' => $valore], 'id', '1');
    TableSync::resetCache();
}

/** Le tabelle delle spedizioni tra quelle che viaggiano col deploy. */
function scoperte(): array
{
    return array_values(array_intersect(TABELLE, array_keys(TableSync::discoverTables())));
}

check('i nomi delle tabelle sono quelli dei modelli', fn () => TABELLE === [
    Carrier::$table,
    ShippingMethod::$table,
    ShippingZone::$table,
    ShippingZoneArea::$table,
    ShippingRate::$table,
    ShippingRateBracket::$table,
]);

check('acceso: le sei tabelle viaggiano col deploy, con i loro id e in sola lettura fuori dal locale', fn () => prova(static function (): bool {
    interruttore('true');
    $scoperte = TableSync::discoverTables();

    foreach (TABELLE as $tabella) {
        $schema = $scoperte[$tabella] ?? null;

        if ($schema === null || !$schema->keepIds || !$schema->localOnly) {
            return false;
        }
    }

    return true;
}));

check('spento: nessuna delle sei viaggia', fn () => prova(static function (): bool {
    interruttore('false');

    return scoperte() === [];
}));

check('senza la riga delle impostazioni vale acceso', fn () => prova(static function (): bool {
    sqlDelete(Setting::$table);
    TableSync::resetCache();

    return ShippingSync::enabled() && scoperte() === TABELLE;
}));

check('una sola importazione accende l\'interruttore e porta subito anche le spedizioni', fn () => prova(static function (): bool {
    $tabelle = array_merge([Setting::$table], TABELLE);
    interruttore('true');
    $zona = (array) sqlSelect(ShippingZone::$table, ['deleted' => 'false'], 1)->row;
    $config = TableSync::exportConfig($tabelle);

    // In produzione l'interruttore era spento e la zona cambiata a mano.
    interruttore('false');
    sqlModify(ShippingZone::$table, ['name' => 'Cambiata in produzione'], 'id', (string) $zona['id']);
    TableSync::importConfig($config, $tabelle);

    $dopo = (array) sqlSelect(ShippingZone::$table, ['id' => (int) $zona['id']], 1)->row;

    return (int) ($zona['id'] ?? 0) > 0
        && ShippingSync::enabled()
        && ($dopo['name'] ?? '') === ($zona['name'] ?? '');
}));

summary();
