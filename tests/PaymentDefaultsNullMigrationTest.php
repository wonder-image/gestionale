<?php
/** php tests/PaymentDefaultsNullMigrationTest.php — metodo e termini di pagamento a `0` diventano vuoti (nessun database). */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Migrations\PaymentDefaultsNullMigration as Migration;

check('è dichiarata nel manifest', function () {
    $migrazioni = (array) (json_decode((string) file_get_contents(__DIR__.'/../module.json'), true)['database']['migrations'] ?? []);

    return in_array(Migration::class, $migrazioni, true);
});

check('persone e aziende: lo 0 di metodo e termini diventa NULL, e solo lo 0', function () {
    $piano = Migration::plan([
        'contacts' => ['id', 'payment_method_id', 'payment_term_id'],
        'companies' => ['id', 'payment_method_id', 'payment_term_id'],
    ]);

    return array_keys($piano) === [
        'contacts.payment_method_id', 'contacts.payment_term_id',
        'companies.payment_method_id', 'companies.payment_term_id',
    ]
        && $piano['contacts.payment_method_id'] === 'UPDATE `contacts` SET `payment_method_id` = NULL WHERE `payment_method_id` = 0'
        && $piano['companies.payment_term_id'] === 'UPDATE `companies` SET `payment_term_id` = NULL WHERE `payment_term_id` = 0';
});

check('una tabella o una colonna che manca non si tocca', fn () =>
    array_keys(Migration::plan(['contacts' => ['id', 'payment_term_id']])) === ['contacts.payment_term_id']
    && Migration::plan([]) === []
    && Migration::plan(['companies' => ['id', 'name']]) === []
);

check('le altre tabelle e le altre colonne restano fuori', fn () =>
    Migration::plan(['gst_orders' => ['payment_method_id'], 'contacts' => ['agent_id', 'price_list_id']]) === []
);

summary();
