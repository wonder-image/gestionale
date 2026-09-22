<?php
/** php tests/ContactResourcesTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Resources\Contacts\CustomerResource;
use Wonder\Plugin\Gestionale\Resources\Contacts\SupplierResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

// La scheda vera si prova nel test d'integrazione: `AddressExtension` del
// core chiede `__t()`, che esiste solo a sito avviato.

check('i due elenchi guardano la stessa tabella', fn () =>
    CustomerResource::$model === Contact::class
    && SupplierResource::$model === Contact::class
);

check('ognuno ha il suo indirizzo e il suo titolo', fn () =>
    CustomerResource::path() === 'app/gestionale/clienti'
    && SupplierResource::path() === 'app/gestionale/fornitori'
    && CustomerResource::titleLabel() === 'Clienti'
    && SupplierResource::titleLabel() === 'Fornitori'
);

check('i fornitori esistono solo con gli acquisti sbloccati', fn () =>
    SupplierResource::$feature === 'purchasing'
    && CustomerResource::$feature === ''
);

check('le due voci stanno sotto Anagrafiche', fn () =>
    (CustomerResource::navigationSchema()->toArray()['section_key'] ?? '') === 'anagrafiche'
    && (SupplierResource::navigationSchema()->toArray()['section_key'] ?? '') === 'anagrafiche'
);

check('ogni elenco mostra solo i suoi', function () {
    $clienti = (string) (CustomerResource::querySchema()['condition'] ?? '');
    $fornitori = (string) (SupplierResource::querySchema()['condition'] ?? '');

    return str_contains($clienti, "is_customer = 'true'")
        && str_contains($fornitori, "is_supplier = 'true'");
});

check('una partita IVA già presa si ferma con il nome di chi ce l\'ha', function () {
    // `Contacts::duplicateOf()` senza database torna vuoto: qui conta che il
    // messaggio esista e nomini la scheda.
    $errore = UserError::make('contact.vat_taken', ['contact' => 'Rossi Srl']);

    return str_contains($errore->getMessage(), 'Rossi Srl')
        && str_contains($errore->getMessage(), 'partita IVA');
});

check('una scheda senza ruolo viene rifiutata', function () {
    try {
        CustomerResource::mutateRequestValues(
            ['is_customer' => 'false', 'is_supplier' => 'false'],
            'update',
            'backend',
            ['id' => 0]
        );
    } catch (UserError $errore) {
        return $errore->key() === 'contact.no_role';
    }

    return false;
});

check('un cliente nuovo nasce cliente', function () {
    $valori = CustomerResource::mutateRequestValues(['name' => 'Mario'], 'store');

    return ($valori['is_customer'] ?? '') === 'true';
});

check('un fornitore nuovo nasce fornitore', function () {
    $valori = SupplierResource::mutateRequestValues(['name' => 'Mario'], 'store');

    return ($valori['is_supplier'] ?? '') === 'true';
});

summary();
