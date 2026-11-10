<?php
/** php tests/ContactVisibilityTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';
require __DIR__ . '/supporto/SenzaDns.php';

use Wonder\App\Visibility\RowVisibility;
use Wonder\Plugin\CustomerAccount\Models\CompanyBrand;
use Wonder\Plugin\CustomerAccount\Models\ContactRelation;
use Wonder\Plugin\CustomerAccount\Support\ContactRelations;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Contacts\Company;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Resources\Contacts\CompanyBrandResource;
use Wonder\Plugin\Gestionale\Resources\Contacts\CompanyResource;
use Wonder\Plugin\Gestionale\Resources\Contacts\ContactAddressResource;
use Wonder\Plugin\Gestionale\Resources\Contacts\CustomerResource;
use Wonder\Plugin\Gestionale\Support\Contacts\ContactVisibility;
use Wonder\Plugin\Gestionale\Support\Contacts\CustomerMode;
use Wonder\Plugin\Gestionale\Support\Contacts\QuickContacts;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Mcp\ContactTools;
use Wonder\Plugin\Gestionale\Support\SalesAgents\SalesAgents;

/*
 * Chi ha una visibilità per riga (il commerciale) vede, sceglie e lega solo
 * le sue schede e i loro brand, e non alza gli accessi all'area clienti.
 * Utenti e visibilità finti: le righe "viste" le dice un lettore finto.
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

$forza = static function (array $stato): void {
    (new ReflectionProperty(Gestionale::class, 'features'))->setValue(null, $stato + ['sales_agents' => true, 'private_customers' => true, 'companies' => true, 'brands' => true]);
};
$forza([]);
(new ReflectionProperty(SalesAgents::class, 'ids'))->setValue(null, [10 => 5]);

RowVisibility::reset();
RowVisibility::useResources([]);
SalesAgents::register();
ContactVisibility::register();

$commerciale = (object) ['id' => 10, 'authority' => json_encode(['sales_agent'])];
$ufficio = (object) ['id' => 11, 'authority' => json_encode(['sales'])];

// Il commerciale vede l'azienda 7, la persona 3 e il brand 4.
$letture = 0;
$visti = [Company::$table => [7], Contact::$table => [3], CompanyBrand::$table => [4]];
ContactVisibility::useReader(static function (string $table, string $sql) use (&$letture, $visti): array {
    $letture++;

    return $visti[$table] ?? [];
});

check('i brand: il commerciale vede quelli legati (legame aperto) a un\'azienda sua, l\'ufficio tutti', function () use ($commerciale, $ufficio) {
    $regola = (string) RowVisibility::conditionFor(CompanyBrand::$table, $commerciale);

    return str_contains($regola, 'company_brand_links') && str_contains($regola, '`ended_at` IS NULL')
        && str_contains($regola, '`agent_id` = 5')
        && RowVisibility::conditionFor(CompanyBrand::$table, $ufficio) === null;
});

check('una regola di visibilità non può nominare `deleted`: il legame cancellato non si filtra lì', function () use ($commerciale) {
    $regola = (string) SalesAgents::contactsRule($commerciale);
    RowVisibility::rule('tabella_di_prova', static fn (): string => "`deleted` = 'false'");

    try {
        RowVisibility::conditionFor('tabella_di_prova', $commerciale);
        $vietata = false;
    } catch (LogicException) {
        $vietata = true;
    }

    return $vietata && str_contains($regola, ContactRelation::$table) && !str_contains($regola, 'deleted');
});

check('gli id visibili: per l\'ufficio tutti e senza query, per il commerciale una query per chiamata', function () use ($commerciale, $ufficio, &$letture) {
    $letture = 0;
    $tutti = RowVisibility::as($ufficio, static fn (): array => ContactVisibility::visibleIds(Company::$table, [7, 8, 9]));
    $senza = $letture;
    $suoi = RowVisibility::as($commerciale, static fn (): array => ContactVisibility::visibleIds(Company::$table, [9, 7, 8, '7', 'x', [1]]));

    return $tutti === [7, 8, 9] && $senza === 0 && $suoi === [7] && $letture === 1;
});

check('i legami: il commerciale lega solo persone e aziende che vede', function () use ($commerciale, $ufficio) {
    $persone = RowVisibility::as($commerciale, static fn (): array => ContactRelations::allowedCounterparts(ContactRelations::COMPANY, [3, 8]));
    $aziende = RowVisibility::as($commerciale, static fn (): array => ContactRelations::allowedCounterparts(ContactRelations::PERSON, [7, 9]));
    $ufficioVede = RowVisibility::as($ufficio, static fn (): array => ContactRelations::allowedCounterparts(ContactRelations::PERSON, [7, 9]));

    return $persone === [3] && $aziende === [7] && $ufficioVede === [7, 9];
});

check('l\'accesso all\'area clienti lo alza solo chi vede tutte le aziende e può modificarle', function () use ($commerciale, $ufficio) {
    $riga = [[ContactRelations::COMPANY => 7, 'access' => ContactRelation::ACCESS_ADMIN]];
    $suo = RowVisibility::as($commerciale, static fn (): array => ContactRelations::normalize($riga, [], ContactRelations::PERSON, 3, [7]));
    $ufficioScrive = RowVisibility::as($ufficio, static fn (): array => ContactRelations::normalize($riga, [], ContactRelations::PERSON, 3, [7]));

    return ($suo[0]['access'] ?? '') === ContactRelation::ACCESS_NONE
        && ($ufficioScrive[0]['access'] ?? '') === ContactRelation::ACCESS_ADMIN
        && ContactVisibility::canGrantAccess($commerciale) === false
        && ContactVisibility::canGrantAccess($ufficio) === true
        && ContactVisibility::canGrantAccess((object) ['id' => 12, 'authority' => json_encode(['warehouse'])]) === false;
});

check('le scelte dei select: il commerciale vede solo le sue (più quelle già scelte)', function () use ($commerciale, $ufficio) {
    $scelte = ['' => '—', 4 => 'Rossi', 6 => 'Altrui', 8 => 'Già scelto'];
    $suo = RowVisibility::as($commerciale, static fn (): array => ContactVisibility::options(CompanyBrand::$table, $scelte, [8]));
    $tutte = RowVisibility::as($ufficio, static fn (): array => ContactVisibility::options(CompanyBrand::$table, $scelte));

    return array_keys($suo) === ['', 4, 8] && $tutte === $scelte;
});

check('un brand che non vede non si lega alla sua azienda, nemmeno postandone l\'id', function () use ($commerciale, $ufficio) {
    $metodo = new ReflectionMethod(CompanyResource::class, 'withLinkableBrands');
    $post = ['company_brands_form' => '1', 'brands' => [['brand_id' => '4', 'role' => 'owner'], ['brand_id' => '6'], ['brand_id' => ['x']], '6']];
    $suo = RowVisibility::as($commerciale, static fn (): array => $metodo->invoke(null, $post));
    $ufficioPosta = RowVisibility::as($ufficio, static fn (): array => $metodo->invoke(null, $post));

    return $suo['brands'] === [['brand_id' => '4', 'role' => 'owner']] && $ufficioPosta === $post;
});

check('il brand principale: il commerciale non ne sceglie uno che non vede', function () use ($commerciale) {
    try {
        RowVisibility::as($commerciale, static fn (): array => CompanyBrandResource::mutateRequestValues(['name' => 'Linea', 'parent_id' => '9'], 'update', 'backend', ['id' => 4, 'parent_id' => 0]));
    } catch (InvalidArgumentException $errore) {
        return str_contains($errore->getMessage(), 'non esiste');
    }

    return false;
});

check('pagine del brand: senza aziende o senza «Brand» nessuna, il commerciale crea solo dalla «+»', function () use ($commerciale, $ufficio, $forza) {
    $pagine = static fn (): array => (array) CompanyBrandResource::pageSchema()->get('pages');
    $suo = RowVisibility::as($commerciale, $pagine);
    $ufficioPagine = RowVisibility::as($ufficio, $pagine);
    $forza(['companies' => false]);
    $soloPersone = $pagine();
    $forza(['brands' => false]);
    $senzaBrand = $pagine();
    $forza([]);

    return ($suo['create'] ?? true) === false && ($suo['store'] ?? true) === false && ($suo['edit'] ?? false) === true
        && ($suo['list'] ?? false) === true && ($ufficioPagine['create'] ?? false) === true
        && array_filter($soloPersone) === [] && array_filter($senzaBrand) === [];
});

check('voce «Brand» in Anagrafiche con i permessi dei contatti, solo con «Brand»', function () use ($forza) {
    $voce = CompanyBrandResource::navigationSchema();
    $forza(['brands' => false]);
    $spenta = CompanyBrandResource::navigationSchema();
    $forza([]);

    return $voce->get('enabled') === true && $voce->get('section_key') === 'anagrafiche' && $voce->get('title') === 'Brand'
        && in_array('sales_agent', (array) $voce->get('authority'), true) && in_array('sales', (array) $voce->get('authority'), true)
        && $spenta->get('enabled') === false;
});

check('senza «Brand» la «+» del brand rifiuta, prima di toccare il database', function () use ($forza) {
    $forza(['brands' => false]);
    $esito = CompanyBrandResource::quickCreateStore(['name' => 'Rossi', 'link_company_id' => '7'], 'name');
    $forza([]);

    return isset($esito['error']) && !isset($esito['id']);
});

check('senza «Brand» i brand postati di un\'azienda e i brand seguiti dei referenti non si scrivono', function () use ($forza) {
    $colonne = static function (): array {
        $nomi = [];

        foreach (CompanyResource::contactRelationFields() as $campo) {
            foreach ((array) ($campo->get('context')['columns'] ?? []) as $colonna) {
                $nomi[] = $colonna->name;
            }
        }

        return $nomi;
    };
    $togli = new ReflectionMethod(CompanyResource::class, 'withoutHiddenBrands');
    $post = ['company_brands_form' => '1', 'brands' => [['brand_id' => '4']], 'contact_people' => [['person_id' => '3', 'brand_ids' => ['4'], 'job_title' => 'Acquisti']]];
    $forza(['brands' => false]);
    $senza = [$colonne(), $togli->invoke(null, $post)];
    $forza([]);
    $con = [$colonne(), $togli->invoke(null, $post)];
    $persona = (new ReflectionMethod(CustomerResource::class, 'withoutHiddenBrands'))->invoke(null, ['contact_companies' => [['company_id' => '7', 'job_title' => 'x']]]);

    return !in_array('brand_ids', $senza[0], true) && in_array('person_id', $senza[0], true) && in_array('brand_ids', $con[0], true)
        && $senza[1] === ['contact_people' => [['person_id' => '3', 'job_title' => 'Acquisti']]]
        && $con[1] === $post && $persona === ['contact_companies' => [['company_id' => '7', 'job_title' => 'x']]];
});

check('senza aziende le sedi d\'azienda non si toccano', function () use ($forza) {
    $forza(['companies' => false]);
    $azienda = ContactAddressResource::run('save', 7, 0, ['street' => 'Via Roma'], ContactAddressResource::OWNER_COMPANY);
    $forza([]);

    return $azienda === ['ok' => false, 'message' => 'Scheda non trovata.'];
});

check('MCP: le aziende solo dove ci sono, le persone clienti solo dove si vende ai privati', function () use ($forza) {
    $forza(['companies' => false]);
    $soloPersone = [ContactTools::kindShown('company'), ContactTools::kindShown('person')];
    $forza(['private_customers' => false]);
    $soloAziende = [ContactTools::kindShown('company'), ContactTools::kindShown('person')];
    $forza([]);

    return $soloPersone === [false, true] && $soloAziende === [true, false];
});

check('solo aziende: una persona postata come cliente non diventa cliente privato', function () use ($forza) {
    $forza(['private_customers' => false]);
    $post = $_POST;
    $_POST = [CustomerResource::RELATION_MARKER => '1', CustomerResource::RELATION_COMPANIES => []];

    try {
        CustomerResource::mutateRequestValues(['name' => 'Anna', 'is_customer' => 'true', 'roles' => 'customer'], 'store');
        $chiave = '';
    } catch (UserError $errore) {
        $chiave = $errore->key();
    } finally {
        $_POST = $post;
        $forza([]);
    }

    return $chiave === 'contact.person_no_role';
});

check('creazione rapida: valori non scalari valgono vuoto', function () {
    $persona = QuickContacts::person(['name' => ['x'], 'surname' => ['y'], 'role' => ['customer']]);
    $azienda = QuickContacts::company(['business_name' => ['Rossi']]);

    return !$persona['ok'] && str_contains($persona['message'], 'nome')
        && !$azienda['ok'] && str_contains($azienda['message'], 'ragione sociale');
});

check('una scheda che non si riesce a leggere non si vede', function () use ($commerciale) {
    $visibile = new ReflectionMethod(QuickContacts::class, 'visible');

    // Senza database la lettura della visibilità fallisce: non vale «visibile».
    return RowVisibility::as($commerciale, static fn (): bool => $visibile->invoke(null, Contact::class, 3)) === false;
});

check('il doppione che non si vede non si nomina; il referente nuovo si lega con attach()', function () {
    $codice = (string) file_get_contents(__DIR__.'/../src/Support/Contacts/QuickContacts.php');
    $scheda = (string) file_get_contents(__DIR__.'/../src/Resources/Contacts/CustomerResource.php');

    return str_contains($codice, 'Non posso creare questa scheda: chiedi a chi gestisce le anagrafiche.')
        && !str_contains($codice, 'C\'è già una persona con questa email')
        && str_contains($codice, 'ContactRelations::attach($companyId, $personId)')
        && !str_contains($codice, 'allRowsOf')
        && str_contains($scheda, "UserError::make('contact.duplicate_hidden')")
        && !str_contains(UserError::make('contact.duplicate_hidden')->getMessage(), '{{');
});

/** Le persone con un doppione: `anna@rossi.it` è già della scheda 8. */
class PersoneConDoppione extends \Wonder\Plugin\CustomerAccount\Models\Contact
{
    public static function find(string|array|null $condition = null, string|int|null $limit = null, ?string $order = null, ?string $orderDirection = null, string|array $columns = '*'): mixed
    {
        return is_array($condition) && ($condition['email'] ?? '') === 'anna@rossi.it' ? [['id' => 8, 'name' => 'Anna', 'surname' => 'Rossi']] : [];
    }
}

final class PersoneDiProva extends CustomerResource
{
    public static string $model = PersoneConDoppione::class;
}

check('il doppione dell\'email principale vale anche dal riquadro: nominato a chi lo vede, generico per gli altri', function () use ($commerciale, $ufficio) {
    $valori = ['name' => 'Anna', 'contact_box_form' => '1', 'contact_points' => [['kind' => 'email', 'email' => 'Anna@Rossi.it', 'is_primary' => 'true']]];
    $chiave = static function (object $utente) use ($valori): string {
        try {
            RowVisibility::as($utente, static fn (): array => PersoneDiProva::mutateRequestValues($valori, 'store'));
        } catch (UserError $errore) {
            return $errore->key();
        }

        return '';
    };

    return $chiave($ufficio) === 'contact.email_taken' && $chiave($commerciale) === 'contact.duplicate_hidden';
});

check('i ganci si registrano al boot accanto ai commerciali', function () {
    $boot = (string) file_get_contents(__DIR__.'/../config/boot.php');

    return strpos($boot, 'SalesAgents::register()') < strpos($boot, 'ContactVisibility::register()');
});

ContactVisibility::useReader(null);
ContactRelations::useCounterpartFilter(null);
ContactRelations::useAccessGuard(null);
RowVisibility::reset();

summary();
