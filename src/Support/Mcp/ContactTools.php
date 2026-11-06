<?php

namespace Wonder\Plugin\Gestionale\Support\Mcp;

use Throwable;
use Wonder\Plugin\CustomerAccount\Support\ContactRelations;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Resources\Contacts\ContactAddressResource;
use Wonder\Plugin\Gestionale\Resources\Contacts\CustomerResource;
use Wonder\Plugin\Gestionale\Support\Contacts\Contacts;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeCustomerPanel;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeOrders;

/**
 * Gli strumenti MCP dei clienti: solo lettura. La scheda si modifica dal
 * backend, dove passa per le sue regole (partita IVA unica, export, ecc.).
 * Sono i contatti con il ruolo di cliente: i soli fornitori non si vedono.
 */
final class ContactTools
{
    use ToolSupport;

    /** Le parole cercate al massimo, come nella ricerca del nuovo ordine. */
    private const WORDS = 5;

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return [
            'customers_list' => [
                'description' => 'Elenca i clienti, i più recenti prima: id, nome da mostrare, tipo, email, telefono, città, partita IVA, attivo. `query` cerca per nome, cognome, ragione sociale, email, partita IVA o codice fiscale. Al massimo '.Tools::LIST_LIMIT.' per pagina; `offset` per le successive.',
                'schema' => self::schema([
                    'query' => ['type' => 'string'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => Tools::LIST_LIMIT],
                    'offset' => ['type' => 'integer', 'minimum' => 0],
                ], []),
                'permission' => [CustomerResource::class, 'list'],
                'write' => false,
                'run' => self::customersList(...),
            ],
            'customer_get' => [
                'description' => 'Legge un cliente per id: anagrafica, contatti, dati di fatturazione e indirizzi di spedizione. Con i referenti attivi, `people` (per un\'azienda: le persone con ruolo, principale, riceve i documenti) e `companies` (per una persona: le aziende di cui è referente).',
                'schema' => self::schema(['id' => ['type' => 'integer', 'minimum' => 1]], ['id']),
                'permission' => [CustomerResource::class, 'view'],
                'write' => false,
                'run' => self::customerGet(...),
            ],
        ];
    }

    private static function customersList(array $args): array
    {
        $conditions = ["`deleted` = 'false'", '`'.CustomerResource::roleColumn()."` = 'true'"];
        $words = array_slice(preg_split('/\s+/u', trim((string) ($args['query'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, self::WORDS);

        foreach ($words as $word) {
            $like = "'%".addcslashes(Contact::connection()->real_escape_string($word), '%_\\')."%'";
            $conditions[] = '(`name` LIKE '.$like.' OR `surname` LIKE '.$like.' OR `business_name` LIKE '.$like
                .' OR `email` LIKE '.$like.' OR `pi` LIKE '.$like.' OR `cf` LIKE '.$like.')';
        }

        $limit = min(Tools::LIST_LIMIT, max(1, (int) ($args['limit'] ?? 20)));
        $offset = max(0, (int) ($args['offset'] ?? 0));

        return ['result' => array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'display_name' => Contacts::displayName($row),
            'type' => (string) ($row['type'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'phone' => trim((string) ($row['phone_prefix'] ?? '').' '.(string) ($row['phone'] ?? '')),
            'city' => (string) ($row['city'] ?? ''),
            'pi' => (string) ($row['pi'] ?? ''),
            'active' => (string) ($row['active'] ?? 'true') === 'true',
        ], self::rows(Contact::find(implode(' AND ', $conditions), $offset.', '.$limit, 'id', 'DESC')))];
    }

    private static function customerGet(array $args): array
    {
        $id = self::int($args, 'id');
        $row = Contact::findById($id);

        if (!is_array($row) || $row === [] || (string) ($row['deleted'] ?? 'false') === 'true'
            || (string) ($row[CustomerResource::roleColumn()] ?? 'false') !== 'true') {
            throw McpError::make('customer_not_found', 0, ['id' => $id]);
        }

        $customer = ['id' => $id, 'display_name' => Contacts::displayName($row)];

        foreach (array_merge(OfficeCustomerPanel::CONTACT_KEYS, OfficeCustomerPanel::BILLING_KEYS) as $key) {
            $customer[$key] = (string) ($row[$key] ?? '');
        }

        $customer['active'] = (string) ($row['active'] ?? 'true') === 'true';
        $customer['addresses'] = array_map(static function (array $address): array {
            $out = ['id' => (int) ($address['id'] ?? 0), 'is_default' => (string) ($address['is_default'] ?? '') === 'true'];

            foreach (OfficeOrders::shippingKeys() as $key) {
                $out[$key] = (string) ($address[$key] ?? '');
            }

            return $out;
        }, ContactAddressResource::addressesOf($id));

        if (ContactRelations::enabled()) {
            $customer += self::relations($id, Contacts::isCompany($row));
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
            $contact = is_array($row['contact'] ?? null) ? $row['contact'] : [];

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
