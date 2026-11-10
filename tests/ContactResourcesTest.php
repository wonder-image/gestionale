<?php
/** php tests/ContactResourcesTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Backend\Support\QuickCreateStore;
use Wonder\Plugin\CustomerAccount\Support\ContactRelations;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Contacts\Company;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Resources\Contacts\CompanyResource;
use Wonder\Plugin\Gestionale\Resources\Contacts\CustomerResource;
use Wonder\Plugin\Gestionale\Resources\Contacts\SupplierResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

// La scheda vera si prova nel test d'integrazione: `AddressExtension` del
// core chiede `__t()`, che esiste solo a sito avviato.

// Qui basta un `__t()` finto che legga il file del modulo come il core:
// `gestionale.x` è la chiave `x` di `lang/it/gestionale.json`.
if (!function_exists('__t')) {
    function __t(string $key, array $replacements = []): string
    {
        static $dati = null;
        $dati ??= json_decode((string) file_get_contents(__DIR__.'/../lang/it/gestionale.json'), true);
        $nodo = str_starts_with($key, 'gestionale.') ? $dati : null;

        foreach (explode('.', substr($key, strlen('gestionale.'))) as $passo) {
            $nodo = is_array($nodo) ? ($nodo[$passo] ?? null) : null;
        }

        return is_string($nodo) ? $nodo : $key;
    }
}

// Lo stato delle funzionalità si forza senza database: `features()` è
// memoizzato.
$forza = static function (array $stato): void {
    (new ReflectionProperty(Gestionale::class, 'features'))->setValue(null, $stato);
};

check('le persone stanno nei contatti, aziende e fornitori nelle aziende', fn () =>
    CustomerResource::$model === Contact::class
    && CompanyResource::$model === Company::class
    && SupplierResource::$model === Company::class
);

// Con persone e aziende (lo stato di serie) l'elenco delle persone è «Persone»:
// gli altri modi li prova CustomerModeTest.
check('ognuno ha il suo indirizzo e il suo titolo', fn () =>
    CustomerResource::path() === 'app/gestionale/clienti'
    && CompanyResource::path() === 'app/gestionale/aziende'
    && SupplierResource::path() === 'app/gestionale/fornitori'
    && CustomerResource::titleLabel() === 'Persone'
    && CompanyResource::titleLabel() === 'Aziende'
    && SupplierResource::titleLabel() === 'Fornitori'
);

check('i fornitori esistono solo con gli acquisti sbloccati', fn () =>
    SupplierResource::$feature === 'purchasing'
    && CustomerResource::$feature === ''
    && CompanyResource::$feature === ''
);

check('le tre voci stanno sotto Anagrafiche', function () {
    foreach ([CustomerResource::class, CompanyResource::class, SupplierResource::class] as $classe) {
        if (($classe::navigationSchema()->toArray()['section_key'] ?? '') !== 'anagrafiche') {
            return false;
        }
    }

    return true;
});

check('ogni elenco mostra solo i suoi', function () {
    $clienti = (string) (CustomerResource::querySchema()['condition'] ?? '');
    $aziende = (string) (CompanyResource::querySchema()['condition'] ?? '');
    $fornitori = (string) (SupplierResource::querySchema()['condition'] ?? '');

    return str_contains($clienti, "is_customer = 'true'")
        && !str_contains($aziende, 'is_')
        && str_contains($fornitori, "is_supplier = 'true'");
});

check('una partita IVA già presa si ferma con il nome di chi ce l\'ha', function () {
    // `Contacts::duplicateOf()` senza database torna vuoto: qui conta che il
    // messaggio esista e nomini la scheda.
    $errore = UserError::make('contact.vat_taken', ['contact' => 'Rossi Srl']);

    return str_contains($errore->getMessage(), 'Rossi Srl')
        && str_contains($errore->getMessage(), 'partita IVA');
});

check('un\'azienda senza ruolo viene rifiutata', function () use ($forza) {
    $forza(['purchasing' => true]);

    try {
        CompanyResource::mutateRequestValues(['roles' => 'none'], 'update', 'backend', ['id' => 0]);
    } catch (UserError $errore) {
        return $errore->key() === 'contact.no_role';
    }

    return false;
});

check('una persona che non è cliente va legata a un\'azienda', fn () =>
    str_contains(UserError::make('contact.person_no_role')->getMessage(), 'referente')
);

check('un cliente nuovo nasce cliente', function () {
    $valori = CustomerResource::mutateRequestValues(['name' => 'Mario'], 'store');

    return ($valori['is_customer'] ?? '') === 'true' && !isset($valori['is_supplier']);
});

check('un\'azienda nuova nasce cliente, un fornitore nuovo nasce fornitore', function () use ($forza) {
    $forza(['purchasing' => true]);
    $azienda = CompanyResource::mutateRequestValues(['business_name' => 'Rossi Srl'], 'store');
    $fornitore = SupplierResource::mutateRequestValues(['business_name' => 'Filati Nord'], 'store');

    return ($azienda['is_customer'] ?? '') === 'true' && ($fornitore['is_supplier'] ?? '') === 'true';
});

check('le persone non hanno il campo Ruolo', function () use ($forza) {
    $forza(['purchasing' => true]);

    return !CustomerResource::rolesShown()
        && !isset(CustomerResource::mutateFormValues(['id' => 0], 'create')['roles']);
});

check('con gli acquisti bloccati il ruolo non si chiede', function () use ($forza) {
    $forza(['purchasing' => false]);

    $creazione = CompanyResource::mutateFormValues(['id' => 0], 'create');
    $modifica = CompanyResource::mutateFormValues(
        ['id' => 7, 'is_customer' => 'true', 'is_supplier' => 'false'],
        'edit'
    );

    return !isset($creazione['roles']) && !isset($modifica['roles']);
});

check('il campo si apre sul ruolo dell\'elenco da cui arrivi', function () use ($forza) {
    $forza(['purchasing' => true]);

    return (CompanyResource::mutateFormValues(['id' => 0], 'create')['roles'] ?? '') === 'customer'
        && (SupplierResource::mutateFormValues(['id' => 0], 'create')['roles'] ?? '') === 'supplier';
});

check('una scheda già salvata si riapre sul suo ruolo', function () use ($forza) {
    $forza(['purchasing' => true]);

    $valori = CompanyResource::mutateFormValues(
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

check('da "Aziende" si può creare un fornitore', function () use ($forza) {
    $forza(['purchasing' => true]);

    $valori = CompanyResource::mutateRequestValues(
        ['business_name' => 'Filati Nord', 'roles' => 'supplier'],
        'store'
    );

    // Il ruolo dell'elenco non si impone sopra una scelta esplicita.
    return ($valori['is_customer'] ?? '') === 'false'
        && ($valori['is_supplier'] ?? '') === 'true'
        && !isset($valori['roles']);
});

check('"Cliente e fornitore" accende tutti e due i ruoli', function () use ($forza) {
    $forza(['purchasing' => true]);

    $valori = CompanyResource::mutateRequestValues(
        ['roles' => 'both'],
        'update',
        'backend',
        ['id' => 0]
    );

    return ($valori['is_customer'] ?? '') === 'true' && ($valori['is_supplier'] ?? '') === 'true';
});

check('con gli acquisti bloccati un ruolo arrivato da fuori non conta', function () use ($forza) {
    $forza(['purchasing' => false]);

    $valori = CompanyResource::mutateRequestValues(
        ['business_name' => 'Rossi Srl', 'roles' => 'supplier'],
        'store'
    );

    return ($valori['is_customer'] ?? '') === 'true' && !isset($valori['is_supplier']);
});

$forza([]);

check('clienti, aziende e fornitori hanno ciascuno la sua scheda in lettura', function () use ($forza) {
    $clienti = json_encode(CustomerResource::pageSchema()->toArray());
    $aziende = json_encode(CompanyResource::pageSchema()->toArray());
    $forza(['purchasing' => true]);
    $fornitori = json_encode(SupplierResource::pageSchema()->toArray());
    $forza(['purchasing' => false]);
    // Con gli acquisti spenti nemmeno la scheda in lettura dei fornitori.
    $spenti = json_encode(SupplierResource::pageSchema()->toArray());
    $forza([]);

    return CustomerResource::hasSheet() && CompanyResource::hasSheet() && SupplierResource::hasSheet()
        && str_contains($clienti, 'customer-show.php')
        && str_contains($aziende, 'company-show.php')
        && str_contains($fornitori, 'supplier-show.php') && !str_contains($fornitori, 'company-show.php')
        && !str_contains($spenti, 'supplier-show.php');
});

check('la scheda del fornitore: carichi e articoli forniti, o la frase se non ce ne sono', fn () =>
    str_contains(SupplierResource::receiptsTable([]), 'Nessun carico')
    && str_contains(SupplierResource::productsTable([]), 'Nessun articolo')
    && str_contains(SupplierResource::productsTable([['name' => 'Maglia — Blu', 'sku' => 'MAG-B', 'supplier_sku' => 'F-12', 'cost' => 4.5]]), 'F-12')
    && SupplierResource::receipts(0) === [] && SupplierResource::supplied(0) === []
);

check('il nome in elenco apre la scheda, per i clienti e per i fornitori', function () {
    $link = static function (string $classe): string {
        foreach ($classe::tableSchema() as $c) {
            if ((string) $c->name === 'name') {
                return (string) ($c->schema['link'] ?? '');
            }
        }

        return '';
    };

    return $link(CustomerResource::class) === 'view' && $link(CompanyResource::class) === 'view'
        && $link(SupplierResource::class) === 'view';
});

check('l\'indirizzo della scheda e quello della modifica sono diversi e portano l\'id', fn () =>
    str_contains(CustomerResource::viewUrl(7), '/7/')
    && str_contains(CustomerResource::editUrlFor(7), '/7/edit')
    && CustomerResource::viewUrl(7) !== CustomerResource::editUrlFor(7)
);

check('il titolo della scheda è il nome del cliente o dell\'azienda', fn () =>
    CustomerResource::pageTitle(['name' => 'Marco', 'surname' => 'Rossi']) === 'Rossi Marco'
    && CompanyResource::pageTitle(['business_name' => 'Rossi Srl']) === 'Rossi Srl'
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

check('nel repeater degli indirizzi la via cerca anche per nome, riempie «Presso» e porta place ID e coordinate', function () {
    $campo = (new ReflectionMethod(CustomerResource::class, 'addressesField'))->invoke(null);
    $nome = new ReflectionProperty(\Wonder\App\ResourceSchema\Input::class, 'name');
    $colonne = [];

    foreach ((array) $campo->get('context')['columns'] as $colonna) {
        $colonne[(string) $nome->getValue($colonna)] = (string) $colonna->get('attribute');
    }

    $pulita = CustomerResource::prepareRepeaterRelationRow('addresses', ['street' => 'Via Roma', 'place_id' => 'x<y', 'lat' => '45.46', 'lng' => '9.19'], []);
    $buona = CustomerResource::prepareRepeaterRelationRow('addresses', ['place_id' => 'ChIJabc_def-123', 'lat' => '45.46', 'lng' => '9.19'], []);

    return str_contains($colonne['street'] ?? '', 'data-wi-place="addresses["') && str_contains($colonne['street'] ?? '', 'data-wi-place-names')
        && str_contains($colonne['street'] ?? '', 'data-wi-place-name="business_name"') && !str_contains($colonne['street'] ?? '', 'data-ca-place-name')
        && count(array_intersect(['place_id', 'lat', 'lng', 'business_name'], array_keys($colonne))) === 4
        && $pulita['place_id'] === null && $pulita['lat'] === 45.46 && ($pulita['street'] ?? '') === 'Via Roma'
        && $buona['place_id'] === 'ChIJabc_def-123' && $buona['lng'] === 9.19;
});

check('la creazione rapida dei legami: target, campi, errore senza id', function () {
    $quick = static function (string $resource, string $parent): array {
        $method = new ReflectionMethod($resource, 'relationQuickCreate');

        return (array) $method->invoke(null, $parent);
    };
    $referente = $quick(CompanyResource::class, ContactRelations::COMPANY);
    $azienda = $quick(CustomerResource::class, ContactRelations::PERSON);
    $vuoto = CustomerResource::quickCreateStore(['email' => 'a@example.com'], 'name');
    $senzaNome = CompanyResource::quickCreateStore(['pi' => 'IT01234567890'], 'business_name');

    return $referente[0] === CustomerResource::class && $referente[1] === ['name', 'surname', 'email']
        && $azienda[0] === CompanyResource::class && $azienda[1] === ['business_name', 'pi']
        && is_subclass_of(CompanyResource::class, QuickCreateStore::class)
        && !isset($vuoto['id']) && str_contains((string) ($vuoto['error'] ?? ''), 'nome')
        && !isset($senzaNome['id']) && str_contains((string) ($senzaNome['error'] ?? ''), 'ragione sociale');
});

summary();
