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

check('persone, aziende e fornitori hanno il riquadro «Contatti» al posto di email, prefisso, telefono e «Altri contatti»', function () use ($chiavi, $forza) {
    $forza(['private_customers' => true, 'companies' => true, 'purchasing' => true]);

    try {
        foreach ([CustomerResource::class, CompanyResource::class, SupplierResource::class] as $resource) {
            $campi = $chiavi($resource);

            if (!in_array('contact_points', $campi, true) || !in_array('contact_box_form', $campi, true)
                || array_intersect(['email', 'phone_prefix', 'phone', 'contact_points_form'], $campi) !== []) {
                return false;
            }
        }

        // «Si occupa di» sulle righe dei contatti solo per le aziende.
        $colonne = static function (string $resource): array {
            foreach ($resource::formSchema() as $campo) {
                if ($campo->name === 'contact_points') {
                    return array_map(static fn ($c): string => $c->name, (array) $campo->get('context')['columns']);
                }
            }

            return [];
        };

        return in_array('departments', $colonne(CompanyResource::class), true) && !in_array('departments', $colonne(CustomerResource::class), true)
            && CustomerResource::contactPointOwner() === 'contact' && CompanyResource::contactPointOwner() === 'company';
    } finally {
        $forza(null);
    }
});

check('dal riquadro il principale va nei campi della scheda, il resto e il marker no; una riga sbagliata ferma', function () {
    $valori = CustomerResource::mutateRequestValues(['name' => 'Mario', 'contact_box_form' => '1', 'contact_points' => [
        ['kind' => 'phone', 'phone_prefix' => '+39', 'phone' => '333 1234567', 'is_primary' => 'false'],
    ]], 'store');

    try {
        CustomerResource::mutateRequestValues(['name' => 'Mario', 'contact_box_form' => '1', 'contact_points' => [
            ['kind' => 'email', 'email' => 'non-email', 'is_primary' => 'true'],
        ]], 'store');
        $fermato = false;
    } catch (InvalidArgumentException) {
        $fermato = true;
    }

    return ($valori['phone'] ?? '') === '3331234567' && ($valori['phone_prefix'] ?? '') === '+39' && ($valori['email'] ?? null) === ''
        && !array_key_exists('contact_points', $valori) && !array_key_exists('contact_box_form', $valori) && $fermato;
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
        && str_contains($html, '+39 3331234567') && str_contains($vuota, '—')
        && str_contains($senzaId[0]->render('bootstrap'), 'anna@example.com');
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
    $forza(['companies' => true, 'brands' => true, 'brand_kit' => true]);
    $conKit = $chiavi(CompanyBrandResource::class);
    $forza(null);

    return ($voce['area'] ?? '') === 'Anagrafiche' && ($voce['default'] ?? true) === false
        && ($voce['requires'] ?? []) === ['brands']
        && in_array('socials', $conKit, true) && in_array('colors', $conKit, true);
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

check('la card «Contatti» ha il riquadro, il marker e lo script; la fatturazione place ID e coordinate nascosti', function () use ($card, $forza) {
    $forza(['private_customers' => true, 'companies' => true]);

    try {
        $nomi = static fn (array $componenti): array => array_values(array_filter(array_map(static fn ($c): string => $c instanceof \Wonder\App\ResourceSchema\Input ? $c->name : '', $componenti)));
        $contatti = $card(CompanyResource::class, 'Contatti');
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
    } finally {
        $forza(null);
    }

    return $nomi($contatti) === ['contact_points', 'contact_box_form'] && $script !== [] && str_contains($script[0], 'caContactForm')
        && array_slice($nomi($fatturazione), -3) === ['place_id', 'lat', 'lng'] && array_slice($nomi($persona), -3) === ['place_id', 'lat', 'lng']
        && str_contains($via($fatturazione), 'data-wi-place-names') && str_contains($via($fatturazione), 'data-ca-place-name="business_name"')
        && !str_contains($via($persona), 'data-ca-place-name');
});

check('place ID e coordinate della fatturazione si ripuliscono, e solo se arrivano', function () {
    $sporchi = CustomerResource::mutateRequestValues(['name' => 'Mario', 'place_id' => 'x<y', 'lat' => '91', 'lng' => '9.1'], 'store');
    $buoni = CustomerResource::mutateRequestValues(['name' => 'Mario', 'place_id' => 'ChIJabc_def-123', 'lat' => '45.4642', 'lng' => '9.19'], 'store');
    $senza = CustomerResource::mutateRequestValues(['name' => 'Mario'], 'store');

    return $sporchi['place_id'] === null && $sporchi['lat'] === null && $sporchi['lng'] === null
        && $buoni['place_id'] === 'ChIJabc_def-123' && $buoni['lat'] === 45.4642
        && !array_key_exists('lat', $senza);
});

check('le finestre dell\'ordine restano con i campi singoli; la fatturazione porta place ID e coordinate', function () use ($forza) {
    $forza(['private_customers' => true, 'companies' => true]);

    try {
        // La scheda non ha più il campo `email`: la finestra se lo fa da sé (disegnarla vuole il sito avviato).
        CustomerResource::getInput('email');
        $campoEmail = true;
    } catch (RuntimeException) {
        $campoEmail = false;
    } finally {
        $forza(null);
    }

    $codice = (string) file_get_contents(__DIR__.'/../src/Support/Sales/OfficeCustomerPanel.php');

    return !$campoEmail && str_contains($codice, "FormField::key('email')->email()")
        && str_contains($codice, "\$campo('place_id', 12)") && str_contains($codice, "\$azienda('place_id', 12)")
        && in_array('place_id', OfficeCustomerPanel::BILLING_KEYS, true) && in_array('lng', OfficeCustomerPanel::COMPANY_BILLING_KEYS, true);
});

check('il repeater dei referenti tiene le colonne di lavoro, anche senza «Brand»', function () use ($forza) {
    $forza(['private_customers' => true, 'companies' => true]);

    try {
        $colonne = [];

        foreach (CompanyResource::contactRelationFields() as $campo) {
            foreach ((array) ($campo->get('context')['columns'] ?? []) as $colonna) {
                $colonne[$colonna->name] = (int) ($colonna->columnSpan['default'] ?? 0);
            }
        }
    } finally {
        $forza(null);
    }

    // Persona, mansione, aree (12); email, prefisso, telefono di lavoro (12); i tre flag (12).
    return ($colonne['email'] ?? 0) === 6 && ($colonne['phone_prefix'] ?? 0) === 2 && ($colonne['phone'] ?? 0) === 4
        && !isset($colonne['brand_ids'])
        && ($colonne['person_id'] ?? 0) + ($colonne['job_title'] ?? 0) + ($colonne['departments'] ?? 0) === 12;
});

summary();
