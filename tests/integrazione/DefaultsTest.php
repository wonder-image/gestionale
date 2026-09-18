<?php
/** php tests/integrazione/DefaultsTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\App\Support\DefaultRows;
use Wonder\Plugin\Gestionale\Models\System\Feature;
use Wonder\Plugin\Gestionale\Models\System\FeatureLog;
use Wonder\Plugin\Gestionale\Seeding\Defaults;
use Wonder\Plugin\Gestionale\Support\Features\FeatureCatalog;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

$righe = static fn (): array => array_values(array_filter(
    (array) sqlSelect(Feature::$table, null)->row,
    'is_array'
));

$prima = count($righe());

try {
    Transaction::run(static function () use ($righe, $prima): void {
        // Si semina sul vuoto: con le righe già presenti il seed non tocca
        // niente e lo stato sarebbe quello lasciato dal pannello.
        sqlDelete(FeatureLog::$table);
        sqlDelete(Feature::$table);

        Defaults::seed(new DefaultRows());
        $dopo = $righe();

        check('una riga per ogni funzionalità del catalogo', fn () =>
            count($dopo) === count(FeatureCatalog::all())
        );

        $stato = array_column($dopo, 'enabled', 'feature_key');

        check('le funzionalità nascono bloccate', fn () =>
            ($stato['orders'] ?? null) === 'false' && ($stato['backorders'] ?? null) === 'false'
        );

        Defaults::seed(new DefaultRows());

        check('una seconda esecuzione non duplica niente', fn () => count($righe()) === count($dopo));

        throw new Annulla();
    });
} catch (Annulla) {
}

check('dopo l\'annullamento il database è come prima', fn () => count($righe()) === $prima);

summary();
