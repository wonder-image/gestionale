<?php
/** php tests/ContactsDataTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

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

check('il repeater degli indirizzi di un\'azienda ha i brand (vuoto = tutti), solo dove ci sono le aziende', function () use ($forza, $colonneIndirizzi, $chiavi) {
    $forza(['private_customers' => true, 'companies' => true, 'purchasing' => true]);
    $azienda = $colonneIndirizzi(CompanyResource::class);
    $fornitore = $colonneIndirizzi(SupplierResource::class);
    $persona = $colonneIndirizzi(CustomerResource::class);
    $marcatore = in_array(CustomerResource::ADDRESS_BRANDS_MARKER, $chiavi(CompanyResource::class), true);
    $forza(['private_customers' => true, 'companies' => false, 'purchasing' => true]);
    $soloPersone = $colonneIndirizzi(SupplierResource::class);
    $forza(null);

    return in_array('brand_ids', $azienda, true) && in_array('brand_ids', $fornitore, true) && $marcatore
        && !in_array('brand_ids', $persona, true) && !in_array('brand_ids', $soloPersone, true);
});

check('i brand non vanno al salvataggio della riga dell\'indirizzo', function () {
    $riga = CompanyResource::prepareRepeaterRelationRow('addresses', ['street' => 'Via Roma', 'brand_ids' => [5]], []);
    $altro = CompanyResource::prepareRepeaterRelationRow('altro', ['brand_ids' => [5]], []);

    return !array_key_exists('brand_ids', $riga) && ($riga['street'] ?? '') === 'Via Roma' && isset($altro['brand_ids']);
});

// --- Altri contatti ---

check('persone e aziende hanno «Altri contatti» nella card dei contatti', function () use ($chiavi) {
    foreach ([CustomerResource::class, CompanyResource::class] as $resource) {
        if (!in_array('contact_points', $chiavi($resource), true) || !in_array('contact_points_form', $chiavi($resource), true)) {
            return false;
        }
    }

    return CustomerResource::contactPointOwner() === 'contact' && CompanyResource::contactPointOwner() === 'company';
});

check('gli altri contatti non arrivano al salvataggio della scheda; uno sbagliato lo ferma', function () {
    $valori = CustomerResource::mutateRequestValues(['name' => 'Mario', 'contact_points' => [], 'contact_points_form' => '1'], 'store');

    try {
        CustomerResource::mutateRequestValues([
            'name' => 'Mario', 'email' => 'm@example.com', 'contact_points_form' => '1',
            'contact_points' => [['kind' => 'email', 'value' => 'non-email', 'label' => '', 'phone_prefix' => '']],
        ], 'store');
        $fermato = false;
    } catch (InvalidArgumentException) {
        $fermato = true;
    }

    return !array_key_exists('contact_points', $valori) && !array_key_exists('contact_points_form', $valori) && $fermato;
});

check('nella scheda in lettura gli altri contatti, escapati, con l\'etichetta; nessuno = nessuna voce', function () {
    $voci = CustomerSheet::points([
        ['kind' => 'email', 'value' => 'a<b>@example.com', 'label' => 'work', 'phone_prefix' => ''],
        ['kind' => 'phone', 'value' => '3331234567', 'label' => '', 'phone_prefix' => '+39'],
    ], ['work' => 'Lavoro']);
    $html = $voci === [] ? '' : $voci[0]->render('bootstrap');

    return count($voci) === 1
        && str_contains($html, 'a&lt;b&gt;@example.com') && !str_contains($html, '<b>')
        && str_contains($html, 'Lavoro') && str_contains($html, '+39 3331234567')
        && CustomerSheet::points([]) === []
        && CustomerResource::pointItems(0) === [];
});

check('la scheda del referente legge gli altri contatti come quella del cliente', function () {
    $codice = (string) file_get_contents(__DIR__.'/../src/Resources/Contacts/CustomerResource.php');
    $referente = substr($codice, (int) strpos($codice, 'function referentLayoutSchema'), 1500);

    return str_contains($referente, 'static::pointItems($id)')
        && substr_count($codice, 'static::pointItems($id)') === 2;
});

// --- Dati del brand ---

check('il brand del gestionale eredita logo, sito, dominio e descrizione', function () use ($chiavi) {
    $campi = $chiavi(CompanyBrandResource::class);

    return array_intersect(['name', 'logo', 'website', 'domain', 'description'], $campi) === ['name', 'logo', 'website', 'domain', 'description'];
});

check('«Brand kit» nel pannello: Anagrafiche, spenta di serie, richiede le aziende; accesa mostra il kit', function () use ($forza, $chiavi) {
    $voce = FeatureCatalog::all()['brand_kit'] ?? [];
    $forza(['companies' => true, 'brand_kit' => true]);
    $conKit = $chiavi(CompanyBrandResource::class);
    $forza(null);

    return ($voce['area'] ?? '') === 'Anagrafiche' && ($voce['default'] ?? true) === false
        && ($voce['requires'] ?? []) === ['companies']
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

check('customer_get espone aree, altri contatti, brand e sedi per brand, con lo stesso permesso', function () {
    $tool = ContactTools::all()['customer_get'];
    $schema = json_encode($tool['schema']);

    return str_contains($schema, 'brand_id')
        && str_contains($tool['description'], 'departments') && str_contains($tool['description'], 'contact_points')
        && str_contains($tool['description'], 'brands') && str_contains($tool['description'], 'non ricevono documenti')
        && $tool['permission'] === [CustomerResource::class, 'view'] && $tool['write'] === false;
});

summary();
