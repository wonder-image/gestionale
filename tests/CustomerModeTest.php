<?php
/** php tests/CustomerModeTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\App\LegacyGlobals;
use Wonder\App\Theme;
use Wonder\Backend\Support\ResourceFormLayoutRenderer;
use Wonder\Plugin\CustomerAccount\Support\ContactRelations;
use Wonder\Plugin\Gestionale\Backend\Widgets\ContactsWidget;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Resources\Contacts\CompanyBrandResource;
use Wonder\Plugin\Gestionale\Resources\Contacts\CompanyResource;
use Wonder\Plugin\Gestionale\Resources\Contacts\CustomerResource;
use Wonder\Plugin\Gestionale\Resources\Contacts\SupplierResource;
use Wonder\Plugin\Gestionale\Resources\Sales\OfficeOrderResource;
use Wonder\Plugin\Gestionale\Support\Contacts\CustomerMode;
use Wonder\Plugin\Gestionale\Support\Contacts\QuickContacts;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Features\FeatureCatalog;
use Wonder\Plugin\Gestionale\Support\Features\FeatureGuards;
use Wonder\Plugin\Gestionale\Support\Features\FeatureRules;
use Wonder\Plugin\Gestionale\Support\Features\FeatureState;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeOrderPage;

/*
 * Con chi lavora il gestionale: persone, aziende o tutte e due. Il modo nasce
 * da «Clienti privati» e «Aziende» del pannello e comanda menu, pagine,
 * etichette, creazioni rapide e ordine da ufficio. I dati non si toccano.
 */

// La scheda chiede `__t()`, che esiste solo a sito avviato: qui una finta che
// legge il file del modulo come il core (vedi ContactResourcesTest).
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

Theme::set('bootstrap');

$forza = static function (?array $stato): void {
    (new ReflectionProperty(Gestionale::class, 'features'))->setValue(null, $stato);
};
$modo = static function (string $modo) use ($forza): void {
    $forza([
        'private_customers' => $modo !== CustomerMode::COMPANIES,
        'companies' => $modo !== CustomerMode::PEOPLE,
        'purchasing' => true,
        'orders' => true,
        'office_sales' => true,
        'brands' => $modo !== CustomerMode::PEOPLE,
    ]);
};

check('il modo nasce dai due interruttori; nessuno dei due (stato non letto) vale «both»', function () {
    return CustomerMode::of(true, true) === CustomerMode::BOTH
        && CustomerMode::of(true, false) === CustomerMode::PEOPLE
        && CustomerMode::of(false, true) === CustomerMode::COMPANIES
        && CustomerMode::of(false, false) === CustomerMode::BOTH;
});

check('un solo punto di lettura: current(), people(), companies()', function () use ($modo, $forza) {
    $esiti = [];

    foreach ([CustomerMode::PEOPLE, CustomerMode::BOTH, CustomerMode::COMPANIES] as $m) {
        $modo($m);
        $esiti[$m] = [CustomerMode::current(), CustomerMode::people(), CustomerMode::companies()];
    }

    $forza(null);

    return $esiti[CustomerMode::PEOPLE] === [CustomerMode::PEOPLE, true, false]
        && $esiti[CustomerMode::BOTH] === [CustomerMode::BOTH, true, true]
        && $esiti[CustomerMode::COMPANIES] === [CustomerMode::COMPANIES, false, true];
});

check('nel pannello: «Clienti privati» e «Aziende» in Anagrafiche, accese di serie', function () {
    $catalogo = FeatureCatalog::all();

    return ($catalogo['private_customers']['area'] ?? '') === 'Anagrafiche'
        && ($catalogo['companies']['area'] ?? '') === 'Anagrafiche'
        && ($catalogo['private_customers']['default'] ?? false) === true
        && ($catalogo['companies']['default'] ?? false) === true
        && ($catalogo['company_accounts']['area'] ?? '') === 'Anagrafiche'
        && in_array('companies', $catalogo['company_accounts']['requires'] ?? [], true);
});

check('spegnere «Aziende» spegne per dipendenza gli account aziendali, che poi non si accendono', function () {
    $catalogo = FeatureCatalog::all();
    $prima = ['private_customers' => true, 'companies' => true, 'orders' => true, 'online_sales' => true, 'company_accounts' => true];
    $dopo = FeatureRules::apply($catalogo, $prima, ['companies' => false]);
    $stato = FeatureState::resolve($catalogo, ['private_customers' => true, 'companies' => false, 'orders' => true, 'online_sales' => true, 'company_accounts' => true], []);

    return $dopo['state']['company_accounts'] === false
        && in_array('company_accounts', $dopo['locked'], true)
        && $stato['company_accounts'] === false;
});

check('«Clienti privati» e «Aziende» non si spengono insieme; una alla volta sì', function () {
    $prima = ['private_customers' => true, 'companies' => true];

    try {
        FeatureGuards::assertAllowed($prima, ['private_customers' => false, 'companies' => false]);

        return false;
    } catch (UserError $errore) {
        $chiave = $errore->key();
    }

    FeatureGuards::assertAllowed($prima, ['private_customers' => false, 'companies' => true]);
    FeatureGuards::assertAllowed($prima, ['private_customers' => true, 'companies' => false]);

    return $chiave === 'feature.customers_none';
});

check('l\'elenco delle persone si chiama come il modo: Clienti, Persone, Referenti', function () use ($modo, $forza) {
    $titoli = [];

    foreach ([CustomerMode::PEOPLE, CustomerMode::BOTH, CustomerMode::COMPANIES] as $m) {
        $modo($m);
        $pagine = (array) CustomerResource::pageSchema()->get('titles');
        $titoli[$m] = [
            CustomerResource::titleLabel(),
            (string) (CustomerResource::navigationSchema()->toArray()['title'] ?? ''),
            (string) ($pagine['create'] ?? ''),
            (string) ($pagine['view'] ?? ''),
        ];
    }

    $forza(null);

    return $titoli[CustomerMode::PEOPLE] === ['Clienti', 'Clienti', 'Aggiungi cliente', 'Scheda cliente']
        && $titoli[CustomerMode::BOTH] === ['Persone', 'Persone', 'Aggiungi persona', 'Scheda persona']
        && $titoli[CustomerMode::COMPANIES] === ['Referenti', 'Referenti', 'Aggiungi referente', 'Scheda referente']
        && CompanyResource::titleLabel() === 'Aziende'
        && CustomerResource::path() === 'app/gestionale/clienti';
});

check('solo persone: aziende fuori da menu e URL, i fornitori restano senza brand, capogruppo e referenti', function () use ($modo, $forza) {
    $modo(CustomerMode::PEOPLE);

    try {
        $chiavi = array_map(static fn ($campo): string => (string) $campo->name, SupplierResource::formSchema());
        $esito = CompanyResource::featureActive() === false
            && (bool) CompanyResource::navigationSchema()->get('enabled') === false
            && array_filter((array) CompanyResource::pageSchema()->get('pages')) === []
            && SupplierResource::featureActive() === true
            && (bool) SupplierResource::navigationSchema()->get('enabled') === true
            && CustomerResource::featureActive() === true
            && !SupplierResource::rolesShown()
            && !in_array('brands', $chiavi, true) && !in_array('parent_id', $chiavi, true)
            && !in_array(CustomerResource::RELATION_PEOPLE, $chiavi, true)
            && !CustomerResource::relationsShown();
    } finally {
        $forza(null);
    }

    return $esito;
});

check('un fornitore nuovo senza il campo Ruolo resta fornitore e non diventa cliente', function () use ($modo, $forza) {
    $modo(CustomerMode::PEOPLE);

    try {
        $valori = SupplierResource::mutateRequestValues(['business_name' => 'Filati Nord'], 'store');
    } finally {
        $forza(null);
    }

    return ($valori['is_supplier'] ?? '') === 'true' && !isset($valori['is_customer']);
});

check('l\'elenco delle persone: solo clienti, clienti e referenti col filtro Ruolo a vista, solo referenti', function () use ($modo, $forza) {
    $condizioni = [];
    $filtri = [];

    foreach ([CustomerMode::PEOPLE, CustomerMode::BOTH, CustomerMode::COMPANIES] as $m) {
        $modo($m);
        $condizioni[$m] = (string) (CustomerResource::querySchema()['condition'] ?? '');
        $filtri[$m] = array_column((array) (CustomerResource::tableLayoutSchema()->toArray()['custom_filters'] ?? []), null, 'column');
    }

    $forza(null);
    $ruolo = $filtri[CustomerMode::BOTH]['role'] ?? [];

    return !str_contains($condizioni[CustomerMode::PEOPLE], 'EXISTS') && str_contains($condizioni[CustomerMode::PEOPLE], "is_customer = 'true'")
        && str_contains($condizioni[CustomerMode::BOTH], "is_customer = 'true'") && str_contains($condizioni[CustomerMode::BOTH], 'EXISTS')
        && str_contains($condizioni[CustomerMode::COMPANIES], 'EXISTS') && !str_contains($condizioni[CustomerMode::COMPANIES], "is_customer = 'true'")
        && ($ruolo['input'] ?? '') === 'radio'
        && ($ruolo['label'] ?? '') === 'Ruolo'
        && array_values((array) ($ruolo['array'] ?? [])) === ['Tutti', 'Clienti', 'Referenti']
        && ($ruolo['where'])(['customers']) === "is_customer = 'true'"
        && str_contains(($ruolo['where'])(['referents']), 'EXISTS')
        && ($ruolo['where'])([]) === '1 = 1'
        && !isset($filtri[CustomerMode::PEOPLE]['role']) && !isset($filtri[CustomerMode::COMPANIES]['role']);
});

check('solo aziende: la persona nuova è un referente e senza azienda si ferma; niente pagamento né listino', function () use ($modo, $forza) {
    $modo(CustomerMode::COMPANIES);
    $post = $_POST;
    $_POST = [CustomerResource::RELATION_MARKER => '1', CustomerResource::RELATION_COMPANIES => []];

    try {
        CustomerResource::mutateRequestValues(['name' => 'Anna'], 'store');
        $chiave = '';
    } catch (UserError $errore) {
        $chiave = $errore->key();
    } finally {
        $_POST = $post;
    }

    $persone = CustomerResource::sellsTo() || CustomerResource::paymentDefaultsShown();
    $aziende = CompanyResource::sellsTo() && CompanyResource::paymentDefaultsShown();
    $forza(null);

    return $chiave === 'contact.person_no_role' && !$persone && $aziende;
});

check('la scheda di chi è solo referente si apre: dati e indirizzi, senza le parti del cliente', function () use ($modo, $forza) {
    $modo(CustomerMode::BOTH);
    ContactRelations::useEnabled(false);

    try {
        $referente = ['id' => 0, 'name' => 'Anna', 'surname' => 'Bianchi', 'is_customer' => 'false', 'email' => 'anna@example.com'];
        $html = ResourceFormLayoutRenderer::renderLayout(CustomerResource::showLayoutSchema($referente));
        $vista = (string) file_get_contents(__DIR__.'/../view/pages/customer-show.php');
        $cliente = CustomerResource::isCustomerSheet(['is_customer' => 'true']);
        $modo(CustomerMode::COMPANIES);
        $soloAziende = CustomerResource::isCustomerSheet(['is_customer' => 'true']);
    } finally {
        ContactRelations::useEnabled(null);
        $forza(null);
    }

    return str_contains($html, 'Tutti i suoi dati') && str_contains($html, 'Referente') && str_contains($html, 'anna@example.com')
        && str_contains($html, 'Indirizzi di consegna')
        && !str_contains($html, 'Statistiche') && !str_contains($html, 'Ordini') && !str_contains($html, 'carrello')
        && !str_contains($html, 'Coupon') && !str_contains($html, 'Listino') && !str_contains($html, 'Dati di fatturazione')
        && !CustomerResource::isCustomerSheet($referente) && $cliente && !$soloAziende
        && CompanyResource::isCustomerSheet([])
        && !str_contains($vista, "is_customer'] ?? 'false') !== 'true'");
});

check('creazione rapida: rispetta il modo e vuole l\'azienda dove serve, prima di toccare il database', function () use ($modo, $forza) {
    $modo(CustomerMode::COMPANIES);
    $privato = QuickContacts::person(['name' => 'Anna', 'role' => QuickContacts::ROLE_CUSTOMER]);
    $senzaAzienda = QuickContacts::person(['name' => 'Anna', QuickContacts::LINK_COMPANY => '']);
    $brandSenzaAzienda = CompanyBrandResource::quickCreateStore(['name' => 'Rossi', QuickContacts::LINK_COMPANY => '0'], 'name');
    $modo(CustomerMode::PEOPLE);
    $azienda = QuickContacts::company(['business_name' => 'Rossi Srl']);
    $referente = QuickContacts::person(['name' => 'Anna', QuickContacts::LINK_COMPANY => '7']);
    $brand = CompanyBrandResource::quickCreateStore(['name' => 'Rossi'], 'name');
    $daAzienda = CompanyResource::quickCreateStore(['business_name' => 'Rossi Srl'], 'business_name');
    $forza(null);

    return !$privato['ok'] && str_contains($privato['message'], 'solo con le aziende')
        && !$senzaAzienda['ok'] && str_contains($senzaAzienda['message'], 'Scegli prima l\'azienda')
        && !isset($brandSenzaAzienda['id']) && str_contains((string) ($brandSenzaAzienda['error'] ?? ''), 'Scegli prima')
        && !$azienda['ok'] && str_contains($azienda['message'], 'solo con le persone')
        && !$referente['ok'] && str_contains($referente['message'], 'solo con le persone')
        && !isset($brand['id']) && str_contains((string) ($brand['error'] ?? ''), 'brand delle aziende sono spenti')
        && !isset($daAzienda['id']);
});

/** L'ordine da ufficio disegnato nel modo dato, da chi può creare schede; `$brand` false spegne «Brand». */
$ordine = static function (string $m, array $valori = [], bool $brand = true) use ($modo, $forza): string {
    $modo($m);

    if (!$brand) {
        $forza(['brands' => false] + (array) (new ReflectionProperty(Gestionale::class, 'features'))->getValue());
    }

    $utente = LegacyGlobals::get('USER');
    LegacyGlobals::set('USER', (object) ['authority' => ['administrator']]);

    try {
        return ResourceFormLayoutRenderer::renderLayout(OfficeOrderPage::layout(OfficeOrderResource::class, $valori + [
            'id' => 0, 'status' => 'draft', 'fulfillment_type' => 'shipping', 'manual_discount_type' => 'none', 'editor' => ['lines' => []],
        ], '<div data-wi-office-editor></div>'));
    } finally {
        LegacyGlobals::set('USER', $utente);
        $forza(null);
    }
};

check('ordine da ufficio: cliente, referente e brand nuovi in una finestra, niente uscita dalla pagina', function () use ($ordine) {
    $html = $ordine(CustomerMode::BOTH);

    return str_contains($html, 'data-wi-oo-new="contact"') && str_contains($html, 'Nuova persona')
        && str_contains($html, 'data-wi-oo-new="company"') && str_contains($html, 'Nuova azienda')
        && preg_match('/data-wi-qc-family="select" data-wi-qc-resource="app-gestionale-clienti"/', $html) === 1
        && preg_match('/data-wi-qc-family="select" data-wi-qc-resource="app-gestionale-brand"/', $html) === 1
        && substr_count($html, 'name="'.QuickContacts::LINK_COMPANY.'" value="0"') === 2
        && substr_count($html, 'data-wi-oo-qc-company') === 2
        && preg_match('/<input[^>]*type="hidden"[^>]*name="role"[^>]*value="customer"/', $html) === 1
        && str_contains($html, 'Nuovo referente') && str_contains($html, 'Nuovo brand')
        && !str_contains($html, '/create/') && !str_contains($html, 'target="_blank"')
        // Il `company_id` dell'ordine resta uno solo: quello delle finestre ha un altro nome.
        && preg_match_all('/name="company_id"/', $html) === 1;
});

check('ordine da ufficio senza «Brand»: referente sì, tendina, «Nuovo brand» e logo no', function () use ($ordine) {
    $html = $ordine(CustomerMode::BOTH, [], false);

    return str_contains($html, 'name="referent"') && str_contains($html, 'Nuovo referente')
        && !str_contains($html, 'name="company_brand_id"') && !str_contains($html, 'Nuovo brand')
        && !str_contains($html, 'app-gestionale-brand') && !str_contains($html, 'data-wi-oo-brand-logo');
});

check('ordine da ufficio, solo persone: «Nuovo cliente», senza azienda, referente né brand', function () use ($ordine) {
    $html = $ordine(CustomerMode::PEOPLE);

    return str_contains($html, 'data-wi-oo-new="contact"') && str_contains($html, 'Nuovo cliente')
        && !str_contains($html, 'data-wi-oo-new="company"')
        && !str_contains($html, 'name="referent"') && !str_contains($html, 'name="company_brand_id"')
        && !str_contains($html, QuickContacts::LINK_COMPANY);
});

check('ordine da ufficio, solo aziende: «Nuova azienda» con referente e brand, nessuna persona cliente', function () use ($ordine) {
    $html = $ordine(CustomerMode::COMPANIES);

    return str_contains($html, 'data-wi-oo-new="company"')
        && !str_contains($html, 'data-wi-oo-new="contact"') && !str_contains($html, 'name="role"')
        && str_contains($html, 'name="referent"') && str_contains($html, 'name="company_brand_id"')
        && str_contains($html, 'Nessuna azienda trovata');
});

check('senza il permesso di creare schede i bottoni non ci sono', function () use ($modo, $forza) {
    $modo(CustomerMode::BOTH);
    $utente = LegacyGlobals::get('USER');
    LegacyGlobals::set('USER', (object) ['authority' => ['warehouse']]);

    try {
        $html = ResourceFormLayoutRenderer::renderLayout(OfficeOrderPage::layout(OfficeOrderResource::class, [
            'id' => 0, 'status' => 'draft', 'fulfillment_type' => 'shipping', 'manual_discount_type' => 'none', 'editor' => ['lines' => []],
        ], '<div data-wi-office-editor></div>'));
    } finally {
        LegacyGlobals::set('USER', $utente);
        $forza(null);
    }

    return !str_contains($html, 'data-wi-oo-new') && !str_contains($html, 'data-wi-quick-create');
});

check('lo script della pagina sceglie il cliente nato e porta l\'azienda nelle finestre', function () {
    $vista = (string) file_get_contents(__DIR__.'/../view/pages/office-order-form.php');

    return str_contains($vista, "'wi:quick-create:created'")
        && str_contains($vista, 'data-wi-oo-new')
        && str_contains($vista, 'window.wiRemoteSearch.select(root')
        && str_contains($vista, '[data-wi-oo-qc-company]')
        && !str_contains($vista, 'innerHTML = detail');
});

check('il riquadro Anagrafiche della home segue il modo', function () {
    $soloAziende = ContactsWidget::markup(null, null, 4);
    $soloPersone = ContactsWidget::markup(3, null, null);

    return !str_contains($soloAziende, 'Clienti') && str_contains($soloAziende, '4 aziende')
        && str_contains($soloPersone, '3 clienti') && !str_contains($soloPersone, 'Aziende');
});

check('l\'ordine da ufficio rifiuta un cliente fuori modo: le frasi ci sono', function () {
    return str_contains(UserError::make('office_order.customer_people_only')->getMessage(), 'persona')
        && str_contains(UserError::make('office_order.customer_companies_only')->getMessage(), 'azienda');
});

summary();
