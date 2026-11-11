<?php

namespace Wonder\Plugin\Gestionale\Resources\Contacts;

use InvalidArgumentException;
use Throwable;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\Input;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\RepeaterColumn;
use Wonder\App\Support\Repeater;
use Wonder\Elements\Components\{Card, SectionTitle, Text};
use Wonder\Elements\Form\Form;
use Wonder\Plugin\CustomerAccount\Models\Company;
use Wonder\Plugin\CustomerAccount\Models\CompanyBrand;
use Wonder\Plugin\CustomerAccount\Resources\CompanyBrandResource as BaseCompanyBrandResource;
use Wonder\Plugin\CustomerAccount\Support\CompanyAddresses;
use Wonder\Plugin\CustomerAccount\Support\CompanyBrands;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Support\Contacts\Brands;
use Wonder\Plugin\Gestionale\Support\Contacts\ContactVisibility;
use Wonder\Plugin\Gestionale\Support\Contacts\QuickContacts;

/**
 * I brand delle aziende nel gestionale: stessa scheda di customer-account,
 * con i permessi dell'area contatti, così il «+» compare a chi gestisce
 * le anagrafiche.
 *
 * Chi ha una visibilità per riga (il commerciale) vede e modifica solo i
 * brand legati a un'azienda sua (`SalesAgents::brandsRule()`, applicata dal
 * core a elenco, scheda, modifica e API): qui si restringono anche le
 * scelte del «Brand principale», gli avvisi dei nomi simili e la «+».
 * Con la funzionalità «Brand» (`Brands::enabled()`) ha la voce «Brand» in
 * Anagrafiche; spenta, niente menu, pagine (anche dall'URL) né «+».
 *
 * In modifica c'è il riquadro «Indirizzi»: gli indirizzi di consegna e di
 * ritiro delle aziende del brand che valgono per lui
 * (`CompanyAddresses::forBrand()`). Si correggono da qui, senza crearne né
 * toglierne: quello resta nella scheda dell'azienda.
 */
class CompanyBrandResource extends BaseCompanyBrandResource
{
    /** Il repeater degli indirizzi che valgono per il brand e il campo che dice al salvataggio che il form li ha mostrati. */
    public const ADDRESSES_INPUT = 'brand_addresses';
    public const ADDRESSES_MARKER = 'brand_addresses_form';

    /** Il «+» accanto a «Ufficio» con i permessi dell'area contatti. */
    public static function officeTypeResource(): string
    {
        return OfficeTypeResource::class;
    }

    public static function path(): string
    {
        return 'app/gestionale/brand';
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->access(Gestionale::area('contatti'));
    }

    /*
     * I campi sono quelli di customer-account (nome, brand principale, logo,
     * sito, dominio, descrizione e, con il kit, il resto): qui si accende
     * solo il brand kit, con la funzionalità «Brand kit», prima di disegnare
     * o salvare il form. Non al boot del modulo: lì lo stato delle
     * funzionalità si leggerebbe prima del database e resterebbe «tutto
     * spento» per la richiesta.
     */

    public static function formSchema(): array
    {
        static::useKit();
        $fields = parent::formSchema();

        // Solo in modifica: un brand nuovo non ha ancora aziende.
        if (static::currentBrandId() > 0) {
            $fields[] = static::addressesField();
            $fields[] = FormField::key(self::ADDRESSES_MARKER)->hidden()->value('1');
        }

        if (!ContactVisibility::restricted(CompanyBrand::$table)) {
            return $fields;
        }

        // Il «Brand principale» fra quelli che vede, più quello già scelto.
        $keep = array_filter([(int) (static::currentParent() ?? 0)]);

        foreach ($fields as $index => $field) {
            if ((string) ($field->name ?? '') === 'parent_id') {
                $fields[$index] = FormField::key('parent_id')
                    ->selectSearch(['' => 'Nessuno'] + ContactVisibility::options(CompanyBrand::$table, CompanyBrands::parentOptions(), $keep))
                    ->label(static::labelSchema()['parent_id']);
            }
        }

        return $fields;
    }

    /** Il brand che si sta modificando, dall'indirizzo della pagina (0 in creazione). */
    private static function currentBrandId(): int
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');

        return preg_match('#/'.preg_quote(basename(static::path()), '#').'/(\d+)/#', $uri, $match) === 1 ? (int) $match[1] : 0;
    }

    /** Il brand principale del brand che si sta modificando. */
    private static function currentParent(): ?int
    {
        $id = static::currentBrandId();
        $brand = $id > 0 ? CompanyBrands::find($id) : null;

        return $brand !== null ? (int) ($brand['parent_id'] ?? 0) : null;
    }

    /**
     * Gli indirizzi che valgono per il brand: gli stessi campi del repeater
     * dell'azienda (`CustomerResource::addressesField()`), senza «Presso»,
     * nome e predefinito, che restano come sono. L'azienda e «Vale per» si
     * leggono soltanto; le righe non si aggiungono.
     */
    protected static function addressesField(): Input
    {
        $text = static fn (string $key): string => (string) __t('gestionale.contacts.brand_addresses.'.$key);

        return FormField::key(self::ADDRESSES_INPUT)
            ->repeater([
                RepeaterColumn::key('id')->hidden(),
                RepeaterColumn::key('company_name')->text()->readonly()->label($text('company'))->columnSpan(5),
                RepeaterColumn::key('scope')->text()->readonly()->label($text('scope'))->columnSpan(4),
                RepeaterColumn::key('label')->text()->label($text('label'))->columnSpan(3),
                // La riga la ricava places.js dal nome della via: il prefisso dichiarato vale per tutte.
                RepeaterColumn::key('street')->text()->label($text('street'))
                    ->attribute('data-wi-place="'.self::ADDRESSES_INPUT.'[]["')->columnSpan(10),
                RepeaterColumn::key('number')->text()->label($text('number'))->columnSpan(2),
                RepeaterColumn::key('country')->country('province')->value('IT')->label($text('country'))->columnSpan(3),
                RepeaterColumn::key('province')->states('IT')->label($text('province'))->columnSpan(3),
                RepeaterColumn::key('city')->text()->label($text('city'))->columnSpan(4),
                RepeaterColumn::key('cap')->text()->label($text('cap'))->columnSpan(2),
                // Place ID e coordinate li scrive Google: nascosti, ripuliti da `CompanyAddresses`.
                RepeaterColumn::key('place_id')->hidden(),
                RepeaterColumn::key('lat')->hidden(),
                RepeaterColumn::key('lng')->hidden(),
            ])
            ->nested()
            ->repeaterStartEmpty()
            ->repeaterAddButton(false)
            ->label('');
    }

    /**
     * Le aziende del brand che chi scrive vede: null se le vede tutte.
     *
     * @return list<int>|null
     */
    private static function visibleCompanies(int $brandId): ?array
    {
        if (!ContactVisibility::restricted(Company::$table)) {
            return null;
        }

        return ContactVisibility::visibleIds(Company::$table, array_map(
            static fn (array $company): int => (int) ($company['id'] ?? 0),
            CompanyBrands::companiesOf($brandId)
        ));
    }

    /**
     * Le righe del riquadro «Indirizzi»: quelle salvate, con sopra i campi
     * appena postati (un salvataggio rifiutato non perde le correzioni). Una
     * riga postata con un id che non vale per il brand non compare.
     *
     * @param mixed $posted le righe del repeater già lette dalla richiesta
     * @return list<array<string, mixed>>
     */
    protected static function addressRows(int $brandId, mixed $posted = null): array
    {
        $typed = [];

        foreach (is_array($posted) ? $posted : [] as $row) {
            $id = is_array($row) && is_scalar($row['id'] ?? null) ? (int) $row['id'] : 0;

            if ($id > 0) {
                $typed[$id] ??= $row;
            }
        }

        $shown = array_flip([...CompanyAddresses::BRAND_FIELDS, 'place_id', 'lat', 'lng']);
        $rows = [];

        foreach (CompanyAddresses::forBrand($brandId, static::visibleCompanies($brandId)) as $address) {
            $id = (int) ($address['id'] ?? 0);
            $row = ['id' => $id] + array_intersect_key($address, $shown);

            foreach (array_intersect_key($typed[$id] ?? [], $shown) as $field => $value) {
                if (is_scalar($value)) {
                    $row[$field] = (string) $value;
                }
            }

            $row['company_name'] = (string) ($address['company_name'] ?? '');
            $row['scope'] = (string) __t('gestionale.contacts.brand_addresses.'.(($address['all_brands'] ?? false) ? 'scope_all' : 'scope_brand'));
            $rows[] = $row;
        }

        return $rows;
    }

    /** Il repeater degli indirizzi non sta nei due gruppi del brand: ha il suo riquadro. */
    protected static function extraFormFields(): array
    {
        return [self::ADDRESSES_INPUT, self::ADDRESSES_MARKER];
    }

    /** Il riquadro «Indirizzi», solo in modifica; senza indirizzi dice da dove si aggiungono. */
    protected static function extraFormCards(array $fields): array
    {
        if (!isset($fields[self::ADDRESSES_INPUT], $fields[self::ADDRESSES_MARKER])) {
            return [];
        }

        $text = static fn (string $key): string => (string) __t('gestionale.contacts.brand_addresses.'.$key);
        $title = SectionTitle::make($text('title'))->tooltip($text('help'))->columnSpan(12);
        $brandId = static::currentBrandId();

        if (CompanyAddresses::forBrand($brandId, static::visibleCompanies($brandId)) === []) {
            return [(new Card)->columns(12)->columnSpan(12)->components([$title, Text::make($text('none'))->muted()->columnSpan(12)])];
        }

        return [(new Card)->columns(12)->columnSpan(12)->components([
            $title,
            $fields[self::ADDRESSES_INPUT]->columnSpan(12),
            $fields[self::ADDRESSES_MARKER]->columnSpan(12),
        ])];
    }

    /** Senza «Brand» il brand non ha pagine; chi ha una visibilità per riga lo crea solo dalla «+», legato alla sua azienda. */
    public static function pageSchema(): PageSchema
    {
        $schema = parent::pageSchema();

        if (!Brands::enabled()) {
            return $schema->only([]);
        }

        return ContactVisibility::restricted(CompanyBrand::$table) ? $schema->disable(['create', 'store']) : $schema;
    }

    /** La voce «Brand» in Anagrafiche, dopo le aziende, con i permessi dell'area contatti. */
    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->section('anagrafiche', 'Anagrafiche', 'bi-people', 320)
            ->title('Brand')
            ->order(17)
            ->access(Gestionale::area('contatti'))
            ->enabled(Brands::enabled());
    }

    public static function icon(): string
    {
        return 'bi-tags';
    }

    /** Gli avvisi dei nomi simili, solo fra i brand che chi scrive vede. */
    public static function formNotices(array $values, string $mode): array
    {
        if (!ContactVisibility::restricted(CompanyBrand::$table)) {
            return parent::formNotices($values, $mode);
        }

        $similar = CompanyBrands::similar(is_scalar($values['name'] ?? null) ? (string) $values['name'] : '', (int) ($values['id'] ?? 0));
        $visible = array_flip(ContactVisibility::visibleIds(CompanyBrand::$table, array_map(static fn (array $b): int => (int) $b['id'], $similar)));
        $names = [];

        foreach ($similar as $brand) {
            if (isset($visible[(int) $brand['id']])) {
                $names[] = CompanyBrands::label($brand);
            }
        }

        return $names === [] ? [] : [['level' => 'warning', 'message' => (string) __t('contacts.brand_similar', ['names' => implode(', ', $names)])]];
    }

    public static function formLayoutSchema(): ?Form
    {
        static::useKit();

        return parent::formLayoutSchema();
    }

    public static function mutateFormValues(array $values, string $mode, string $context = 'backend'): array
    {
        static::useKit();
        $brandId = (int) ($values['id'] ?? 0);

        if ($brandId > 0) {
            $values[self::ADDRESSES_INPUT] = static::addressRows($brandId, $values[self::ADDRESSES_INPUT] ?? null);
        }

        return parent::mutateFormValues($values, $mode, $context);
    }

    /**
     * Chi ha una visibilità per riga non sceglie come brand principale uno
     * che non vede (quello già salvato resta); il resto lo controlla
     * customer-account. Gli indirizzi del riquadro si controllano qui, prima
     * di scrivere il brand: li salva poi `syncRepeaterRelations()`.
     */
    public static function mutateRequestValues(array $values, string $action, string $context = 'backend', ?array $oldValues = null): array
    {
        static::useKit();
        $parent = $values['parent_id'] ?? null;
        $parentId = is_scalar($parent) && ctype_digit(trim((string) $parent)) ? (int) $parent : 0;

        if ($parentId > 0 && $parentId !== (int) ($oldValues['parent_id'] ?? 0)
            && ContactVisibility::visibleIds(CompanyBrand::$table, [$parentId]) === []) {
            throw new InvalidArgumentException((string) __t('contacts.brand_parent_missing'));
        }

        $brandId = (int) ($oldValues['id'] ?? 0);

        if ($action === 'update' && $brandId > 0 && isset($values[self::ADDRESSES_MARKER])) {
            $problems = CompanyAddresses::brandProblems($brandId, static::postedAddresses($values), static::visibleCompanies($brandId));

            if ($problems !== []) {
                throw new InvalidArgumentException(implode("\n", array_map(
                    static fn (string $field): string => (string) __t('contacts.field_invalid', [
                        'field' => __t('gestionale.contacts.brand_addresses.title').': '.__t('gestionale.contacts.brand_addresses.'.$field),
                    ]),
                    $problems
                )));
            }
        }

        return parent::mutateRequestValues($values, $action, $context, $oldValues);
    }

    /**
     * Dopo il brand, gli indirizzi del riquadro: solo se il form li ha
     * mostrati (il segnaposto), solo quelli che valgono per il brand e che
     * chi scrive vede, solo i campi del riquadro. Mai in creazione.
     */
    public static function syncRepeaterRelations(int|string $parentId, array $post, array $files = [], string $action = 'store', string $context = 'backend'): array
    {
        $summary = parent::syncRepeaterRelations($parentId, $post, $files, $action, $context);
        $brandId = (int) $parentId;

        if ($action === 'update' && $brandId > 0 && isset($post[self::ADDRESSES_MARKER])) {
            $summary[self::ADDRESSES_INPUT] = ['updated' => CompanyAddresses::updateFromBrand($brandId, static::postedAddresses($post), static::visibleCompanies($brandId))];
        }

        return $summary;
    }

    /**
     * Le righe del riquadro «Indirizzi» nella richiesta.
     *
     * @param array<string, mixed> $post
     * @return list<array<string, mixed>>
     */
    private static function postedAddresses(array $post): array
    {
        return array_values(array_filter(Repeater::rowsFromRequest(self::ADDRESSES_INPUT, $post), 'is_array'));
    }

    /** Accende il kit e il CRM con le loro funzionalità; non li spegne mai, se li ha accesi il sito. */
    public static function useKit(): void
    {
        if (Gestionale::feature('brand_kit')) {
            CompanyBrands::useKit();
        }

        \Wonder\Plugin\Gestionale\Support\Contacts\Crm::apply();
    }

    /**
     * Il «+» del brand. Dalla scheda di un'azienda crea solo il brand (il
     * legame lo scrive il salvataggio del form); dall'ordine da ufficio arriva
     * anche l'azienda (`QuickContacts::LINK_COMPANY`), e il brand nasce già
     * legato a lei, in fondo ai suoi, senza toccare ruolo e predefinito degli
     * altri. L'azienda
     * si controlla prima di creare: deve esistere e chi scrive deve vederla.
     * Del modal passa solo il nome.
     */
    public static function quickCreateStore(array $values, string $labelField): array
    {
        if (!Brands::enabled()) {
            return ['error' => 'I brand delle aziende sono spenti: si accendono da Set Up → Funzionalità.'];
        }

        $companyId = 0;

        if (array_key_exists(QuickContacts::LINK_COMPANY, $values)) {
            $raw = $values[QuickContacts::LINK_COMPANY];
            $companyId = is_scalar($raw) && ctype_digit(trim((string) $raw)) ? (int) $raw : 0;

            if ($companyId <= 0) {
                return ['error' => 'Scegli prima l\'azienda: il brand nasce legato a lei.'];
            }

            if (!QuickContacts::reachableCompany($companyId)) {
                return ['error' => 'Questa azienda non c\'è più o non è fra le tue: ricarica la pagina.'];
            }
        }

        $name = is_scalar($values['name'] ?? null) ? (string) $values['name'] : '';
        $result = ContactVisibility::restricted(CompanyBrand::$table)
            ? static::restrictedCreate($name)
            : parent::quickCreateStore(['name' => $name], $labelField);
        $brandId = (int) ($result['id'] ?? 0);

        if ($companyId <= 0 || $brandId <= 0 || CompanyBrands::belongsTo($brandId, $companyId)) {
            return $result;
        }

        try {
            static::linkTo($companyId, $brandId);
        } catch (Throwable) {
            return ['error' => 'Il brand è salvato, ma non sono riuscito a legarlo all\'azienda: aggiungilo dalla scheda dell\'azienda.'];
        }

        return $result;
    }

    /**
     * La «+» di chi vede solo alcuni brand. Un nome già usato da un brand che
     * vede riprende quello; da un brand che non vede si rifiuta, senza dire
     * di chi è: agganciarlo gli aprirebbe il brand di altri, crearne un
     * doppione sporcherebbe l'anagrafica (ci pensa l'ufficio). Niente avviso
     * dei nomi simili, che nominerebbe brand altrui. Il brand nuovo resta in
     * sessione: lo può legare alla sua azienda salvando la scheda.
     *
     * @return array{id?: int, label?: string, item?: array<string, mixed>, message?: string, error?: string}
     */
    protected static function restrictedCreate(string $name): array
    {
        $same = CompanyBrands::byName($name);

        if ($same !== null && ContactVisibility::visibleIds(CompanyBrand::$table, [(int) $same['id']]) === []) {
            return ['error' => 'Non posso creare questo brand: chiedi a chi gestisce le anagrafiche.'];
        }

        $result = CompanyBrands::findOrCreate($name);

        if ($result === null) {
            return ['error' => (string) __t('contacts.brand_not_created')];
        }

        if ($result['created']) {
            ContactVisibility::rememberBrand($result['id']);
        }

        $label = CompanyBrands::label($result['brand']);

        return [
            'id' => $result['id'],
            'label' => $label,
            'item' => ['id' => $result['id'], 'name' => $label],
            'message' => $result['created'] ? '' : (string) __t('contacts.brand_existing', ['name' => $label]),
        ];
    }

    /**
     * Aggiunge il brand ai legami aperti dell'azienda. `sync()` stacca quelli
     * che non riceve: si ripassano tutti, con il loro ruolo e il predefinito.
     */
    protected static function linkTo(int $companyId, int $brandId): void
    {
        $items = array_map(static fn (array $link): array => [
            'brand_id' => $link['brand_id'],
            'role' => $link['role'],
            'is_default' => $link['is_default'],
        ], CompanyBrands::linksOf($companyId));
        $items[] = ['brand_id' => $brandId];

        CompanyBrands::sync($companyId, $items);
    }
}
