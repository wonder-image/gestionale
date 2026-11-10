<?php
/** php tests/BrandsFeatureTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\App\LegacyGlobals;
use Wonder\App\ResourceSchema\Input;
use Wonder\App\Theme;
use Wonder\Backend\Support\ResourceFormLayoutRenderer;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Resources\Contacts\CompanyResource;
use Wonder\Plugin\Gestionale\Resources\Contacts\CustomerResource;
use Wonder\Plugin\Gestionale\Support\Contacts\Brands;
use Wonder\Plugin\Gestionale\Support\Features\FeatureCatalog;
use Wonder\Plugin\Gestionale\Support\Features\FeatureRules;
use Wonder\Plugin\Gestionale\Support\Features\FeatureState;

/*
 * «Brand» e «Gruppi di aziende»: due funzionalità dell'area Anagrafiche che
 * nascondono, senza cancellare, i brand e la capogruppo delle aziende. «Brand»
 * nasce accesa dove i brand ci sono già. Il blocco «Pagamento» della scheda
 * sta su una riga.
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

Theme::set('bootstrap');

$forza = static function (?array $stato): void {
    (new ReflectionProperty(Gestionale::class, 'features'))->setValue(null, $stato === null ? null : $stato + ['private_customers' => true, 'companies' => true]);
};

/** Le chiavi del form delle aziende. @return list<string> */
$chiavi = static fn (): array => array_map(static fn ($campo): string => (string) $campo->name, CompanyResource::formSchema());

/** Le card del form per titolo. @return array<string, Card> */
$card = static function (string $resource): array {
    $trovate = [];
    $scendi = function (array $componenti) use (&$scendi, &$trovate): void {
        foreach ($componenti as $c) {
            if ($c instanceof Container) {
                $scendi($c->components);
            } elseif ($c instanceof Card && ($c->components[0] ?? null) instanceof SectionTitle) {
                $trovate[(string) (new ReflectionProperty($c->components[0], 'text'))->getValue($c->components[0])] = $c;
            }
        }
    };
    $scendi($resource::formLayoutSchema()->components);

    return $trovate;
};

/** I nomi dei campi dentro una card, a ogni livello. @return list<string> */
$campi = static function (Card $card): array {
    $nomi = [];
    $scendi = function (array $componenti) use (&$scendi, &$nomi): void {
        foreach ($componenti as $c) {
            if (isset($c->components) && is_array($c->components)) {
                $scendi($c->components);
            } elseif ($c instanceof Input) {
                $nomi[] = $c->name;
            }
        }
    };
    $scendi($card->components);

    return $nomi;
};

// --- Catalogo ---

check('nel pannello: «Brand» e «Gruppi di aziende» in Anagrafiche, spente di serie, richiedono le aziende; il kit richiede «Brand»', function () {
    $catalogo = FeatureCatalog::all();

    return ($catalogo['brands']['area'] ?? '') === 'Anagrafiche' && ($catalogo['brands']['default'] ?? true) === false
        && ($catalogo['brands']['requires'] ?? []) === ['companies'] && is_callable($catalogo['brands']['default_if'] ?? null)
        && ($catalogo['company_groups']['area'] ?? '') === 'Anagrafiche' && ($catalogo['company_groups']['default'] ?? true) === false
        && ($catalogo['company_groups']['requires'] ?? []) === ['companies'] && $catalogo['company_groups']['default_if'] === null
        && ($catalogo['brand_kit']['requires'] ?? []) === ['brands'];
});

check('spegnere «Aziende» spegne brand, kit e gruppi; accendere il kit accende «Brand» e le aziende', function () {
    $catalogo = FeatureCatalog::all();
    $tutte = ['private_customers' => true, 'companies' => true, 'brands' => true, 'brand_kit' => true, 'company_groups' => true];
    $spente = FeatureRules::apply($catalogo, $tutte, ['companies' => false] + $tutte)['state'];
    $kit = FeatureRules::apply($catalogo, ['private_customers' => true], ['brand_kit' => true])['state'];

    return !$spente['brands'] && !$spente['brand_kit'] && !$spente['company_groups']
        && $kit['brand_kit'] && $kit['brands'] && $kit['companies'];
});

// --- Accensione dove i brand ci sono già ---

check('`default_if`: senza riga accende se risponde sì; con la riga vale la riga e non si chiede', function () {
    $chiamate = 0;
    $catalogo = FeatureCatalog::fromArray([
        'companies' => ['name' => 'Aziende', 'default' => true],
        'brands' => ['name' => 'Brand', 'requires' => ['companies'], 'default_if' => static function () use (&$chiamate): bool {
            $chiamate++;

            return true;
        }],
        'senza' => ['name' => 'Senza', 'default_if' => static fn (): bool => false],
        'testo' => ['name' => 'Testo', 'default_if' => 'non_una_funzione_che_esiste'],
    ]);
    $nuovo = FeatureState::defaults($catalogo, []);
    $primaChiamate = $chiamate;
    $spentaDaLui = FeatureState::defaults($catalogo, ['brands' => false]);
    $accesa = FeatureState::defaults($catalogo, ['brands' => true]);

    return ($nuovo['brands'] ?? false) === true && !isset($nuovo['senza']) && !isset($nuovo['testo'])
        && ($nuovo['companies'] ?? false) === true
        && $primaChiamate === 1 && $chiamate === 1
        && $spentaDaLui['brands'] === false && $accesa['brands'] === true;
});

check('«Brand» nasce accesa solo se c\'è un brand: il catalogo vero chiede a `Brands::inUse()`, che senza database dice no', function () {
    $catalogo = FeatureCatalog::all();

    return $catalogo['brands']['default_if'] === [Brands::class, 'inUse']
        && Brands::inUse() === false
        && !isset(FeatureState::defaults($catalogo, [])['brands']);
});

check('le righe nuove del pannello e dei valori precaricati nascono come la voce senza riga', function () {
    $pannello = (string) file_get_contents(__DIR__.'/../src/Support/Features/FeaturePanel.php');
    $precaricati = (string) file_get_contents(__DIR__.'/../src/Seeding/Defaults.php');

    return str_contains($pannello, 'FeatureState::defaults(FeatureCatalog::all(), array_fill_keys($present, false))')
        && str_contains($precaricati, 'FeatureState::defaults($catalog, FeatureState::inherit($catalog, $known))')
        && str_contains($precaricati, '$rows->ensure(Feature::class');
});

// --- Un solo punto di lettura ---

check('`Brands::enabled()`: con «Brand» e le aziende; stato non letto spenta', function () use ($forza) {
    $forza(['brands' => true]);
    $accesa = Brands::enabled();
    $forza(['brands' => true, 'companies' => false]);
    $soloPersone = Brands::enabled();
    $forza([]);
    $spenta = Brands::enabled();
    $forza(null);

    return $accesa && !$soloPersone && !$spenta;
});

// --- Scheda dell'azienda ---

check('la scheda dell\'azienda: brand con «Brand», capogruppo con «Gruppi di aziende», ognuno da sé', function () use ($forza, $chiavi, $card, $campi) {
    $esiti = [];

    foreach ([[true, true], [true, false], [false, true], [false, false]] as [$brand, $gruppi]) {
        $forza(['brands' => $brand, 'company_groups' => $gruppi]);
        $chiaviForm = $chiavi();
        $schede = $card(CompanyResource::class);
        $esiti[] = [
            in_array('brands', $chiaviForm, true) && in_array('company_brands_form', $chiaviForm, true),
            in_array('parent_id', $chiaviForm, true),
            isset($schede['Brand']) ? $campi($schede['Brand']) : (isset($schede['Gruppo']) ? ['Gruppo', ...$campi($schede['Gruppo'])] : []),
        ];
    }

    $forza(null);

    return $esiti[0] === [true, true, ['brands', 'company_brands_form', 'parent_id']]
        && $esiti[1] === [true, false, ['brands', 'company_brands_form']]
        && $esiti[2] === [false, true, ['Gruppo', 'parent_id']]
        && $esiti[3] === [false, false, []];
});

check('senza «Gruppi di aziende» la capogruppo postata non arriva al salvataggio; accesa si valida', function () use ($forza) {
    $valori = ['business_name' => 'Rossi Srl', 'parent_id' => 'abc'];
    $forza([]);
    $spenta = CompanyResource::mutateRequestValues($valori, 'store');
    $forza(['company_groups' => true]);

    try {
        CompanyResource::mutateRequestValues($valori, 'store');
        $rifiutata = false;
    } catch (InvalidArgumentException) {
        $rifiutata = true;
    } finally {
        $forza(null);
    }

    return !array_key_exists('parent_id', $spenta) && ($spenta['business_name'] ?? '') === 'Rossi Srl' && $rifiutata;
});

// --- Pagamento su una riga ---

check('«Pagamento»: metodo, esigibilità, listino e commerciale su una riga da `lg`, uno per riga sotto', function () use ($forza, $card, $campi) {
    $utente = LegacyGlobals::get('USER');
    LegacyGlobals::set('USER', (object) ['id' => 1, 'authority' => json_encode(['administrator'])]);
    $forza(['orders' => true, 'office_sales' => true, 'customer_price_lists' => true, 'sales_agents' => true]);

    try {
        $azienda = $card(CompanyResource::class);
        $html = ResourceFormLayoutRenderer::renderLayout((new Form)->columns(12)->components([$azienda['Pagamento']]));
        $forza(['orders' => true]);
        $persona = $card(CustomerResource::class);
    } finally {
        LegacyGlobals::set('USER', $utente);
        $forza(null);
    }

    // Senza più condizioni di pagamento (niente database) la condizione non c'è: quattro colonne.
    $colonne = static fn (Card $c): array => array_map(static fn ($campo): array => [$campo->columnSpan['default'], $campo->columnSpan['lg']], array_slice($c->components, 1));

    return $campi($azienda['Pagamento']) === ['payment_method_id', 'vat_collectability', 'price_list_id', 'agent_id']
        && $colonne($azienda['Pagamento']) === [[12, 3], [12, 3], [12, 3], [12, 3]]
        && substr_count($html, 'class="col-12 col-lg-3"') === 4
        && !isset($azienda['Listino']) && !isset($azienda['Commerciale'])
        && $campi($persona['Pagamento']) === ['vat_collectability'] && $colonne($persona['Pagamento']) === [[12, 12]];
});

summary();
