<?php
/** php tests/integrazione/FeaturePanelTest.php */

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';

use Wonder\Plugin\Gestionale\Models\System\Feature;
use Wonder\Plugin\Gestionale\Models\System\FeatureLog;
use Wonder\Plugin\Gestionale\Support\Features\FeaturePanel;
use Wonder\Sql\Transaction;

/** Serve a buttare via tutto quello che la prova scrive. */
final class Annulla extends RuntimeException {}

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
    }

    return $esito;
}

/** Tutte le righe di una funzionalità, anche quelle eliminate. */
function righe(string $chiave): array
{
    $trovate = Feature::find(['feature_key' => $chiave]);

    return is_array($trovate) ? array_values(array_filter($trovate, 'is_array')) : [];
}

const CHIAVE = 'orders';

echo "FeaturePanel — righe mancanti\n";

check('una funzionalità senza riga si accende dal pannello', static function (): bool {
    return prova(static function (): bool {
        foreach (righe(CHIAVE) as $riga) {
            sqlDelete(FeatureLog::$table, 'feature_id = '.(int) $riga['id']);
        }
        sqlDelete(Feature::$table, "feature_key = '".CHIAVE."'");

        FeaturePanel::save([CHIAVE => true], 0);

        $righe = righe(CHIAVE);

        return count($righe) === 1
            && $righe[0]['enabled'] === 'true'
            && $righe[0]['deleted'] === 'false'
            && FeaturePanel::state()[CHIAVE] === true;
    });
});

check('l\'accensione di una riga appena creata lascia nello storico un solo passaggio spento → acceso', static function (): bool {
    return prova(static function (): bool {
        foreach (righe(CHIAVE) as $riga) {
            sqlDelete(FeatureLog::$table, 'feature_id = '.(int) $riga['id']);
        }
        sqlDelete(Feature::$table, "feature_key = '".CHIAVE."'");

        FeaturePanel::save([CHIAVE => true], 0);

        $riga = righe(CHIAVE)[0];
        $storico = FeatureLog::find(['feature_id' => (int) $riga['id']]);
        $storico = is_array($storico) ? array_values(array_filter($storico, 'is_array')) : [];

        return count($storico) === 1
            && $storico[0]['from_value'] === 'false'
            && $storico[0]['to_value'] === 'true'
            && $storico[0]['source'] === 'user';
    });
});

check('una riga eliminata con la stessa chiave si recupera, senza doppioni', static function (): bool {
    return prova(static function (): bool {
        $esistenti = righe(CHIAVE);
        $id = (int) ($esistenti[0]['id'] ?? Feature::create(['feature_key' => CHIAVE, 'enabled' => 'false'])->insert_id);
        sqlModify(Feature::$table, ['enabled' => 'false', 'deleted' => 'true'], 'id', $id);

        FeaturePanel::save([CHIAVE => true], 0);

        $righe = righe(CHIAVE);

        return count($righe) === 1
            && (int) $righe[0]['id'] === $id
            && $righe[0]['deleted'] === 'false'
            && $righe[0]['enabled'] === 'true';
    });
});

check('una riga che c\'è già non viene duplicata', static function (): bool {
    return prova(static function (): bool {
        FeaturePanel::save([CHIAVE => false], 0);
        $prima = count(righe(CHIAVE));
        FeaturePanel::save([CHIAVE => true], 0);

        return $prima === count(righe(CHIAVE)) && $prima === 1;
    });
});

summary();
