<?php
/** php tests/integrazione/ContactRegistryTest.php */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\App\ModelRegistry;
use Wonder\App\ResourceRegistry;
use Wonder\Plugin\CustomerAccount\Resources\ContactAddressResource as AccountAddressResource;
use Wonder\Plugin\CustomerAccount\Resources\ContactResource;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Contacts\ContactAddress;
use Wonder\Plugin\Gestionale\Resources\Contacts\CustomerResource;
use Wonder\Plugin\Gestionale\Resources\Contacts\SupplierResource;

// Le tabelle sono di customer-account; il gestionale ci mette i suoi Model e
// le sue Resource. Qui si fissa chi risponde per tabella, senza database.

// L'ordine in cui il registro vede le Resource di ogni tabella, per leggerlo
// quando un check cade.
foreach ([Contact::$table, ContactAddress::$table] as $tabella) {
    echo "  · {$tabella}:";
    foreach (ResourceRegistry::all() as $classe) {
        if ($classe::modelTable() === $tabella) {
            echo ' '.$classe.($classe::isTableFallback() ? ' (fallback)' : '');
        }
    }
    echo "\n";
}

check('la scheda di customer-account cede il passo sulla sua tabella', fn () =>
    ContactResource::isTableFallback() === true
);

// Tutte e due non fallback: vince la prima che il registro trova, Clienti.
check('clienti e fornitori non sono fallback', fn () =>
    SupplierResource::isTableFallback() === false
    && CustomerResource::isTableFallback() === false
);

check('per la tabella dei contatti risponde «Clienti» del gestionale', fn () =>
    ResourceRegistry::resolveByTable(Contact::$table) === CustomerResource::class
);

check('per gli indirizzi risponde ancora customer-account', fn () =>
    ResourceRegistry::resolveByTable(ContactAddress::$table) === AccountAddressResource::class
);

// `ModelRegistry` tiene l'ultimo Model per tabella in ordine di nome di
// classe: `...\Gestionale\...` viene dopo `...\CustomerAccount\...`.
check('le due tabelle condivise usano i Model del gestionale', function () {
    $modelli = ModelRegistry::all();

    return ($modelli['contacts'] ?? null) === Contact::class
        && ($modelli['contact_addresses'] ?? null) === ContactAddress::class;
});

summary();
