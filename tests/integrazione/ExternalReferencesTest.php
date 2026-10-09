<?php
/** php tests/integrazione/ExternalReferencesTest.php */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\Plugin\Gestionale\Support\Providers\ExternalReferences;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

$entita = 'test_entity';
$id = 4242;
$provider = 'fatture-in-cloud';
$oggetto = 'invoice';

try {
    Transaction::run(static function () use ($entita, $id, $provider, $oggetto): void {
        check('senza riferimento find() è vuoto', fn () =>
            ExternalReferences::find($entita, $id, $provider, $oggetto) === null
        );

        check('save() registra l\'id esterno', fn () =>
            ExternalReferences::save($entita, $id, $provider, $oggetto, 'FIC-1') === true
        );

        check('find() lo ritrova con la data di sincronizzazione', function () use ($entita, $id, $provider, $oggetto) {
            $riga = ExternalReferences::find($entita, $id, $provider, $oggetto);

            return ($riga['external_id'] ?? '') === 'FIC-1'
                && trim((string) ($riga['synced_at'] ?? '')) !== ''
                && trim((string) ($riga['sync_error'] ?? '')) === '';
        });

        check('un secondo save() aggiorna invece di duplicare', function () use ($entita, $id, $provider, $oggetto) {
            ExternalReferences::save($entita, $id, $provider, $oggetto, 'FIC-2');
            $righe = ExternalReferences::all($entita, $id);

            return count($righe) === 1 && ($righe[0]['external_id'] ?? '') === 'FIC-2';
        });

        check('un errore temporaneo non cancella l\'id già salvato', function () use ($entita, $id, $provider, $oggetto) {
            ExternalReferences::fail($entita, $id, $provider, $oggetto, 'timeout');
            $riga = ExternalReferences::find($entita, $id, $provider, $oggetto);

            return ($riga['external_id'] ?? '') === 'FIC-2'
                && ($riga['sync_error'] ?? '') === 'timeout';
        });

        check('un save() riuscito pulisce l\'errore', function () use ($entita, $id, $provider, $oggetto) {
            ExternalReferences::save($entita, $id, $provider, $oggetto, 'FIC-2');
            $riga = ExternalReferences::find($entita, $id, $provider, $oggetto);

            return trim((string) ($riga['sync_error'] ?? '')) === '';
        });

        check('lo stesso oggetto convive in ambienti diversi', function () use ($entita, $id, $provider, $oggetto) {
            ExternalReferences::save($entita, $id, $provider, $oggetto, 'FIC-2', 'test');

            return count(ExternalReferences::all($entita, $id)) === 2
                && (ExternalReferences::find($entita, $id, $provider, $oggetto, 'test')['external_id'] ?? '') === 'FIC-2';
        });

        check('forget() toglie solo il suo ambiente', function () use ($entita, $id, $provider, $oggetto) {
            ExternalReferences::forget($entita, $id, $provider, $oggetto, 'test');

            return ExternalReferences::find($entita, $id, $provider, $oggetto, 'test') === null
                && ExternalReferences::find($entita, $id, $provider, $oggetto) !== null;
        });

        throw new Annulla();
    });
} catch (Annulla) {
}

check('dopo l\'annullamento non resta niente', fn () =>
    ExternalReferences::all($entita, $id) === []
);

summary();
