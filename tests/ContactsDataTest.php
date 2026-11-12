<?php
/** php tests/ContactsDataTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';
require __DIR__ . '/supporto/SenzaDns.php';

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Resources\Contacts\CompanyBrandResource;
use Wonder\Plugin\Gestionale\Resources\Contacts\CompanyResource;
use Wonder\Plugin\Gestionale\Resources\Contacts\CustomerResource;
use Wonder\Plugin\Gestionale\Resources\Contacts\SupplierResource;
use Wonder\Plugin\Gestionale\Support\Contacts\CustomerMode;
use Wonder\Plugin\Gestionale\Support\Contacts\CustomerSheet;
use Wonder\Plugin\Gestionale\Support\Contacts\DocumentBrands;
use Wonder\Plugin\Gestionale\Support\Features\FeatureCatalog;
use Wonder\Plugin\Gestionale\Support\Mcp\ContactTools;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeCustomerPanel;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeOrders;

/*
 * I dati nuovi di customer-account nel gestionale: indirizzi per brand, altri
 * contatti, dati e kit del brand, MCP. Quello che vuole il database (scrittura
 * dei legami, lettura delle sedi) lo provano i test di customer-account.
 */

// `__t()` esiste solo a sito avviato: una finta che legge i file di lingua.
if (!function_exists('__t')) {
    function __t(string $key, array $replacements = []): string
    {
        static $file = [];
        [$nome, $resto] = explode('.', $key, 2) + [1 => ''];
        $percorso = $nome === 'contacts'
            ? __DIR__.'/../vendor/wonder-image/customer-account/lang/it/contacts.json'
            : __DIR__.'/../lang/it/'.$nome.'.json';
        $file[$nome] ??= (array) json_decode((string) @file_get_contents($percorso), true);
        $nodo = $file[$nome];

        foreach (explode('.', $resto) as $passo) {
            $nodo = is_array($nodo) ? ($nodo[$passo] ?? null) : null;
        }

        return is_string($nodo) ? $nodo : $key;
    }
}

$forza = static function (?array $stato): void {
    (new ReflectionProperty(Gestionale::class, 'features'))->setValue(null, $stato);
    // Il CRM di customer-account resta acceso finché non lo si spegne: ogni test riparte da spento.
    \Wonder\Plugin\CustomerAccount\Support\Crm::enable(false);
};

/** Le chiavi del form di una Resource. @return list<string> */
$chiavi = static fn (string $resource): array => array_map(static fn ($campo): string => (string) $campo->name, $resource::formSchema());

/** Le colonne del repeater degli indirizzi. @return list<string> */
$colonneIndirizzi = static function (string $resource): array {
    foreach ($resource::formSchema() as $campo) {
        if ((string) $campo->name === 'addresses') {
            $nome = new ReflectionProperty($campo->get('context')['columns'][0], 'name');

            return array_map(static fn ($c): string => (string) $nome->getValue($c), (array) $campo->get('context')['columns']);
        }
    }

    return [];
};

// --- Indirizzi per brand ---

$sedi = [
    ['id' => 1, 'position' => 2, 'is_default' => 'false', 'brand_ids' => [5]],
    ['id' => 2, 'position' => 0, 'is_default' => 'true', 'brand_ids' => []],
    ['id' => 3, 'position' => 1, 'is_default' => 'false', 'brand_ids' => [7]],
    ['id' => 4, 'position' => 3, 'is_default' => 'false', 'brand_ids' => [], 'deleted' => 'true'],
    ['id' => 6, 'position' => 4, 'is_default' => 'false', 'brand_ids' => []],
];

check('le sedi per brand: prima quelle del brand, poi quelle per tutti con la predefinita in testa', function () use ($sedi) {
    $ids = static fn (array $rows): array => array_column($rows, 'id');

    return $ids(OfficeOrders::forBrand($sedi, 5)) === [1, 2, 6]
        && $ids(OfficeOrders::forBrand($sedi, 9)) === [2, 6]
        && $ids(OfficeOrders::forBrand($sedi, 0)) === [2, 3, 1, 6];
});

check('cambiare brand non sovrascrive una sede scelta o scritta: lo script la tiene', function () {
    $vista = (string) file_get_contents(__DIR__.'/../view/pages/office-order-form.php');
    $cambio = substr($vista, (int) strpos($vista, "field('company_brand_id').addEventListener('change'"), 1200);

    return str_contains($vista, 'var touched = false;')
        // Spuntare una sede, salvarne una dalla finestra o riaprire un documento la fissano.
        && substr_count($vista, 'touched = true;') === 3
        && str_contains($cambio, 'brandId()')
        && str_contains($cambio, 'if (touched) {')
        && strpos($cambio, 'if (touched) {') < strpos($cambio, 'check(parseInt(data.proposed, 10) || 0);')
        // Il brand cambiato dallo script (scelta del cliente) non rilegge le sedi.
        && str_contains($cambio, 'if (autoBrand ||');
});

check('il repeater degli indirizzi di un\'azienda ha i brand (vuoto = tutti), solo con «Brand»', function () use ($forza, $colonneIndirizzi, $chiavi) {
    $forza(['private_customers' => true, 'companies' => true, 'purchasing' => true, 'brands' => true]);
    $azienda = $colonneIndirizzi(CompanyResource::class);
    $fornitore = $colonneIndirizzi(SupplierResource::class);
    $persona = $colonneIndirizzi(CustomerResource::class);
    $marcatore = in_array(CustomerResource::ADDRESS_BRANDS_MARKER, $chiavi(CompanyResource::class), true);
    $forza(['private_customers' => true, 'companies' => false, 'purchasing' => true, 'brands' => true]);
    $soloPersone = $colonneIndirizzi(SupplierResource::class);
    $forza(['private_customers' => true, 'companies' => true, 'purchasing' => true]);
    $senzaBrand = $colonneIndirizzi(CompanyResource::class);
    $senzaMarcatore = !in_array(CustomerResource::ADDRESS_BRANDS_MARKER, $chiavi(CompanyResource::class), true);
    $forza(null);

    return in_array('brand_ids', $azienda, true) && in_array('brand_ids', $fornitore, true) && $marcatore
        && !in_array('brand_ids', $persona, true) && !in_array('brand_ids', $soloPersone, true)
        && !in_array('brand_ids', $senzaBrand, true) && $senzaMarcatore;
});

check('i brand non vanno al salvataggio della riga dell\'indirizzo', function () {
    $riga = CompanyResource::prepareRepeaterRelationRow('addresses', ['street' => 'Via Roma', 'brand_ids' => [5]], []);
    $altro = CompanyResource::prepareRepeaterRelationRow('altro', ['brand_ids' => [5]], []);

    return !array_key_exists('brand_ids', $riga) && ($riga['street'] ?? '') === 'Via Roma' && isset($altro['brand_ids']);
});

// --- Altri contatti ---

check('con il CRM il riquadro «Contatti» è uno solo, una riga per recapito; «Ufficio» singolo solo per le aziende', function () use ($chiavi, $forza) {
    $forza(['private_customers' => true, 'companies' => true, 'purchasing' => true, 'crm' => true]);

    try {
        foreach ([CustomerResource::class, CompanyResource::class, SupplierResource::class] as $resource) {
            $campi = $chiavi($resource);

            if (array_diff(['contact_points', 'contact_box_form'], $campi) !== []
                || array_intersect(['contact_points_email', 'contact_points_phone', 'phone_prefix', 'phone', 'contact_points_form'], $campi) !== []) {
                return false;
            }
        }

        $colonne = static function (string $resource): array {
            foreach ($resource::formSchema() as $campo) {
                if ($campo->name === 'contact_points') {
                    return array_map(static fn ($c): string => $c->name, (array) $campo->get('context')['columns']);
                }
            }

            return [];
        };
        $ufficio = null;

        foreach (CompanyResource::formSchema() as $campo) {
            foreach ($campo->name === 'contact_points' ? (array) $campo->get('context')['columns'] : [] as $colonna) {
                $ufficio = $colonna->name === 'department' ? $colonna : $ufficio;
            }
        }

        $azienda = $colonne(CompanyResource::class);
        $persona = $colonne(CustomerResource::class);

        // L'email di una persona è quella di accesso, in un campo a parte; l'azienda la tiene nel riquadro.
        return in_array('email', $chiavi(CustomerResource::class), true) && !in_array('email', $chiavi(CompanyResource::class), true)
            && array_intersect(['kind', 'email', 'phone_prefix', 'phone', 'department'], $azienda) === ['kind', 'email', 'phone_prefix', 'phone', 'department']
            && in_array('kind', $persona, true) && !in_array('department', $persona, true)
            && array_intersect(['departments', 'is_primary', 'label'], [...$azienda, ...$persona]) === []
            // Un ufficio solo, con la ricerca e il «+».
            && $ufficio !== null && ($ufficio->get('context')['multiple'] ?? false) !== true && $ufficio->get('label') === 'Ufficio'
            && CustomerResource::officeTypeResource() === \Wonder\Plugin\Gestionale\Resources\Contacts\OfficeTypeResource::class
            && CustomerResource::contactPointOwner() === 'contact' && CompanyResource::contactPointOwner() === 'company';
    } finally {
        $forza(null);
    }
});

check('senza CRM un\'email e un telefono soli, niente social; con il CRM i social solo per le persone', function () use ($chiavi, $forza) {
    $forza(['private_customers' => true, 'companies' => true, 'purchasing' => true]);

    try {
        foreach ([CustomerResource::class, CompanyResource::class, SupplierResource::class] as $resource) {
            $campi = $chiavi($resource);

            if (array_diff(['email', 'phone_prefix', 'phone', 'contact_box_form'], $campi) !== []
                || array_intersect(['contact_points', 'socials', 'socials_form'], $campi) !== []) {
                return false;
            }
        }

        $forza(['private_customers' => true, 'companies' => true, 'crm' => true]);

        return array_diff(['socials', 'socials_form'], $chiavi(CustomerResource::class)) === []
            && array_intersect(['socials', 'socials_form'], $chiavi(CompanyResource::class)) === [];
    } finally {
        $forza(null);
    }
});

check('i social di una persona si salvano solo con il CRM; spento la colonna resta com\'è', function () use ($forza) {
    $post = ['name' => 'Mario', 'socials_form' => '1', 'socials' => [['network' => 'instagram', 'url' => 'https://instagram.com/mario']]];
    $forza(['private_customers' => true, 'companies' => true]);

    try {
        $spento = CustomerResource::mutateRequestValues($post, 'store');
        $forza(['private_customers' => true, 'companies' => true, 'crm' => true]);
        $acceso = CustomerResource::mutateRequestValues($post, 'store');
        $senzaForm = CustomerResource::mutateRequestValues(['name' => 'Mario'], 'store');
        $azienda = CompanyResource::mutateRequestValues(['business_name' => 'Rossi Srl'] + $post, 'store');

        try {
            CustomerResource::mutateRequestValues(['name' => 'Mario', 'socials_form' => '1', 'socials' => [['network' => 'instagram', 'url' => 'javascript:alert(1)']]], 'store');
            $fermato = false;
        } catch (InvalidArgumentException) {
            $fermato = true;
        }
    } finally {
        $forza(null);
    }

    return !array_key_exists('socials', $spento) && !array_key_exists('socials_form', $spento)
        && str_contains((string) ($acceso['socials'] ?? ''), 'instagram.com/mario') && !array_key_exists('socials_form', $acceso)
        && !array_key_exists('socials', $senzaForm) && !array_key_exists('socials', $azienda) && $fermato;
});

check('dal riquadro il principale va nei campi della scheda, il resto e il marker no; una riga sbagliata ferma', function () use ($forza) {
    $forza(['private_customers' => true, 'companies' => true, 'crm' => true]);

    try {
        $valori = CustomerResource::mutateRequestValues(['name' => 'Mario', 'email' => 'mario@example.com', 'contact_box_form' => '1', 'contact_points' => [
            ['kind' => 'phone', 'phone_prefix' => '+39', 'phone' => '333 1234567', 'is_primary' => 'false'],
        ]], 'store');
        $azienda = CompanyResource::mutateRequestValues(['business_name' => 'Rossi Srl', 'contact_box_form' => '1', 'contact_points' => [
            ['kind' => 'email', 'email' => 'Info@Rossi.it', 'department' => 'administration'],
            ['kind' => 'email', 'email' => 'acquisti@rossi.it', 'department' => 'purchasing'],
        ]], 'store');

        try {
            CompanyResource::mutateRequestValues(['business_name' => 'Rossi Srl', 'contact_box_form' => '1', 'contact_points' => [
                ['kind' => 'email', 'email' => 'non-email', 'is_primary' => 'true'],
            ]], 'store');
            $fermato = false;
        } catch (InvalidArgumentException) {
            $fermato = true;
        }
    } finally {
        $forza(null);
    }

    // L'email di accesso della persona arriva dal suo campo e resta fra i valori; quella dell'azienda è la prima riga.
    return ($valori['phone'] ?? '') === '3331234567' && ($valori['phone_prefix'] ?? '') === '+39' && ($valori['email'] ?? null) === 'mario@example.com'
        && !array_key_exists('contact_points', $valori) && !array_key_exists('contact_box_form', $valori)
        && ($azienda['email'] ?? '') === 'info@rossi.it' && !array_key_exists('contact_points', $azienda) && $fermato;
});

check('senza CRM email e telefono del form vanno nei campi della scheda; il marker no', function () use ($forza) {
    $forza(['private_customers' => true, 'companies' => true]);

    try {
        $azienda = CompanyResource::mutateRequestValues(['business_name' => 'Rossi Srl', 'contact_box_form' => 'single', 'email' => 'Info@Rossi.it', 'phone_prefix' => '+39', 'phone' => '02 1234567'], 'store');
        // L'email di accesso, dal suo campo, si pulisce come un contatto: minuscola, senza spazi, formato giusto.
        $persona = CustomerResource::mutateRequestValues(['name' => 'Mario', 'contact_box_form' => 'single', 'email' => ' Mario@Example.com ', 'phone_prefix' => '+39', 'phone' => '333 1234567'], 'store');

        try {
            CustomerResource::mutateRequestValues(['name' => 'Mario', 'contact_box_form' => 'single', 'email' => 'non-email', 'phone_prefix' => '+39', 'phone' => ''], 'store');
            $fermato = false;
        } catch (InvalidArgumentException) {
            $fermato = true;
        }
    } finally {
        $forza(null);
    }

    return $fermato && ($azienda['email'] ?? '') === 'info@rossi.it' && ($azienda['phone'] ?? '') === '021234567' && !array_key_exists('contact_box_form', $azienda)
        && ($persona['email'] ?? '') === 'mario@example.com' && ($persona['phone'] ?? '') === '3331234567' && !array_key_exists('contact_box_form', $persona);
});

check('senza riquadro (creazione rapida, finestre, API) i campi singoli passano come prima', function () {
    $valori = CustomerResource::mutateRequestValues(['name' => 'Mario', 'email' => 'mario@example.com', 'phone' => '3331234567'], 'store');

    return ($valori['email'] ?? '') === 'mario@example.com' && ($valori['phone'] ?? '') === '3331234567';
});

check('l\'email di accesso di chi ha un account non cambia: dal riquadro si ignora, da un campo singolo ferma', function () {
    // Id 0 e `store`: i controlli che leggono la scheda dal database non partono; il blocco guarda la scheda salvata.
    $salvata = ['id' => 0, 'user_id' => 9, 'email' => 'mario@example.com', 'name' => 'Mario', 'is_customer' => 'true'];
    $errore = static function (callable $fn): string {
        try {
            $fn();
        } catch (\Wonder\Plugin\Gestionale\Support\Errors\UserError $e) {
            return $e->key();
        }

        return '';
    };
    $stessa = CustomerResource::mutateRequestValues(['name' => 'Mario', 'email' => 'Mario@Example.com'], 'store', 'backend', $salvata);
    $riquadro = CustomerResource::mutateRequestValues(['name' => 'Mario', 'contact_box_form' => '1', 'contact_points' => [
        ['kind' => 'email', 'email' => 'nuova@example.com', 'is_primary' => 'true'],
    ]], 'store', 'backend', $salvata);
    $azienda = CompanyResource::mutateRequestValues(['business_name' => 'Rossi Srl', 'email' => 'info@rossi.it'], 'store', 'backend', ['id' => 0, 'user_id' => 9, 'email' => 'x@rossi.it', 'is_customer' => 'true']);

    return !array_key_exists('email', $stessa) && !array_key_exists('email', $riquadro)
        && $errore(static fn () => CustomerResource::mutateRequestValues(['name' => 'Mario', 'email' => 'altra@example.com'], 'store', 'backend', $salvata)) === 'contact.access_email_locked'
        && ($azienda['email'] ?? '') === 'info@rossi.it';
});

check('nella scheda in lettura tutti i contatti, escapati: principale, email di accesso, etichetta, aree', function () {
    $voci = CustomerSheet::points([
        ['kind' => 'email', 'value' => 'mario@example.com', 'label' => 'access', 'is_primary' => true],
        ['kind' => 'email', 'value' => 'a<b>@example.com', 'label' => 'work', 'phone_prefix' => '', 'departments' => ['administration']],
        ['kind' => 'phone', 'value' => '3331234567', 'label' => '', 'phone_prefix' => '+39', 'is_primary' => true],
    ], ['work' => 'Lavoro', 'access' => 'Email di accesso'], ['administration' => 'Amministrazione']);
    $html = $voci === [] ? '' : $voci[0]->render('bootstrap');
    $vuota = CustomerSheet::points([])[0]->render('bootstrap');
    $senzaId = CustomerResource::pointItems(0, ['email' => 'anna@example.com']);

    return count($voci) === 1
        && str_contains($html, 'a&lt;b&gt;@example.com') && !str_contains($html, '<b>')
        && str_contains($html, 'mario@example.com · Email di accesso · principale') === false
        && str_contains($html, 'Email di accesso · principale') && str_contains($html, 'Lavoro · Amministrazione')
        && str_contains($html, '+39 3331234567') && str_contains($vuota, 'Nessun recapito')
        && str_contains($senzaId[0]->render('bootstrap'), 'anna@example.com');
});

check('con «CRM» la scheda in lettura dice il tipo di ogni recapito e i social della persona; senza resta com\'era', function () use ($forza) {
    $scheda = ['email' => 'anna@example.com', 'phone' => '3331234567', 'phone_prefix' => '+39', 'phone_kind' => 'mobile',
        'socials' => '{"instagram":"https://www.instagram.com/anna/","linkedin":"https://linkedin.com/in/anna"}'];
    $html = static fn (array $voci): string => implode('', array_map(static fn ($voce): string => $voce->render('bootstrap'), $voci));

    $forza(['private_customers' => true, 'companies' => true]);
    $spento = CustomerResource::pointItems(0, $scheda);
    $forza(['private_customers' => true, 'companies' => true, 'crm' => true]);
    $persona = $html(CustomerResource::pointItems(0, $scheda));
    $azienda = CompanyResource::pointItems(0, $scheda);
    $vuota = $html(CustomerResource::pointItems(0, ['email' => 'anna@example.com']));
    $forza(null);
    $tipi = $html(CustomerSheet::points([
        ['kind' => 'whatsapp', 'value' => '3330000000', 'phone_prefix' => '+39', 'label' => 'work'],
        ['kind' => 'phone', 'value' => '021234567', 'phone_prefix' => '+39'],
    ], ['work' => 'Lavoro'], [], ['whatsapp' => 'WhatsApp', 'phone' => 'Telefono fisso']));
    // Chi stampa non si fida di quello che legge: solo http(s), tutto escapato.
    $sporchi = $html(CustomerSheet::socials(
        ['x' => 'javascript:alert(1)', 'facebook' => 'https://facebook.com/a"><b>', 'mia' => 'https://example.com/<i>'],
        ['facebook' => 'Face<b>book'], 'Social', 'Nessun social'
    ));

    return count($spento) === 1 && !str_contains($html($spento), 'Cellulare') && !str_contains($html($spento), 'Social')
        && str_contains($persona, 'anna@example.com <span class="text-muted">· Email · principale')
        && str_contains($persona, '+39 3331234567 <span class="text-muted">· Cellulare · principale')
        && str_contains($persona, 'Social')
        && preg_match('#<a[^>]*href="https://www\.instagram\.com/anna/"[^>]*>instagram\.com/anna</a> <span class="text-muted">· Instagram#', $persona) === 1
        && str_contains($persona, 'target="_blank"') && str_contains($persona, 'rel="noopener"')
        && str_contains($persona, '>linkedin.com/in/anna</a> <span class="text-muted">· LinkedIn')
        && !str_contains($sporchi, 'javascript:') && !str_contains($sporchi, '<b>') && !str_contains($sporchi, '<i>')
        && str_contains($sporchi, 'Face&lt;b&gt;book') && str_contains($sporchi, '· mia')
        && count($azienda) === 1 && str_contains($html($azienda), 'Cellulare')
        && str_contains($vuota, 'Nessun social') && str_contains($vuota, 'Email · principale')
        && str_contains($tipi, '+39 3330000000 <span class="text-muted">· WhatsApp · Lavoro')
        && str_contains($tipi, '+39 021234567 <span class="text-muted">· Telefono fisso');
});

check('la scheda del referente legge gli altri contatti come quella del cliente', function () {
    $codice = (string) file_get_contents(__DIR__.'/../src/Resources/Contacts/CustomerResource.php');
    $referente = substr($codice, (int) strpos($codice, 'function referentLayoutSchema'), 1500);

    return str_contains($referente, 'static::pointItems($id, $contact)')
        && substr_count($codice, 'static::pointItems($id, $contact)') === 2;
});

// --- Dati del brand ---

check('il brand del gestionale eredita logo, sito, domini email e descrizione', function () use ($chiavi) {
    $campi = $chiavi(CompanyBrandResource::class);

    return array_values(array_intersect(['name', 'logo', 'website', 'domains', 'description'], $campi)) === ['name', 'logo', 'website', 'domains', 'description'];
});

check('«Brand kit» nel pannello: Anagrafiche, spenta di serie, richiede «Brand»; accesa mostra il kit', function () use ($forza, $chiavi) {
    $voce = FeatureCatalog::all()['brand_kit'] ?? [];
    // Senza CRM il kit ha un'email e un telefono soli e nessun social.
    $forza(['companies' => true, 'brands' => true, 'brand_kit' => true]);
    $senzaCrm = $chiavi(CompanyBrandResource::class);
    $forza(['companies' => true, 'brands' => true, 'brand_kit' => true, 'crm' => true]);
    $conKit = $chiavi(CompanyBrandResource::class);
    $ufficio = CompanyBrandResource::officeTypeResource();
    $forza(null);
    // Il gestionale accende il CRM di customer-account e non lo spegne: qui si rimette com'era.
    \Wonder\Plugin\CustomerAccount\Support\Crm::enable(false);
    \Wonder\Plugin\CustomerAccount\Support\CompanyBrands::useKit(false);

    return ($voce['area'] ?? '') === 'Anagrafiche' && ($voce['default'] ?? true) === false
        && ($voce['requires'] ?? []) === ['brands']
        && in_array('socials', $conKit, true) && in_array('contacts', $conKit, true) && in_array('colors', $conKit, true) && !in_array('email', $conKit, true)
        && !in_array('socials', $senzaCrm, true) && !in_array('contacts', $senzaCrm, true) && in_array('colors', $senzaCrm, true)
        && array_values(array_intersect(['email', 'phone_prefix', 'phone'], $senzaCrm)) === ['email', 'phone_prefix', 'phone']
        && $ufficio === \Wonder\Plugin\Gestionale\Resources\Contacts\OfficeTypeResource::class;
});

check('scheda del brand: il riquadro «Indirizzi» solo in modifica, senza «Presso» né «Aggiungi»; in creazione non c\'è', function () use ($forza, $chiavi) {
    $forza(['companies' => true, 'brands' => true]);
    $prima = $_SERVER['REQUEST_URI'] ?? null;
    $_SERVER['REQUEST_URI'] = '/backend/app/gestionale/brand/';
    $crea = $chiavi(CompanyBrandResource::class);
    $carteCrea = count(CompanyBrandResource::formLayoutSchema()->components);
    $_SERVER['REQUEST_URI'] = '/backend/app/gestionale/brand/7/?modify=7';
    $modifica = $chiavi(CompanyBrandResource::class);
    $colonne = [];
    $aggiungi = null;

    foreach (CompanyBrandResource::formSchema() as $campo) {
        if ($campo->name === CompanyBrandResource::ADDRESSES_INPUT) {
            $contesto = $campo->get('context');
            $colonne = array_map(static fn ($colonna): string => (string) $colonna->name, $contesto['columns'] ?? []);
            $aggiungi = $contesto;
        }
    }

    // Senza database il brand non ha aziende: il riquadro c'è, con la frase al posto delle righe.
    $carte = CompanyBrandResource::formLayoutSchema()->components;
    $tema = \Wonder\App\Theme::get();
    \Wonder\App\Theme::set('bootstrap');
    $html = \Wonder\Backend\Support\ResourceFormLayoutRenderer::renderLayout($carte[1]);
    \Wonder\App\Theme::set($tema);
    // Con una riga: i campi ci sono, il cestino no (toglierla non cancellerebbe niente).
    // Paese e provincia leggono gli elenchi dal database: qui si lasciano fuori.
    $campoRighe = (new ReflectionMethod(CompanyBrandResource::class, 'addressesField'))->invoke(null);
    $righe = $campoRighe
        ->columns(array_values(array_filter(
            (array) ($campoRighe->get('context')['columns'] ?? []),
            static fn ($colonna): bool => !in_array((string) $colonna->name, ['country', 'province'], true)
        )))
        ->value(['row_1' => ['id' => 1, 'label' => 'Sede', 'street' => 'Via Roma']])
        ->render('bootstrap');
    $righe = substr($righe, 0, (int) strpos($righe, '<script'));
    $valori = CompanyBrandResource::mutateFormValues(['id' => 7, 'name' => 'La Piana'], 'edit');
    // In creazione, e senza il segnaposto, il salvataggio non tocca gli indirizzi.
    $creato = CompanyBrandResource::syncRepeaterRelations(8, [CompanyBrandResource::ADDRESSES_MARKER => '1', CompanyBrandResource::ADDRESSES_INPUT => [['id' => 1, 'label' => 'x']]], [], 'store');
    $senzaSegno = CompanyBrandResource::syncRepeaterRelations(7, [CompanyBrandResource::ADDRESSES_INPUT => [['id' => 1, 'label' => 'x']]], [], 'update');
    $conSegno = CompanyBrandResource::syncRepeaterRelations(7, [CompanyBrandResource::ADDRESSES_MARKER => '1', CompanyBrandResource::ADDRESSES_INPUT => [['id' => 1, 'label' => 'x']]], [], 'update');

    if ($prima === null) {
        unset($_SERVER['REQUEST_URI']);
    } else {
        $_SERVER['REQUEST_URI'] = $prima;
    }

    $forza(null);

    return !in_array(CompanyBrandResource::ADDRESSES_INPUT, $crea, true) && !in_array(CompanyBrandResource::ADDRESSES_MARKER, $crea, true) && $carteCrea === 1
        && in_array(CompanyBrandResource::ADDRESSES_INPUT, $modifica, true) && in_array(CompanyBrandResource::ADDRESSES_MARKER, $modifica, true)
        && $colonne === ['id', 'company_name', 'scope', 'label', 'street', 'number', 'country', 'province', 'city', 'cap', 'place_id', 'lat', 'lng']
        && ($aggiungi['nested'] ?? false) === true && ($aggiungi['add_button'] ?? true) === false
        && ($aggiungi['delete_button'] ?? true) === false
        && str_contains($righe, 'name="brand_addresses[row_1][label]"') && str_contains($righe, 'value="Via Roma"')
        && str_contains($righe, '</template>') && !str_contains($righe, 'wi-repeater-delete') && !str_contains($righe, 'wiRepeaterRemoveRow') && !str_contains($righe, 'wiRepeaterAddRow')
        && count($carte) === 2
        && str_contains($html, 'Indirizzi') && str_contains($html, 'si aggiungono dalla scheda dell') && !str_contains($html, 'brand_addresses[')
        && !str_contains($html, '—') && !str_contains($html, '<wi-card')
        && $valori[CompanyBrandResource::ADDRESSES_INPUT] === []
        && !isset($creato[CompanyBrandResource::ADDRESSES_INPUT]) && !isset($senzaSegno[CompanyBrandResource::ADDRESSES_INPUT])
        && $conSegno[CompanyBrandResource::ADDRESSES_INPUT] === ['updated' => []];
});

check('il logo del brand: URL solo http(s) o del sito, tutto escapato', function () {
    $buono = DocumentBrands::logoHtml('https://example.com/logo.png?a=1&b="2"', 'Rossi <C>');

    return str_contains($buono, 'src="https://example.com/logo.png?a=1&amp;b=&quot;2&quot;"')
        && str_contains($buono, 'alt="Rossi &lt;C&gt;"')
        && DocumentBrands::logoHtml('javascript:alert(1)', 'x') === ''
        && DocumentBrands::logoHtml('//evil.example/x.png', 'x') === ''
        && DocumentBrands::safeUrl('/uploads/contacts/brand-logos/a.png') === '/uploads/contacts/brand-logos/a.png'
        && DocumentBrands::logoOf(['company_id' => 7, 'company_brand_id' => 0]) === '';
});

check('lo script mette il logo con il DOM, senza HTML', function () {
    $vista = (string) file_get_contents(__DIR__.'/../view/pages/office-order-form.php');

    return str_contains($vista, "document.createElement('img')") && str_contains($vista, 'data-wi-oo-brand-logo')
        && !preg_match('/innerHTML\s*=\s*[^;]*logo/', $vista);
});

// --- MCP ---

check('customer_get espone aree, altri contatti, brand e sedi per brand, con lo stesso permesso', function () use ($forza) {
    $forza(['private_customers' => true, 'companies' => true, 'brands' => true]);
    $tool = ContactTools::all()['customer_get'];
    $forza(['private_customers' => true, 'companies' => true]);
    $senzaBrand = ContactTools::all()['customer_get'];
    $forza(null);
    $schema = json_encode($tool['schema']);

    return str_contains($schema, 'brand_id')
        && str_contains($tool['description'], 'departments') && str_contains($tool['description'], 'contact_points')
        && str_contains($tool['description'], 'brands') && str_contains($tool['description'], 'is_primary')
        && str_contains($tool['description'], 'work_email') && str_contains($tool['description'], 'place_id')
        && $tool['permission'] === [CustomerResource::class, 'view'] && $tool['write'] === false
        && !str_contains(json_encode($senzaBrand['schema']), 'brand_id') && !str_contains($senzaBrand['description'], 'brand')
        && str_contains($senzaBrand['description'], 'departments');
});

check('customer_get non dice più chi è il referente principale o chi riceve i documenti; con «CRM» ha tipi, recapiti dei referenti e social', function () use ($forza) {
    $forza(['private_customers' => true, 'companies' => true]);
    $senza = ContactTools::all()['customer_get']['description'];
    $forza(['private_customers' => true, 'companies' => true, 'crm' => true]);
    $con = ContactTools::all()['customer_get']['description'];
    $forza(null);
    $codice = (string) file_get_contents(__DIR__.'/../src/Support/Mcp/ContactTools.php');
    $legami = substr($codice, (int) strpos($codice, 'private static function relations'));

    return !str_contains($senza, 'riceve i documenti') && !str_contains($senza, 'principale, ')
        && !str_contains($codice, 'receives_documents') && !str_contains($legami, 'is_primary')
        // `is_primary` resta sui recapiti della scheda.
        && str_contains($senza, 'is_primary') && str_contains($codice, "'is_primary' => !empty(\$point['is_primary'])")
        && !str_contains($senza, 'socials') && !str_contains($senza, '`points`')
        && str_contains($con, 'socials') && str_contains($con, '`points`') && str_contains($con, 'whatsapp')
        && str_contains($legami, "\$legame['points']") && str_contains($codice, "\$customer['socials'] = Socials::of(\$row)");
});

// --- Riquadro, fatturazione e finestre dell'ordine ---

/** I componenti di una card del form, per titolo. @return list<mixed> */
$card = static function (string $resource, string $titolo): array {
    $trovata = [];
    $scendi = function (array $componenti) use (&$scendi, &$trovata, $titolo): void {
        foreach ($componenti as $c) {
            if ($c instanceof \Wonder\Elements\Components\Container) {
                $scendi($c->components);
            } elseif ($c instanceof \Wonder\Elements\Components\Card && ($c->components[0] ?? null) instanceof \Wonder\Elements\Components\SectionTitle
                && (string) (new ReflectionProperty($c->components[0], 'text'))->getValue($c->components[0]) === $titolo) {
                $trovata = $c->components;
            }
        }
    };
    $scendi($resource::formLayoutSchema()->components);

    return $trovata;
};

check('la card dei recapiti ha il riquadro, il marker e lo script; la fatturazione place ID e coordinate nascosti', function () use ($card, $forza) {
    $nomi = static fn (array $componenti): array => array_values(array_filter(array_map(static fn ($c): string => $c instanceof \Wonder\App\ResourceSchema\Input ? $c->name : '', $componenti)));
    $larghezze = static fn (array $componenti): int => array_sum(array_map(static fn ($c): int => $c instanceof \Wonder\App\ResourceSchema\Input && !in_array($c->name, ['contact_box_form', 'place_id', 'lat', 'lng', 'more'], true) ? (int) ($c->columnSpan['default'] ?? 0) : 0, $componenti));
    $forza(['private_customers' => true, 'companies' => true, 'crm' => true]);

    try {
        $contatti = $card(CompanyResource::class, (string) __t('gestionale.contacts.form.points_company'));
        $contattiPersona = $card(CustomerResource::class, (string) __t('gestionale.contacts.form.points_person'));
        $social = $card(CustomerResource::class, (string) __t('contacts.person_socials'));
        $socialAzienda = $card(CompanyResource::class, (string) __t('contacts.person_socials'));
        $fatturazione = $card(CompanyResource::class, 'Dati di fatturazione');
        $persona = $card(CustomerResource::class, 'Dati di fatturazione');
        $via = static function (array $componenti): string {
            foreach ($componenti as $c) {
                if ($c instanceof \Wonder\App\ResourceSchema\Input && $c->name === 'street') {
                    return (string) $c->get('attribute');
                }
            }

            return '';
        };
        $script = array_values(array_map(static fn ($c): string => (string) (new ReflectionProperty($c, 'text'))->getValue($c), array_filter($contatti, static fn ($c): bool => $c instanceof \Wonder\Elements\Components\RichText)));

        $forza(['private_customers' => true, 'companies' => true]);
        $semplice = $card(CompanyResource::class, (string) __t('gestionale.contacts.form.points_company'));
        $semplicePersona = $card(CustomerResource::class, (string) __t('gestionale.contacts.form.points_person'));
        $senzaSocial = $card(CustomerResource::class, (string) __t('contacts.person_socials'));
    } finally {
        $forza(null);
    }

    return $nomi($contatti) === ['contact_points', 'contact_box_form'] && $script !== [] && str_contains($script[0], 'caContactForm')
        // La persona ha l'«Email di accesso» sopra il riquadro; i social in una card loro, solo per lei.
        && $nomi($contattiPersona) === ['email', 'contact_points', 'contact_box_form']
        && $nomi($social) === ['socials', 'socials_form'] && $socialAzienda === [] && $senzaSocial === []
        // Senza CRM email, prefisso e telefono su una riga.
        && $nomi($semplice) === ['email', 'phone_prefix', 'phone', 'contact_box_form'] && $larghezze($semplice) === 12
        && $nomi($semplicePersona) === ['email', 'phone_prefix', 'phone', 'contact_box_form'] && $larghezze($semplicePersona) === 12
        // Le righe della fatturazione riempiono la griglia: azienda 12 + 12 + 12, persona 12 + 12.
        && $larghezze($fatturazione) === 36 && $larghezze($persona) === 24
        && array_slice($nomi($fatturazione), -3) === ['place_id', 'lat', 'lng'] && array_slice($nomi($persona), -3) === ['place_id', 'lat', 'lng']
        && str_contains($via($fatturazione), 'data-wi-place-names') && str_contains($via($fatturazione), 'data-ca-place-name="business_name"')
        && !str_contains($via($persona), 'data-ca-place-name');
});

check('l\'«Email di accesso» della persona: modificabile senza account, bloccata con; l\'aiuto è nel titolo', function () use ($card, $forza) {
    $forza(['private_customers' => true, 'companies' => true, 'crm' => true]);

    try {
        $componenti = $card(CustomerResource::class, (string) __t('gestionale.contacts.form.points_person'));
        $email = null;

        foreach ($componenti as $c) {
            $email = $c instanceof \Wonder\App\ResourceSchema\Input && $c->name === 'email' ? $c : $email;
        }

        $aiuto = (string) ($componenti[0]->getSchema()['tooltip'] ?? '');
        $libera = CustomerResource::mutateRequestValues(['name' => 'Mario', 'email' => 'nuova@example.com', 'contact_box_form' => '1', 'contact_points' => []], 'store', 'backend', ['id' => 0, 'email' => 'mario@example.com', 'name' => 'Mario', 'is_customer' => 'true']);
    } finally {
        $forza(null);
    }

    return $email !== null && $email->get('label') === (string) __t('contacts.point_label_access')
        && !str_contains((string) $email->get('attribute'), 'readonly')
        && str_contains($aiuto, (string) __t('contacts.access_email_help'))
        && ($libera['email'] ?? '') === 'nuova@example.com';
});

check('place ID e coordinate della fatturazione si ripuliscono, e solo se arrivano', function () {
    $sporchi = CustomerResource::mutateRequestValues(['name' => 'Mario', 'place_id' => 'x<y', 'lat' => '91', 'lng' => '9.1'], 'store');
    $buoni = CustomerResource::mutateRequestValues(['name' => 'Mario', 'place_id' => 'ChIJabc_def-123', 'lat' => '45.4642', 'lng' => '9.19'], 'store');
    $senza = CustomerResource::mutateRequestValues(['name' => 'Mario'], 'store');

    return $sporchi['place_id'] === null && $sporchi['lat'] === null && $sporchi['lng'] === null
        && $buoni['place_id'] === 'ChIJabc_def-123' && $buoni['lat'] === 45.4642
        && !array_key_exists('lat', $senza);
});

check('le finestre dell\'ordine restano con i campi singoli; la fatturazione porta place ID e coordinate', function () {
    // La finestra ha il suo campo `email`, non quello «Email di accesso» della scheda (disegnarla vuole il sito avviato).
    $codice = (string) file_get_contents(__DIR__.'/../src/Support/Sales/OfficeCustomerPanel.php');

    return str_contains($codice, "FormField::key('email')->email()")
        && str_contains($codice, "\$campo('place_id', 12)") && str_contains($codice, "\$azienda('place_id', 12)")
        && in_array('place_id', OfficeCustomerPanel::BILLING_KEYS, true) && in_array('lng', OfficeCustomerPanel::COMPANY_BILLING_KEYS, true);
});

/** Le colonne (nome => larghezza) e il contesto di un repeater dei legami. @return array{0: array<string, int>, 1: array<string, mixed>, 2: list<string>} */
$legami = static function (string $resource, string $input): array {
    $colonne = [];
    $contesto = [];
    $campi = [];

    foreach ($resource::contactRelationFields() as $campo) {
        $campi[] = (string) $campo->name;

        if ($campo->name === $input) {
            $contesto = (array) $campo->get('context');

            foreach ((array) ($contesto['columns'] ?? []) as $colonna) {
                $colonne[$colonna->name] = (int) ($colonna->columnSpan['default'] ?? 0);
            }
        }
    }

    return [$colonne, $contesto, $campi];
};

check('la riga del referente: persona, ruolo e ufficio insieme, poi i contatti aziendali; niente persona nuova né opzioni', function () use ($forza, $legami) {
    $forza(['private_customers' => true, 'companies' => true]);

    try {
        [$colonne, $contesto] = $legami(CompanyResource::class, CompanyResource::RELATION_PEOPLE);
        [$aziende] = $legami(CustomerResource::class, CustomerResource::RELATION_COMPANIES);
        $forza(['private_customers' => true, 'companies' => true, 'crm' => true]);
        [$conCrm] = $legami(CompanyResource::class, CompanyResource::RELATION_PEOPLE);
        [$aziendeCrm] = $legami(CustomerResource::class, CustomerResource::RELATION_COMPANIES);
    } finally {
        $forza(null);
    }

    $tolte = ['new_name', 'new_surname', 'new_email', 'is_primary', 'receives_documents', 'access', 'departments'];

    // La spunta «Rappresentante legale» chiude la prima riga accanto all'ufficio.
    return ($colonne['person_id'] ?? 0) + ($colonne['job_title'] ?? 0) + ($colonne['department'] ?? 0) + ($colonne['is_legal_representative'] ?? 0) === 9
        && ($aziende['company_id'] ?? 0) + ($aziende['job_title'] ?? 0) + ($aziende['department'] ?? 0) + ($aziende['is_legal_representative'] ?? 0) === 9
        && ($colonne['is_legal_representative'] ?? 0) === 2 && ($aziendeCrm['is_legal_representative'] ?? 0) === 2
        // Senza CRM un'email e un telefono su una riga; con, il repeater dei «Contatti aziendali».
        && ($colonne['email'] ?? 0) + ($colonne['phone_prefix'] ?? 0) + ($colonne['phone'] ?? 0) === 9 && !isset($colonne['points'])
        && ($conCrm['points'] ?? 0) === 12 && !isset($conCrm['email']) && !isset($conCrm['phone'])
        && ($aziendeCrm['points'] ?? 0) === 12 && !isset($aziendeCrm['email'])
        && array_intersect($tolte, [...array_keys($colonne), ...array_keys($conCrm), ...array_keys($aziende), ...array_keys($aziendeCrm)]) === []
        && !isset($colonne['brand_ids']) && !isset($aziende['brand_ids'])
        && ($contesto['advanced'] ?? []) === [] && ($contesto['start_empty'] ?? null) === true;
});

check('i «Brand seguiti» del referente: solo con «Brand», visibili se l\'azienda ha più di un brand', function () use ($forza, $legami) {
    $forza(['private_customers' => true, 'companies' => true, 'brands' => true]);

    try {
        [$colonne, $contesto, $campi] = $legami(CompanyResource::class, CompanyResource::RELATION_PEOPLE);
        [, , $persona] = $legami(CustomerResource::class, CustomerResource::RELATION_COMPANIES);
        $brand = null;

        foreach ((array) ($contesto['columns'] ?? []) as $colonna) {
            $brand = $colonna->name === 'brand_ids' ? $colonna : $brand;
        }

        $forza(['private_customers' => true, 'companies' => true]);
        [$senza] = $legami(CompanyResource::class, CompanyResource::RELATION_PEOPLE);
    } finally {
        $forza(null);
    }

    $quando = $brand !== null ? json_encode($brand->get('context')).' '.(string) $brand->get('attribute') : '';

    return ($colonne['brand_ids'] ?? 0) === 12 && !isset($senza['brand_ids'])
        && in_array(CompanyResource::RELATION_BRANDS_MANY, $campi, true) && !in_array(CompanyResource::RELATION_BRANDS_MANY, $persona, true)
        && str_contains($quando, CompanyResource::RELATION_BRANDS_MANY);
});

check('una riga di referente senza persona: vuota si scarta, compilata ferma il salvataggio', function () use ($forza) {
    $forza(['private_customers' => true, 'companies' => true]);
    $prima = $_POST;
    $salva = static function (array $riga): string {
        $_POST = ['business_name' => 'Rossi Srl', 'contact_relations_form' => '1', 'contact_people' => [$riga]];

        try {
            CompanyResource::mutateRequestValues($_POST, 'store');
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return '';
    };

    try {
        $vuota = $salva(['person_id' => '', 'job_title' => '', 'department' => 'general', 'email' => '', 'phone_prefix' => '+39', 'phone' => '']);
        $compilata = $salva(['person_id' => '', 'job_title' => 'Titolare', 'department' => 'general']);
        // Con il CRM la riga appena aggiunta posta anche un recapito con i soli valori di partenza.
        $forza(['private_customers' => true, 'companies' => true, 'crm' => true]);
        $vuotaCrm = $salva(['person_id' => '', 'job_title' => '', 'department' => 'general', 'points' => [['kind' => 'email', 'email' => '', 'phone_prefix' => '+39', 'phone' => '']]]);
    } finally {
        $_POST = $prima;
        $forza(null);
    }

    return $vuota === '' && $vuotaCrm === '' && $compilata === (string) __t('contacts.relation_person_required');
});

summary();
