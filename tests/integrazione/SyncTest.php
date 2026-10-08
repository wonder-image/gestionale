<?php
/** php tests/integrazione/SyncTest.php */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\App\Support\TableSync;
use Wonder\Plugin\Gestionale\Models\System\Feature;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

// L'export scrive shared/sync-data.json: si usa una cartella temporanea, così
// il file del sito non viene toccato.
$root = sys_get_temp_dir().'/gestionale-sync-'.bin2hex(random_bytes(4));
mkdir($root.'/shared', 0777, true);

$riga = static fn (): array => (array) sqlSelect(Feature::$table, ['feature_key' => 'orders'], 1)->row;
$prima = $riga();

try {
    Transaction::run(static function () use ($root, $riga, $prima): void {
        $id = (int) ($prima['id'] ?? 0);

        check('la riga della funzionalità esiste', fn () => $id > 0);

        sqlModify(Feature::$table, ['enabled' => 'true'], 'id', $id);
        TableSync::exportToFile($root);

        $file = json_decode((string) file_get_contents($root.'/shared/sync-data.json'), true);
        $esportate = array_column($file[Feature::$table] ?? [], null, 'feature_key');

        check('l\'export contiene la funzionalità con id e stato', fn () =>
            (int) ($esportate['orders']['id'] ?? 0) === $id
            && ($esportate['orders']['enabled'] ?? '') === 'true'
        );

        check('l\'export contiene tutte le righe, anche quelle bloccate', fn () =>
            count($esportate) === count(array_filter((array) sqlSelect(Feature::$table, null)->row, 'is_array'))
        );

        check('l\'export porta anche la colonna deleted', fn () =>
            array_key_exists('deleted', $esportate['orders'] ?? [])
        );

        throw new Annulla();
    });
} catch (Annulla) {
}

check('dopo l\'annullamento lo stato è quello di prima', fn () =>
    ($riga()['enabled'] ?? '') === ($prima['enabled'] ?? '')
);

array_map('unlink', glob($root.'/shared/*') ?: []);
rmdir($root.'/shared');
rmdir($root);

summary();
