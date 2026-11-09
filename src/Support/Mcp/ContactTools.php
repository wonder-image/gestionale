<?php

namespace Wonder\Plugin\Gestionale\Support\Mcp;

use Throwable;
use Wonder\Plugin\CustomerAccount\Support\ContactRelations;
use Wonder\Plugin\CustomerAccount\Models\CompanyAddress;
use Wonder\Plugin\Gestionale\Models\Contacts\Company;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Resources\Contacts\ContactAddressResource;
use Wonder\Plugin\Gestionale\Resources\Contacts\CustomerResource;
use Wonder\Plugin\Gestionale\Support\Contacts\Contacts;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeCustomerPanel;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeOrders;

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
                'description' => 'Legge un cliente per id e `kind` (`person`, predefinito, o `company`): anagrafica, contatti, dati di fatturazione e indirizzi di spedizione. Con i referenti attivi, `people` (per un\'azienda: le persone con ruolo, principale, riceve i documenti) e `companies` (per una persona: le aziende di cui è referente). Per un preventivo d\'azienda usa `company_id` e una persona di `people` come `customer_id`.',
                'schema' => self::schema(['id' => ['type' => 'integer', 'minimum' => 1], 'kind' => ['type' => 'string', 'enum' => self::KINDS]], ['id']),
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

            foreach (self::rows($model::find(implode(' AND ', $conditions), $offset.', '.$limit, 'id', 'DESC')) as $row) {
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
            || (string) ($row['is_customer'] ?? 'false') !== 'true') {
            throw McpError::make('customer_not_found', 0, ['id' => $id]);
        }

        $customer = ['id' => $id, 'kind' => $company ? 'company' : 'person', 'display_name' => Contacts::displayName($row)];

        foreach (array_merge(OfficeCustomerPanel::CONTACT_KEYS, $company ? self::COMPANY_KEYS : OfficeCustomerPanel::BILLING_KEYS) as $key) {
            $customer[$key] = (string) ($row[$key] ?? '');
        }

        $customer['active'] = (string) ($row['active'] ?? 'true') === 'true';
        $addresses = $company
            ? self::rows(CompanyAddress::find(['company_id' => $id, 'deleted' => 'false'], null, 'position', 'ASC'))
            : ContactAddressResource::addressesOf($id);
        $customer['addresses'] = array_map(static function (array $address): array {
            $out = ['id' => (int) ($address['id'] ?? 0), 'is_default' => (string) ($address['is_default'] ?? '') === 'true'];

            foreach (OfficeOrders::shippingKeys() as $key) {
                $out[$key] = (string) ($address[$key] ?? '');
            }

            return $out;
        }, $addresses);

        if (ContactRelations::enabled()) {
            $customer += self::relations($id, $company);
        }

        return ['result' => $customer];
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
                'is_primary' => ($row['is_primary'] ?? 'false') === 'true',
                'receives_documents' => ($row['receives_documents'] ?? 'false') === 'true',
            ];
        }

        return [$company ? 'people' : 'companies' => $out];
    }
}
