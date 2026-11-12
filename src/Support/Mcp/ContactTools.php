<?php

namespace Wonder\Plugin\Gestionale\Support\Mcp;

use Throwable;
use Wonder\Plugin\CustomerAccount\Support\BrandData;
use Wonder\Plugin\CustomerAccount\Support\CompanyBrands;
use Wonder\Plugin\CustomerAccount\Support\ContactPoints;
use Wonder\Plugin\CustomerAccount\Support\ContactRelations;
use Wonder\Plugin\CustomerAccount\Support\Socials;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Contacts\Company;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Resources\Contacts\ContactAddressResource;
use Wonder\Plugin\Gestionale\Resources\Contacts\CustomerResource;
use Wonder\Plugin\Gestionale\Support\Contacts\Brands;
use Wonder\Plugin\Gestionale\Support\Contacts\Contacts;
use Wonder\Plugin\Gestionale\Support\Contacts\ContactVisibility;
use Wonder\Plugin\Gestionale\Support\Contacts\Crm;
use Wonder\Plugin\Gestionale\Support\Contacts\CustomerMode;
use Wonder\Plugin\Gestionale\Support\Contacts\DocumentReferents;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeCustomerPanel;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeOrders;
use Wonder\App\Visibility\RowVisibility;

/**
 * Gli strumenti MCP dei clienti: solo lettura. La scheda si modifica dal
 * backend, dove passa per le sue regole (partita IVA unica, export, ecc.).
 * Sono le persone e le aziende con il ruolo di cliente (`kind`: `person`,
 * `company`): i soli fornitori non si vedono.
 */
final class ContactTools
{
    use ToolSupport;

    /** Le parole cercate al massimo, come nella ricerca del nuovo ordine. */
    private const WORDS = 5;

    public const KINDS = ['person', 'company'];

    /** I campi di fatturazione di un'azienda. */
    public const COMPANY_KEYS = ['business_name', 'cf', 'pi', 'sdi', 'pec', 'country', 'street', 'number', 'province', 'city', 'cap', 'more', 'place_id', 'lat', 'lng'];

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        // I brand solo con «Brand»: spenta, né nella descrizione né nei parametri.
        $brands = Brands::enabled();
        // Tipi dei recapiti, recapiti dei referenti e social solo con «CRM».
        $crm = Crm::enabled();

        return [
            'customers_list' => [
                'description' => 'Elenca i clienti, i più recenti prima: id, `kind` (`person` o `company`), nome da mostrare, email, telefono, città, partita IVA (aziende), attivo. `kind` limita a persone o aziende (assente: prima le aziende, poi le persone). `query` cerca per nome, cognome, ragione sociale, email, partita IVA o codice fiscale. Al massimo '.Tools::LIST_LIMIT.' per tipo e pagina; `offset` per le successive.',
                'schema' => self::schema([
                    'kind' => ['type' => 'string', 'enum' => self::KINDS],
                    'query' => ['type' => 'string'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => Tools::LIST_LIMIT],
                    'offset' => ['type' => 'integer', 'minimum' => 0],
                ], []),
                'permission' => [CustomerResource::class, 'list'],
                'write' => false,
                'run' => self::customersList(...),
            ],
            'customer_get' => [
                'description' => 'Legge un cliente per id e `kind` (`person`, predefinito, o `company`): anagrafica, contatti (`contact_points`: tutte le email e i telefoni, i principali per primi con `is_primary`; `label` `access` è l\'email di accesso al sito; per un\'azienda `departments`, le aree a cui va quell\'email, vuota = tutte), dati di fatturazione e indirizzi di spedizione (con `business_name` = presso, `place_id`, `lat`, `lng`). Con i referenti attivi, `people` (per un\'azienda: le persone con ruolo, `departments` = le aree di cui si occupano, vuota = tutte; `email` è quella a cui scrivergli per l\'azienda, di lavoro se c\'è, con `work_email` e `work_phone`) e `companies` (per una persona: le aziende di cui è referente, con i contatti di lavoro). `is_legal_representative` dice chi è rappresentante legale di quell\'azienda (anche più d\'uno). Per il resto i referenti sono tutti uguali: in copia va solo quello scelto sul documento. '
                    .($crm ? 'Ogni contatto ha il suo `kind` (`email`, `phone` = telefono fisso, `mobile`, `whatsapp`); una persona ha `socials` (rete => indirizzo) e ogni referente `points`, tutti i suoi recapiti per quell\'azienda (`kind`, `value`, `phone_prefix`, `label`). ' : '')
                    .($brands ? 'Un\'azienda ha anche `brands` (nome, ruolo, predefinito, logo, sito, dominio, descrizione); i suoi indirizzi hanno `brand_ids` (vuota = per tutti) e con `brand_id` vengono prima quelli di quel brand, senza quelli degli altri. ' : '')
                    .'Per un preventivo d\'azienda usa `company_id` e una persona di `people` come `customer_id`.',
                'schema' => self::schema([
                    'id' => ['type' => 'integer', 'minimum' => 1],
                    'kind' => ['type' => 'string', 'enum' => self::KINDS],
                    ...($brands ? ['brand_id' => ['type' => 'integer', 'minimum' => 0]] : []),
                ], ['id']),
                'permission' => [CustomerResource::class, 'view'],
                'write' => false,
                'run' => self::customerGet(...),
            ],
        ];
    }

    private static function customersList(array $args): array
    {
        $kind = (string) ($args['kind'] ?? '');
        $words = array_slice(preg_split('/\s+/u', trim((string) ($args['query'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, self::WORDS);
        $limit = min(Tools::LIST_LIMIT, max(1, (int) ($args['limit'] ?? 20)));
        $offset = max(0, (int) ($args['offset'] ?? 0));
        $out = [];

        // Il modo del gestionale vale anche qui: senza aziende niente aziende,
        // con le sole aziende niente persone clienti.
        foreach (array_filter($kind !== '' ? [$kind] : ['company', 'person'], [self::class, 'kindShown']) as $side) {
            $company = $side === 'company';
            $model = $company ? Company::class : Contact::class;
            $columns = $company ? ['business_name', 'email', 'pi', 'cf'] : ['name', 'surname', 'email', 'cf'];
            $conditions = ["`deleted` = 'false'", "`is_customer` = 'true'"];

            foreach ($words as $word) {
                $like = "'%".addcslashes($model::connection()->real_escape_string($word), '%_\\')."%'";
                $conditions[] = '('.implode(' OR ', array_map(static fn (string $c): string => "`{$c}` LIKE {$like}", $columns)).')';
            }

            $where = RowVisibility::applySql($model::$table, implode(' AND ', $conditions), RowVisibility::currentUser());
            foreach (self::rows($model::find($where, $offset.', '.$limit, 'id', 'DESC')) as $row) {
                $out[] = [
                    'id' => (int) $row['id'],
                    'kind' => $side,
                    'display_name' => Contacts::displayName($row),
                    'email' => (string) ($row['email'] ?? ''),
                    'phone' => trim((string) ($row['phone_prefix'] ?? '').' '.(string) ($row['phone'] ?? '')),
                    'city' => (string) ($row['city'] ?? ''),
                    'pi' => (string) ($row['pi'] ?? ''),
                    'active' => (string) ($row['active'] ?? 'true') === 'true',
                ];
            }
        }

        return ['result' => $out];
    }

    private static function customerGet(array $args): array
    {
        $id = self::int($args, 'id');
        $company = (string) ($args['kind'] ?? 'person') === 'company';
        $row = $company ? Company::findById($id) : Contact::findById($id);

        if (!self::kindShown($company ? 'company' : 'person')
            || !is_array($row) || $row === [] || (string) ($row['deleted'] ?? 'false') === 'true'
            || (string) ($row['is_customer'] ?? 'false') !== 'true'
            || !RowVisibility::current()->visible(($company ? Company::class : Contact::class)::$table, $id)) {
            throw McpError::make('customer_not_found', 0, ['id' => $id]);
        }

        $customer = ['id' => $id, 'kind' => $company ? 'company' : 'person', 'display_name' => Contacts::displayName($row)];

        foreach (array_merge(OfficeCustomerPanel::CONTACT_KEYS, $company ? self::COMPANY_KEYS : OfficeCustomerPanel::BILLING_KEYS) as $key) {
            $customer[$key] = (string) ($row[$key] ?? '');
        }

        $customer['active'] = (string) ($row['active'] ?? 'true') === 'true';

        // I social sono della persona, e solo col CRM.
        if (!$company && Crm::enabled()) {
            $customer['socials'] = Socials::of($row);
        }

        $customer['contact_points'] = self::points($company ? ContactPoints::COMPANY : ContactPoints::CONTACT, $id, $row);
        // Gli indirizzi dell'azienda per brand: un brand che non è suo lascia solo quelli per tutti. Senza «Brand» tutti.
        $brands = $company && Brands::enabled();
        $addresses = $company
            ? OfficeOrders::companyAddresses($id, $brands ? max(0, (int) filter_var($args['brand_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['default' => 0]])) : 0)
            : ContactAddressResource::addressesOf($id);
        $customer['addresses'] = array_map(static function (array $address) use ($brands): array {
            $out = ['id' => (int) ($address['id'] ?? 0), 'is_default' => (string) ($address['is_default'] ?? '') === 'true'];

            foreach (OfficeOrders::shippingKeys() as $key) {
                $out[$key] = (string) ($address[$key] ?? '');
            }

            if ($brands) {
                $out['brand_ids'] = array_map('intval', (array) ($address['brand_ids'] ?? []));
            }

            return $out;
        }, $addresses);

        if ($brands) {
            $customer['brands'] = self::brands($id);
        }

        if (CustomerResource::relationsShown()) {
            $customer += self::relations($id, $company);
        }

        return ['result' => $customer];
    }

    /** Il tipo di cliente esiste nel modo del gestionale (`CustomerMode`), come le pagine. */
    public static function kindShown(string $kind): bool
    {
        return $kind === 'company' ? CustomerMode::companies() : CustomerMode::people();
    }

    /**
     * Tutti i contatti della scheda (`ContactPoints::all()`), i principali
     * per primi: `is_primary`, `label` (`access` = l'email con cui la persona
     * entra nel sito), `kind` (`email`, `phone`, `mobile`, `whatsapp`), `departments` (gli uffici, vuota = tutto) e
     * `departments_label`, da leggere. Solo
     * per leggerli: si scrive alle email che dicono `contact_email` dei
     * referenti e le aree, mai a una a caso.
     *
     * @return list<array{kind: string, label: string, value: string, phone_prefix: string, is_primary: bool, departments: list<string>, departments_label: string}>
     */
    private static function points(string $owner, int $id, array $card): array
    {
        try {
            $rows = ContactPoints::all($owner, $id, $card);
        } catch (Throwable) {
            return [];
        }

        return array_map(static fn (array $point): array => [
            'kind' => (string) ($point['kind'] ?? ''),
            'label' => (string) ($point['label'] ?? ''),
            'value' => (string) ($point['value'] ?? ''),
            'phone_prefix' => (string) ($point['phone_prefix'] ?? ''),
            'is_primary' => !empty($point['is_primary']),
            'departments' => $areas = array_values(array_map('strval', (array) ($point['departments'] ?? []))),
            'departments_label' => DocumentReferents::areasLabel($areas),
        ], $rows);
    }

    /**
     * I brand dell'azienda con i loro dati (`BrandData`); il brand kit solo
     * con la funzionalità «Brand kit». Testo puro: chi lo mostra lo escapa.
     *
     * @return list<array<string, mixed>>
     */
    private static function brands(int $companyId): array
    {
        try {
            $links = CompanyBrands::linksOf($companyId);
        } catch (Throwable) {
            return [];
        }

        $kit = Gestionale::feature('brand_kit');
        $crm = Gestionale::feature('crm');
        $out = [];

        foreach ($links as $link) {
            $data = BrandData::fromRow((array) $link['brand']);
            $keep = [...BrandData::BASE_FIELDS, ...($kit ? BrandData::KIT_FIELDS : ($crm ? BrandData::CRM_FIELDS : []))];
            $out[] = [
                'id' => (int) $link['brand_id'],
                'name' => CompanyBrands::label((array) $link['brand']),
                'role' => (string) $link['role'],
                'is_default' => (bool) $link['is_default'],
                'logo_url' => (string) ($data['logo_url'] ?? ''),
            ] + array_intersect_key($data, array_flip($keep));
        }

        return $out;
    }

    /**
     * I legami vivi della scheda (#145): le persone di un'azienda, le aziende
     * di una persona. Un errore di lettura lascia l'elenco vuoto. Col CRM ogni
     * legame porta `points`, i recapiti di lavoro uno per uno.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private static function relations(int $id, bool $company): array
    {
        $side = $company ? ContactRelations::PERSON : ContactRelations::COMPANY;

        try {
            $rows = $company ? ContactRelations::peopleOf($id) : ContactRelations::companiesOf($id);
        } catch (Throwable) {
            $rows = [];
        }

        $out = [];
        $crm = Crm::enabled();
        // Solo le schede che il chiamante vede: le aziende di una persona possono essere di altri commerciali.
        $visible = array_flip(ContactVisibility::visibleIds(
            ($company ? Contact::class : Company::class)::$table,
            array_map(static fn (array $row): int => (int) ($row[$side] ?? 0), $rows)
        ));

        foreach ($rows as $row) {
            $other = $row[$company ? 'contact' : 'company'] ?? null;
            $contact = is_array($other) ? $other : [];

            if (($row['deleted'] ?? 'false') !== 'false' || ($contact['deleted'] ?? 'false') !== 'false'
                || !isset($visible[(int) ($row[$side] ?? 0)])) {
                continue;
            }

            $legame = [
                'id' => (int) ($row[$side] ?? 0),
                'display_name' => Contacts::displayName($contact),
                // A chi scrivere per quell'azienda: l'email di lavoro se c'è, altrimenti quella della persona.
                'email' => (string) ($row['contact_email'] ?? ($contact['email'] ?? '')),
                'work_email' => (string) ($row['work_email'] ?? ''),
                'work_phone' => trim((string) ($row['work_phone_prefix'] ?? '').' '.(string) ($row['work_phone'] ?? '')),
                'job_title' => (string) ($row['job_title'] ?? ''),
                // Le aree di cui si occupa (vuota = tutte), con le etichette da leggere.
                'departments' => $areas = ContactRelations::cleanDepartments($row['departments'] ?? []),
                'departments_label' => DocumentReferents::areasLabel($areas),
                // Rappresentante legale di quell'azienda: può essercene più d'uno.
                'is_legal_representative' => ($row['is_legal_representative'] ?? 'false') === 'true',
            ];

            if ($crm) {
                $legame['points'] = array_values(array_map(static fn (array $point): array => [
                    'kind' => (string) ($point['kind'] ?? ''),
                    'value' => (string) ($point['value'] ?? ''),
                    'phone_prefix' => (string) ($point['phone_prefix'] ?? ''),
                    'label' => (string) ($point['label'] ?? ''),
                ], array_filter((array) ($row['points'] ?? []), 'is_array')));
            }

            $out[] = $legame;
        }

        return [$company ? 'people' : 'companies' => $out];
    }
}
