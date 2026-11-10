<?php

namespace Wonder\Plugin\Gestionale\Resources\Contacts;

use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\TableLayoutSchema;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Plugin\CustomerAccount\Resources\Concerns\HasCompanyBrands;
use Wonder\App\Support\Repeater;
use Wonder\Plugin\CustomerAccount\Models\CompanyAddress;
use Wonder\Plugin\CustomerAccount\Support\CompanyAddresses;
use Wonder\Plugin\CustomerAccount\Support\ContactRelations;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Contacts\Company;
use Wonder\Plugin\Gestionale\Support\Contacts\CustomerMode;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Purchasing\ProductSuppliers;

/**
 * "Aziende": clienti e fornitori con partita IVA, e i loro referenti.
 *
 * Stessa scheda delle persone, su `companies`: i documenti la legano con
 * `company_id`, non ha account, i suoi indirizzi stanno in
 * `company_addresses`. Con gli acquisti accesi sceglie il ruolo.
 * `SupplierResource` la estende per l'elenco dei fornitori. La prima cosa
 * che chiede è il brand: il nome commerciale, condiviso tra ragioni sociali.
 *
 * Con il gestionale «solo persone» (`CustomerMode`) l'elenco e le sue pagine
 * spariscono; i fornitori restano, senza brand, capogruppo e referenti.
 */
class CompanyResource extends CustomerResource
{
    use HasCompanyBrands {
        syncRepeaterRelations as brandsSyncRepeaterRelations;
    }

    public static string $model = Company::class;

    public static function relationSide(): string
    {
        return ContactRelations::COMPANY;
    }

    public static function documentColumn(): string
    {
        return 'company_id';
    }

    public static function hasAccount(): bool
    {
        return false;
    }

    public static function addressModel(): string
    {
        return CompanyAddress::class;
    }

    public static function addressColumn(): string
    {
        return 'company_id';
    }

    /** Il ruolo «Cliente e fornitore» c'è solo se le aziende sono anche clienti. */
    public static function rolesShown(): bool
    {
        return Gestionale::feature('purchasing') && CustomerMode::companies();
    }

    public static function shownInMode(): bool
    {
        return CustomerMode::companies();
    }

    protected static function uniqueColumns(): array
    {
        return ['pi' => 'contact.vat_taken', 'cf' => 'contact.tax_code_taken', 'email' => 'contact.email_taken'];
    }

    public static function path(): string
    {
        return 'app/gestionale/aziende';
    }

    public static function icon(): string
    {
        return 'bi-building';
    }

    public static function titleLabel(): string
    {
        return 'Aziende';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'azienda',
            'plural_label' => 'aziende',
            'last' => 'ultime',
            'all' => 'tutte',
            'article' => 'le',
            'this' => 'questa',
        ];
    }

    public static function brandResource(): string
    {
        return CompanyBrandResource::class;
    }

    /** Brand e capogruppo sono delle aziende clienti: con le sole persone non si chiedono. */
    public static function formSchema(): array
    {
        $extras = CustomerMode::companies() ? [...static::brandFields(), ...static::groupFields()] : [];

        return [...$extras, ...parent::formSchema()];
    }

    public static function mutateFormValues(array $values, string $mode, string $context = 'backend'): array
    {
        return static::withBrandValues(parent::mutateFormValues($values, $mode, $context));
    }

    /** Gli indirizzi di consegna del form con i brand per cui valgono (`brand_ids`, vuoto = tutti). */
    public static function hydrateRepeaterFormValues(
        array $values,
        int|string|null $parentId = null,
        ?array $post = null,
        ?array $files = null
    ): array {
        $values = parent::hydrateRepeaterFormValues($values, $parentId, $post, $files);

        if (static::addressBrandsShown() && is_array($values['addresses'] ?? null)) {
            $values['addresses'] = CompanyAddresses::withBrandIds(array_values($values['addresses']));
        }

        return $values;
    }

    /**
     * Prima i legami (indirizzi, referenti, contatti, poi i brand
     * dell'azienda), poi i brand degli indirizzi: `setBrands()` tiene solo i
     * brand legati all'azienda, e su una scheda nuova lo sono appena dopo.
     */
    public static function syncRepeaterRelations(
        int|string $parentId,
        array $post,
        array $files = [],
        string $action = 'store',
        string $context = 'backend'
    ): array {
        $summary = static::brandsSyncRepeaterRelations($parentId, $post, $files, $action, $context);

        if (static::addressBrandsShown() && isset($post[self::ADDRESS_BRANDS_MARKER])) {
            $summary['address_brands'] = static::syncAddressBrands((int) $parentId, $post, $files);
        }

        return $summary;
    }

    /**
     * Scrive i brand di ogni indirizzo postato. Una riga con l'id è
     * quell'indirizzo, se è dell'azienda; una riga nuova è l'indirizzo
     * che il sync del core ha messo nella stessa posizione (le righe le
     * scrive in ordine, con `position` da zero).
     *
     * @return array<int, list<int>> indirizzo => brand rimasti
     */
    protected static function syncAddressBrands(int $companyId, array $post, array $files = []): array
    {
        $field = static::repeaterRelations()['addresses']['field'] ?? null;
        $rows = Repeater::rowsFromRequest('addresses', $post, $files, $field !== null ? Repeater::columnDefaults($field) : []);
        $live = [];

        foreach (static::rowsOf(CompanyAddress::class, ['company_id' => $companyId, 'deleted' => 'false'], 'position') as $address) {
            $live[(int) $address['id']] = (int) ($address['position'] ?? 0);
        }

        $byPosition = array_flip($live);
        $out = [];

        foreach (array_values(array_filter($rows, 'is_array')) as $position => $row) {
            if (!array_key_exists('brand_ids', $row)) {
                continue;
            }

            $id = (int) ($row['id'] ?? 0);
            $id = $id > 0 ? (isset($live[$id]) ? $id : 0) : (int) ($byPosition[$position] ?? 0);

            if ($id > 0) {
                $brandIds = is_array($row['brand_ids']) ? $row['brand_ids'] : [$row['brand_ids']];
                $out[$id] = CompanyAddresses::setBrands($id, $brandIds);
            }
        }

        return $out;
    }

    protected static function brandCards(): array
    {
        if (!CustomerMode::companies()) {
            return [];
        }

        return [
            (new Card)->components([
                SectionTitle::make(__t('gestionale.contacts.brands.title'))
                    ->tooltip(__t('gestionale.contacts.brands.tooltip'))
                    ->columnSpan(12),
                static::getInput(static::$brandsInput)->columnSpan(12),
                static::getInput(static::$brandsMarker)->columnSpan(12),
                // La capogruppo: un livello solo, la valida `HasCompanyGroup`.
                static::getInput(static::$groupInput)->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ];
    }

    protected static function sheetView(): string
    {
        return 'pages/company-show.php';
    }

    public static function navigationSchema(): NavigationSchema
    {
        return parent::navigationSchema()->order(15);
    }

    /** Tutte le aziende: il ruolo si sceglie col filtro. */
    protected static function listCondition(): string
    {
        return '1 = 1';
    }

    public static function tableLayoutSchema(): TableLayoutSchema
    {
        $layout = parent::tableLayoutSchema();

        if (!static::rolesShown() || static::listCondition() !== '1 = 1') {
            return $layout;
        }

        return $layout->filterQuery(
            'Ruolo',
            'role',
            ['customer' => 'Clienti', 'supplier' => 'Fornitori'],
            static fn (array $values): string => match ($values[0] ?? '') {
                'customer' => "is_customer = 'true'",
                'supplier' => "is_supplier = 'true'",
                default => '1 = 1',
            }
        );
    }

    /**
     * Un'azienda è cliente, fornitore o tutti e due. Un fornitore che smette di
     * esserlo sparirebbe dalle tendine degli articoli che comprano da lui.
     */
    protected static function checkRoles(array $values, int $id): array
    {
        $customer = ($values['is_customer'] ?? static::storedRole($id, 'is_customer')) === 'true';
        $supplier = ($values['is_supplier'] ?? static::storedRole($id, 'is_supplier')) === 'true';

        if ($id <= 0 && !static::rolesShown() && !isset($values['is_customer']) && static::roleColumn() === 'is_customer') {
            // Senza acquisti ogni azienda nuova è un cliente; un fornitore
            // nuovo senza il campo Ruolo (solo persone) resta fornitore.
            $values['is_customer'] = 'true';
            $customer = true;
        }

        if (!$customer && !$supplier) {
            throw UserError::make('contact.no_role');
        }

        if ($id > 0 && !$supplier && static::storedRole($id, 'is_supplier') === 'true') {
            $links = ProductSuppliers::countForSupplier($id);

            if ($links > 0) {
                throw UserError::make('contact.supplier_role_in_use', ['count' => $links]);
            }
        }

        return $values;
    }

    public static function assertDeletable(int|string $id): void
    {
        parent::assertDeletable($id);

        $links = ProductSuppliers::countForSupplier((int) $id);

        if ($links > 0) {
            throw UserError::refusal('contact.supplier_in_use', ['count' => $links]);
        }
    }

    public static function deleteRecord(int|string $id): object
    {
        static::assertDeletable($id);
        ProductSuppliers::dropForRemovedProducts((int) $id);

        return parent::deleteRecord($id);
    }
}
