<?php

namespace Wonder\Plugin\Gestionale\Support\Mcp;

use Throwable;
use Wonder\Plugin\CustomerAccount\Support\BrandData;
use Wonder\Plugin\CustomerAccount\Support\CompanyBrands;
use Wonder\Plugin\CustomerAccount\Support\ContactPoints;
use Wonder\Plugin\CustomerAccount\Support\ContactRelations;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Contacts\Company;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Resources\Contacts\ContactAddressResource;
use Wonder\Plugin\Gestionale\Resources\Contacts\CustomerResource;
use Wonder\Plugin\Gestionale\Support\Contacts\Contacts;
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
    public const COMPANY_KEYS = ['business_name', 'cf', 'pi', 'sdi', 'pec', 'country', 'street', 'number', 'province', 'city', 'cap', 'more'];

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
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
                'description' => 'Legge un cliente per id e `kind` (`person`, predefinito, o `company`): anagrafica, contatti (con `contact_points`, le email e i telefoni in più: non ricevono documenti), dati di fatturazione e indirizzi di spedizione. Con i referenti attivi, `people` (per un\'azienda: le persone con ruolo, `departments` = le aree di cui si occupano, vuota = tutte, principale, riceve i documenti) e `companies` (per una persona: le aziende di cui è referente). Un\'azienda ha anche `brands` (nome, ruolo, predefinito, logo, sito, dominio, descrizione); i suoi indirizzi hanno `brand_ids` (vuota = per tutti) e con `brand_id` vengono prima quelli di quel brand, senza quelli degli altri. Per un preventivo d\'azienda usa `company_id` e una persona di `people` come `customer_id`.',
                'schema' => self::schema([
                    'id' => ['type' => 'integer', 'minimum' => 1],
                    'kind' => ['type' => 'string', 'enum' => self::KINDS],
                    'brand_id' => ['type' => 'integer', 'minimum' => 0],
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

        foreach ($kind !== '' ? [$kind] : ['company', 'person'] as $side) {
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

        if (!is_array($row) || $row === [] || (string) ($row['deleted'] ?? 'false') === 'true'
            || (string) ($row['is_customer'] ?? 'false') !== 'true'
            || !RowVisibility::current()->visible(($company ? Company::class : Contact::class)::$table, $id)) {
            throw McpError::make('customer_not_found', 0, ['id' => $id]);
        }

        $customer = ['id' => $id, 'kind' => $company ? 'company' : 'person', 'display_name' => Contacts::displayName($row)];

        foreach (array_merge(OfficeCustomerPanel::CONTACT_KEYS, $company ? self::COMPANY_KEYS : OfficeCustomerPanel::BILLING_KEYS) as $key) {
            $customer[$key] = (string) ($row[$key] ?? '');
        }

        $customer['active'] = (string) ($row['active'] ?? 'true') === 'true';
        $customer['contact_points'] = self::points($company ? ContactPoints::COMPANY : ContactPoints::CONTACT, $id);
        // Gli indirizzi dell'azienda per brand: un brand che non è suo lascia solo quelli per tutti.
        $addresses = $company
            ? OfficeOrders::companyAddresses($id, max(0, (int) filter_var($args['brand_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['default' => 0]])))
            : ContactAddressResource::addressesOf($id);
        $customer['addresses'] = array_map(static function (array $address) use ($company): array {
            $out = ['id' => (int) ($address['id'] ?? 0), 'is_default' => (string) ($address['is_default'] ?? '') === 'true'];

            foreach (OfficeOrders::shippingKeys() as $key) {
                $out[$key] = (string) ($address[$key] ?? '');
            }

            if ($company) {
                $out['brand_ids'] = array_map('intval', (array) ($address['brand_ids'] ?? []));
            }

            return $out;
        }, $addresses);

        if ($company && CustomerMode::companies()) {
            $customer['brands'] = self::brands($id);
        }

        if (ContactRelations::enabled()) {
            $customer += self::relations($id, $company);
        }

        return ['result' => $customer];
    }

    /**
     * Le email e i telefoni in più della scheda: solo per leggerli, non
     * ricevono documenti né aprono un accesso.
     *
     * @return list<array{kind: string, label: string, value: string, phone_prefix: string}>
     */
    private static function points(string $owner, int $id): array
    {
        try {
            $rows = ContactPoints::of($owner, $id);
        } catch (Throwable) {
            return [];
        }

        return array_map(static fn (array $point): array => [
            'kind' => (string) ($point['kind'] ?? ''),
            'label' => (string) ($point['label'] ?? ''),
            'value' => (string) ($point['value'] ?? ''),
            'phone_prefix' => (string) ($point['phone_prefix'] ?? ''),
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
        $out = [];

        foreach ($links as $link) {
            $data = BrandData::fromRow((array) $link['brand']);
            $keep = $kit ? [...BrandData::BASE_FIELDS, ...BrandData::KIT_FIELDS] : BrandData::BASE_FIELDS;
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
     * di una persona. Un errore di lettura lascia l'elenco vuoto.
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

        foreach ($rows as $row) {
            $other = $row[$company ? 'contact' : 'company'] ?? null;
            $contact = is_array($other) ? $other : [];

            if (($row['deleted'] ?? 'false') !== 'false' || ($contact['deleted'] ?? 'false') !== 'false') {
                continue;
            }

            $out[] = [
                'id' => (int) ($row[$side] ?? 0),
                'display_name' => Contacts::displayName($contact),
                'email' => (string) ($contact['email'] ?? ''),
                'job_title' => (string) ($row['job_title'] ?? ''),
                // Le aree di cui si occupa (vuota = tutte), con le etichette da leggere.
                'departments' => $areas = ContactRelations::cleanDepartments($row['departments'] ?? []),
                'departments_label' => DocumentReferents::areasLabel($areas),
                'is_primary' => ($row['is_primary'] ?? 'false') === 'true',
                'receives_documents' => ($row['receives_documents'] ?? 'false') === 'true',
            ];
        }

        return [$company ? 'people' : 'companies' => $out];
    }
}
