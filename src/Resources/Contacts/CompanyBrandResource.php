<?php

namespace Wonder\Plugin\Gestionale\Resources\Contacts;

use InvalidArgumentException;
use Throwable;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\Elements\Form\Form;
use Wonder\Plugin\CustomerAccount\Models\CompanyBrand;
use Wonder\Plugin\CustomerAccount\Resources\CompanyBrandResource as BaseCompanyBrandResource;
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
 */
class CompanyBrandResource extends BaseCompanyBrandResource
{
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

    /** Il brand principale del brand che si sta modificando, dall'indirizzo. */
    private static function currentParent(): ?int
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');

        if (preg_match('#/'.preg_quote(basename(static::path()), '#').'/(\d+)/#', $uri, $match) !== 1) {
            return null;
        }

        $brand = CompanyBrands::find((int) $match[1]);

        return $brand !== null ? (int) ($brand['parent_id'] ?? 0) : null;
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

        return parent::mutateFormValues($values, $mode, $context);
    }

    /**
     * Chi ha una visibilità per riga non sceglie come brand principale uno
     * che non vede (quello già salvato resta); il resto lo controlla
     * customer-account.
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

        return parent::mutateRequestValues($values, $action, $context, $oldValues);
    }

    /** Accende il kit con la funzionalità; non lo spegne mai, se l'ha acceso il sito. */
    public static function useKit(): void
    {
        if (Gestionale::feature('brand_kit')) {
            CompanyBrands::useKit();
        }
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
