<?php

namespace Wonder\Plugin\Gestionale\Resources\Contacts;

use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\TableLayoutSchema;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Plugin\CustomerAccount\Resources\Concerns\HasCompanyBrands;
use Wonder\App\Support\Repeater;
use Wonder\Plugin\CustomerAccount\Models\CompanyAddress;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\RepeaterColumn;
use Wonder\Plugin\CustomerAccount\Models\CompanyBrand;
use Wonder\Plugin\CustomerAccount\Models\CompanyBrandLink;
use Wonder\Plugin\CustomerAccount\Support\CompanyAddresses;
use Wonder\Plugin\CustomerAccount\Support\CompanyBrands;
use Wonder\Plugin\CustomerAccount\Support\CompanyGroups;
use Wonder\Plugin\Gestionale\Support\Contacts\ContactVisibility;
use Wonder\Plugin\CustomerAccount\Support\ContactRelations;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Contacts\Company;
use Wonder\Plugin\Gestionale\Support\Contacts\Brands;
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
 * spariscono; i fornitori restano, senza brand, capogruppo e referenti. I
 * brand ci sono con «Brand» (`Brands::enabled()`), la capogruppo con «Gruppi
 * di aziende» (`groupsShown()`): spente, un POST che li porta non li tocca.
 */
class CompanyResource extends CustomerResource
{
    use HasCompanyBrands {
        syncRepeaterRelations as brandsSyncRepeaterRelations;
        mutateRequestValues as groupMutateRequestValues;
        brandFields as allBrandFields;
        groupFields as allGroupFields;
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

    /** La capogruppo, con «Gruppi di aziende» e dove ci sono le aziende. */
    public static function groupsShown(): bool
    {
        return CustomerMode::companies() && Gestionale::feature('company_groups');
    }

    /** Brand e capogruppo, ognuno con la sua funzionalità. */
    public static function formSchema(): array
    {
        return [
            ...(Brands::enabled() ? static::brandFields() : []),
            ...(static::groupsShown() ? static::groupFields() : []),
            ...parent::formSchema(),
        ];
    }

    public static function mutateFormValues(array $values, string $mode, string $context = 'backend'): array
    {
        $values = parent::mutateFormValues($values, $mode, $context);

        return Brands::enabled() ? static::withBrandValues($values) : $values;
    }

    /**
     * Senza «Gruppi di aziende» la capogruppo postata non arriva al
     * salvataggio: resta quella salvata. Accesa la valida `HasCompanyGroup`.
     */
    public static function mutateRequestValues(array $values, string $action, string $context = 'backend', ?array $oldValues = null): array
    {
        if (!static::groupsShown()) {
            unset($values[static::$groupInput]);
        }

        return static::groupMutateRequestValues($values, $action, $context, $oldValues);
    }

    /**
     * I brand della scheda. Chi vede solo alcuni brand (il commerciale) sceglie
     * fra quelli che vede: lo stesso campo di customer-account, con le scelte
     * ristrette. Gli altri hanno il campo com'è.
     *
     * @return list<\Wonder\App\ResourceSchema\Input>
     */
    public static function brandFields(): array
    {
        if (!ContactVisibility::restricted(CompanyBrand::$table)) {
            return static::allBrandFields();
        }

        // Come il campo di customer-account: il predefinito non si sceglie nel form.
        return [
            FormField::key(static::$brandsInput)
                ->repeater([
                    RepeaterColumn::key('brand_id')
                        ->selectSearch(['' => (string) __t('contacts.option_none')] + ContactVisibility::options(CompanyBrand::$table, CompanyBrands::options(), ContactVisibility::rememberedBrands()))
                        ->quickCreate(static::brandResource(), ['name'], null, 'name', (string) __t('contacts.brand_add'))
                        ->attribute('data-ca-company-brand')
                        ->label((string) __t('contacts.brand'))
                        ->columnSpan(8),
                    RepeaterColumn::key('role')
                        ->select(CompanyBrands::roles())
                        ->value(CompanyBrandLink::ROLE_OWNER)
                        ->label((string) __t('contacts.brand_role'))
                        ->columnSpan(4),
                ])
                ->nested()
                ->repeaterSortable()
                ->repeaterAddLabel((string) __t('contacts.brand_add_row'))
                ->label((string) __t('contacts.brands_label')),
            FormField::key(static::$brandsMarker)->hidden()->value('1'),
        ];
    }

    /** La capogruppo fra le aziende che chi scrive vede (più quella già scelta: la valida `mutateRequestValues()`). */
    public static function groupFields(): array
    {
        if (!ContactVisibility::restricted(Company::$table)) {
            return static::allGroupFields();
        }

        return [
            FormField::key(static::$groupInput)
                ->selectSearch(['' => 'Nessuno'] + ContactVisibility::options(Company::$table, CompanyGroups::options(), array_filter([static::storedParent()])))
                ->label((string) __t('contacts.company_parent')),
        ];
    }

    /** La capogruppo salvata dell'azienda aperta; 0 se nessuna. */
    private static function storedParent(): int
    {
        $id = (int) (static::currentId() ?? 0);
        $row = $id > 0 ? static::$model::findById($id) : null;

        return is_array($row) ? (int) ($row['parent_id'] ?? 0) : 0;
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
        $summary = static::brandsSyncRepeaterRelations($parentId, static::withLinkableBrands(static::withoutHiddenBrands($post)), $files, $action, $context);

        if (static::addressBrandsShown() && isset($post[self::ADDRESS_BRANDS_MARKER])) {
            $summary['address_brands'] = static::syncAddressBrands((int) $parentId, $post, $files);
        }

        return $summary;
    }

    /** Senza «Brand» anche i brand dell'azienda: senza il marcatore i legami restano come sono. */
    protected static function withoutHiddenBrands(array $post): array
    {
        if (!Brands::enabled()) {
            unset($post[static::$brandsMarker], $post[static::$brandsInput]);
        }

        return parent::withoutHiddenBrands($post);
    }

    /**
     * Il POST dei brand con solo quelli che chi scrive può legare: che vede o
     * che ha appena creato con la «+» (`ContactVisibility::linkableBrands()`).
     * Un brand che non vede non si lega alla sua azienda, nemmeno postandone
     * l'id. Chi vede tutto non cambia.
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    protected static function withLinkableBrands(array $post): array
    {
        if (!isset($post[static::$brandsMarker]) || !ContactVisibility::restricted(CompanyBrand::$table)) {
            return $post;
        }

        $posted = $post[static::$brandsInput] ?? [];
        $posted = is_array($posted) ? $posted : [$posted];
        $ids = array_map(static fn ($item): mixed => is_array($item) ? ($item['brand_id'] ?? 0) : $item, $posted);
        $allowed = array_flip(ContactVisibility::linkableBrands(array_values(array_filter($ids, 'is_scalar'))));

        $post[static::$brandsInput] = array_values(array_filter($posted, static function ($item) use ($allowed): bool {
            $id = is_array($item) ? ($item['brand_id'] ?? 0) : $item;

            return is_scalar($id) && isset($allowed[(int) $id]);
        }));

        return $post;
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

    /** La card dei brand, con la capogruppo; solo la capogruppo, senza «Brand». */
    protected static function brandCards(): array
    {
        $brands = Brands::enabled();
        $groups = static::groupsShown();

        if (!$brands && !$groups) {
            return [];
        }

        return [
            (new Card)->components([
                SectionTitle::make($brands ? __t('gestionale.contacts.brands.title') : __t('gestionale.contacts.groups.title'))
                    ->tooltip((string) ($brands ? __t('gestionale.contacts.brands.tooltip') : __t('gestionale.contacts.groups.tooltip')))
                    ->columnSpan(12),
                ...($brands ? [
                    // Il titolo è già quello della card; senza brand non c'è una riga vuota da ignorare.
                    static::getInput(static::$brandsInput)->label('')->repeaterStartEmpty()->columnSpan(12),
                    static::getInput(static::$brandsMarker)->columnSpan(12),
                ] : []),
                // La capogruppo: un livello solo, la valida `HasCompanyGroup`.
                ...($groups ? [static::getInput(static::$groupInput)->columnSpan(12)] : []),
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
