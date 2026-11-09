<?php
/** php tests/OrderCompanyBrandMigrationTest.php — il brand degli ordini da `brand_id` a `company_brand_id` (nessun database). */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Migrations\OrderCompanyBrandMigration as Migration;

$compatto = static fn (string $sql): string => (string) preg_replace('/\s+/', ' ', $sql);

check('è dichiarata nel manifest, dopo quella delle aziende', function () {
    $migrazioni = (array) (json_decode((string) file_get_contents(__DIR__.'/../module.json'), true)['database']['migrations'] ?? []);

    return array_slice($migrazioni, -1) === [Migration::class]
        && in_array('Wonder\\Plugin\\Gestionale\\Migrations\\DocumentCompanyMigration', $migrazioni, true);
});

check('con la colonna vecchia: crea le nuove, copia l\'id dove manca, poi il nome', function () use ($compatto) {
    $piano = array_map($compatto, Migration::plan(['id', 'company_id', 'brand_id', 'referent_name'], true));

    return array_keys($piano) === ['add_company_brand_id', 'add_company_brand_name', 'copied', 'named']
        && str_contains($piano['add_company_brand_id'], 'ADD COLUMN `company_brand_id` INT')
        && str_contains($piano['add_company_brand_name'], 'ADD COLUMN `company_brand_name` VARCHAR(255)')
        && str_contains($piano['copied'], 'SET `company_brand_id` = `brand_id`')
        && str_contains($piano['copied'], 'WHERE COALESCE(`company_brand_id`, 0) = 0 AND `brand_id` > 0')
        && str_contains($piano['named'], 'JOIN `company_brands` b ON b.`id` = o.`company_brand_id`')
        && str_contains($piano['named'], "COALESCE(o.`company_brand_name`, '') = ''")
        && str_contains($piano['named'], "b.`deleted` = 'false'");
});

check('rilanciata a colonne già create non le ricrea, e le copie scrivono solo dove manca', function () {
    $piano = Migration::plan(['id', 'brand_id', 'company_brand_id', 'company_brand_name'], true);

    return array_keys($piano) === ['copied', 'named'];
});

check('la colonna vecchia non si cancella: lo fa la sincronizzazione delle tabelle', function () {
    foreach ([['brand_id'], ['brand_id', 'company_brand_id', 'company_brand_name'], ['company_brand_id']] as $colonne) {
        foreach (Migration::plan($colonne, true) as $sql) {
            if (stripos($sql, 'DROP') !== false) {
                return false;
            }
        }
    }

    return true;
});

check('tolta la colonna vecchia resta solo il nome dei documenti che non l\'hanno', fn () =>
    array_keys(Migration::plan(['id', 'company_brand_id', 'company_brand_name'], true)) === ['named']
);

check('un\'installazione senza brand non fa niente, e senza la tabella dei brand non copia nomi', fn () =>
    Migration::plan(['id', 'company_id', 'referent_name'], true) === []
    && array_keys(Migration::plan(['brand_id'], false)) === ['add_company_brand_id', 'add_company_brand_name', 'copied']
);

summary();
