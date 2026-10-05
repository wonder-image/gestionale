<?php
/** php tests/ContactResourcesTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Gestionale;
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

// Lo stato delle funzionalità si forza senza database: `features()` è
// memoizzato. Il campo "Ruolo" esiste solo con gli acquisti sbloccati, e
// senza non c'è niente da scegliere.
$forza = static function (array $stato): void {
    (new ReflectionProperty(Gestionale::class, 'features'))->setValue(null, $stato);
};

check('con gli acquisti bloccati il ruolo non si chiede', function () use ($forza) {
    $forza(['purchasing' => false]);

    $creazione = CustomerResource::mutateFormValues(['id' => 0], 'create');
    $modifica = CustomerResource::mutateFormValues(
        ['id' => 7, 'is_customer' => 'true', 'is_supplier' => 'false'],
        'edit'
    );

    return !isset($creazione['roles']) && !isset($modifica['roles']);
});

check('il campo si apre sul ruolo dell\'elenco da cui arrivi', function () use ($forza) {
    $forza(['purchasing' => true]);

    return (CustomerResource::mutateFormValues(['id' => 0], 'create')['roles'] ?? '') === 'customer'
        && (SupplierResource::mutateFormValues(['id' => 0], 'create')['roles'] ?? '') === 'supplier';
});

check('una scheda già salvata si riapre sul suo ruolo', function () use ($forza) {
    $forza(['purchasing' => true]);

    $valori = CustomerResource::mutateFormValues(
        ['id' => 7, 'is_customer' => 'true', 'is_supplier' => 'true'],
        'edit'
    );

    // Una riga vecchia senza nessun ruolo acceso non lascia il campo vuoto:
    // si presenta con quello dell'elenco da cui la stai aprendo.
    $orfana = SupplierResource::mutateFormValues(
        ['id' => 8, 'is_customer' => 'false', 'is_supplier' => 'false'],
        'edit'
    );

    return ($valori['roles'] ?? '') === 'both' && ($orfana['roles'] ?? '') === 'supplier';
});

check('da "Clienti" si può creare la scheda di un fornitore', function () use ($forza) {
    $forza(['purchasing' => true]);

    $valori = CustomerResource::mutateRequestValues(
        ['name' => 'Mario', 'roles' => 'supplier'],
        'store'
    );

    // Il ruolo dell'elenco non si impone sopra una scelta esplicita: la
    // scheda nasce fornitore, e sparisce dall'elenco da cui l'hai creata.
    return ($valori['is_customer'] ?? '') === 'false'
        && ($valori['is_supplier'] ?? '') === 'true'
        && !isset($valori['roles']);
});

check('"Cliente e fornitore" accende tutti e due i ruoli', function () use ($forza) {
    $forza(['purchasing' => true]);

    $valori = CustomerResource::mutateRequestValues(
        ['roles' => 'both'],
        'update',
        'backend',
        ['id' => 7]
    );

    return ($valori['is_customer'] ?? '') === 'true' && ($valori['is_supplier'] ?? '') === 'true';
});

check('con gli acquisti bloccati un ruolo arrivato da fuori non conta', function () use ($forza) {
    $forza(['purchasing' => false]);

    $valori = CustomerResource::mutateRequestValues(
        ['name' => 'Mario', 'roles' => 'supplier'],
        'store'
    );

    return ($valori['is_customer'] ?? '') === 'true' && !isset($valori['is_supplier']);
});

$forza([]);

check('i clienti hanno la scheda in lettura, i fornitori vanno diritti alla modifica', function () {
    $clienti = CustomerResource::pageSchema()->toArray();
    $fornitori = SupplierResource::pageSchema()->toArray();

    return CustomerResource::hasSheet() && !SupplierResource::hasSheet()
        && json_encode($clienti) !== json_encode($fornitori)
        && str_contains(json_encode($clienti), 'customer-show.php')
        && !str_contains(json_encode($fornitori), 'customer-show.php');
});

check('il nome in elenco apre la scheda per i clienti e la modifica per i fornitori', function () {
    $link = static function (string $classe): string {
        foreach ($classe::tableSchema() as $c) {
            if ((string) $c->name === 'name') {
                return (string) ($c->schema['link'] ?? '');
            }
        }

        return '';
    };

    return $link(CustomerResource::class) === 'view' && $link(SupplierResource::class) === 'modify';
});

check('l\'indirizzo della scheda e quello della modifica sono diversi e portano l\'id', fn () =>
    str_contains(CustomerResource::viewUrl(7), '/7/')
    && str_contains(CustomerResource::editUrlFor(7), '/7/edit')
    && CustomerResource::viewUrl(7) !== CustomerResource::editUrlFor(7)
);

check('il titolo della scheda è il nome del cliente', fn () =>
    CustomerResource::pageTitle(['type' => 'business', 'business_name' => 'Rossi Srl']) === 'Rossi Srl'
);

check('il gestionale distingue password e provider federati', function () {
    $select = (string) (CustomerResource::tableLayoutSchema()->toArray()['select'] ?? '');

    return str_contains($select, 'auth_federated')
        && str_contains($select, 'AS auth_providers')
        && str_contains($select, 'AS has_local_password')
        && CustomerResource::authMethod(['user_id' => 0]) === 'Nessun account'
        && CustomerResource::authMethod([
            'user_id' => 7,
            'has_local_password' => false,
            'auth_providers' => 'google',
        ]) === 'Google'
        && CustomerResource::authMethod([
            'user_id' => 7,
            'has_local_password' => true,
            'auth_providers' => 'google',
        ]) === 'Email e password + Google'
        && CustomerResource::authMethod([
            'user_id' => 7,
            'has_local_password' => true,
            'auth_providers' => '',
        ]) === 'Email e password';
});

summary();
