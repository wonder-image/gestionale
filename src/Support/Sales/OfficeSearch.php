<?php

namespace Wonder\Plugin\Gestionale\Support\Sales;

use Wonder\App\Visibility\RowVisibility;
use Wonder\Plugin\CustomerAccount\Support\ContactRelations;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Contacts\Company;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Support\Contacts\Contacts;
use Wonder\Plugin\Gestionale\Support\Contacts\CustomerMode;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Plugin\Gestionale\Support\Stock\StockVersionSearch;

/**
 * Le ricerche della pagina *Nuovo ordine* (G9 §2.2): le versioni con il
 * prezzo di listino e il disponibile della sede, i clienti per nome.
 *
 * Le versioni sono quelle di `StockVersionSearch` (mai i multiprodotti), solo
 * quelle in vendita: da ufficio non si vende una versione ferma. Il prezzo è
 * un suggerimento per la casella; quello vero lo decide il motore del
 * carrello al salvataggio (listino, campagna, prezzo a mano).
 */
final class OfficeSearch
{
    public const CUSTOMER_LIMIT = 20;

    /** @return list<array<string, mixed>> */
    public static function versions(string $q, int $locationId): array
    {
        return self::enrich(StockVersionSearch::search($q, $locationId), $locationId);
    }

    /** @return array{result: string, versions: list<array<string, mixed>>} */
    public static function scan(string $code, int $locationId): array
    {
        $versions = self::enrich(StockVersionSearch::scan($code, $locationId)['versions'], $locationId);

        return ['result' => StockVersionSearch::outcome($versions), 'versions' => $versions];
    }

    /**
     * Le versioni già nella pagina, rilette (cambio di sede).
     *
     * @param list<mixed> $ids
     * @return list<array<string, mixed>>
     */
    public static function describe(array $ids, int $locationId): array
    {
        return self::enrich(array_values(StockVersionSearch::describe($ids, $locationId)), $locationId, true);
    }

    /**
     * I clienti attivi che contengono ogni parola nel nome, nella ragione
     * sociale, nell'email o nella partita IVA.
     *
     * @return list<array{id: int, label: string, detail: string}>
     */
    public static function customers(string $q): array
    {
        $words = array_slice(preg_split('/\s+/u', trim($q), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 5);

        if ($words === []) {
            return [];
        }

        $conditions = ["`deleted` = 'false'", "`is_customer` = 'true'", "`active` = 'true'"];

        foreach ($words as $word) {
            $like = "'%".addcslashes(Contact::connection()->real_escape_string($word), '%_')."%'";
            $conditions[] = '(`name` LIKE '.$like.' OR `surname` LIKE '.$like
                .' OR `email` LIKE '.$like.' OR `cf` LIKE '.$like.')';
        }

        // Il commerciale trova solo i suoi clienti (#77).
        $rows = Contact::find(RowVisibility::applySql(Contact::$table, implode(' AND ', $conditions), RowVisibility::currentUser()), self::CUSTOMER_LIMIT, 'id', 'DESC');
        $rows = is_array($rows) ? (isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'))) : [];

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'label' => Contacts::displayName($row),
            'detail' => trim(implode(' · ', array_filter([(string) ($row['email'] ?? ''), (string) ($row['city'] ?? '')]))),
        ], $rows);
    }

    /**
     * Persone e aziende clienti attive, per l'intestatario di un documento.
     *
     * L'`id` di ogni risultato è la chiave composta del cliente:
     * `contact:12` per una persona, `company:7` per un'azienda (vedi
     * `customerKey()`). Le aziende si cercano su ragione sociale, P.IVA, CF
     * ed email; il dettaglio le distingue con «Azienda · P.IVA …». Le persone
     * legate a un'azienda cliente compaiono anche come «Persona — Azienda»
     * (`company:7:12`): sceglierle apre l'azienda con quel referente.
     * Il modo del gestionale (`CustomerMode`) toglie le aziende (e i loro
     * referenti) o le persone clienti.
     *
     * @return list<array{id: string, kind: string, label: string, detail: string}>
     */
    public static function customerChoices(string $q): array
    {
        $words = self::words($q);

        if ($words === []) {
            return [];
        }

        $withCompanies = CustomerMode::companies();
        $withPeople = CustomerMode::people();

        $companies = $withCompanies ? array_map(static fn (array $row): array => [
            'id' => self::customerKey(0, (int) $row['id']),
            'kind' => 'company',
            'label' => trim((string) ($row['business_name'] ?? '')) ?: 'Azienda #'.(int) $row['id'],
            'detail' => self::companyDetail($row),
        ], self::rows(Company::class, $words, ['business_name', 'pi', 'cf', 'email'])) : [];

        $people = $withPeople ? array_map(static fn (array $row): array => [
            'id' => self::customerKey((int) $row['id'], 0),
            'kind' => 'contact',
            'label' => Contacts::displayName($row),
            'detail' => implode(' · ', array_filter(['Privato', trim((string) ($row['email'] ?? '')), trim((string) ($row['city'] ?? ''))])),
        ], self::rows(Contact::class, $words, ['name', 'surname', 'email', 'cf'])) : [];

        // Chi è sia privato sia referente compare due volte: come privato e
        // come la sua azienda; si sceglie con quale veste procedere.
        $people = [...($withCompanies ? self::referents($words, array_column($companies, 'id')) : []), ...$people];

        // Metà posti a testa; quelli che una parte non usa vanno all'altra.
        $half = intdiv(self::CUSTOMER_LIMIT, 2);
        $companies = array_slice($companies, 0, max($half, self::CUSTOMER_LIMIT - count($people)));

        return array_slice([...$companies, ...$people], 0, self::CUSTOMER_LIMIT);
    }

    /**
     * Le aziende clienti (attive e visibili) delle persone che contengono le
     * parole: la riga è l'azienda, con il referente già scelto nella chiave
     * (`company:N:P`). La persona non deve essere cliente; un'azienda già in
     * `$skip` (chiavi `company:N`) o già trovata non si ripete.
     *
     * @param list<string> $words
     * @param list<string> $skip
     * @return list<array{id: string, kind: string, label: string, detail: string}>
     */
    private static function referents(array $words, array $skip = []): array
    {
        if (!ContactRelations::enabled()) {
            return [];
        }

        $persons = self::rows(Contact::class, $words, ['name', 'surname', 'email'], false);
        $links = [];

        foreach ($persons as $person) {
            foreach (ContactRelations::companiesOf((int) $person['id']) as $link) {
                if (($link['deleted'] ?? 'false') === 'true') {
                    continue;
                }

                $links[] = [$person, (int) ($link['company_id'] ?? 0)];
            }
        }

        $ids = array_values(array_unique(array_filter(array_column($links, 1))));

        if ($ids === []) {
            return [];
        }

        $where = "`id` IN (".implode(', ', $ids).") AND `deleted` = 'false' AND `is_customer` = 'true' AND `active` = 'true'";
        $companies = [];

        foreach (self::list(Company::find(RowVisibility::applySql(Company::$table, $where, RowVisibility::currentUser()))) as $company) {
            $companies[(int) $company['id']] = $company;
        }

        $out = [];
        $seen = array_fill_keys($skip, true);

        foreach ($links as [$person, $companyId]) {
            if (!isset($companies[$companyId]) || isset($seen[self::customerKey(0, $companyId)])) {
                continue;
            }

            $seen[self::customerKey(0, $companyId)] = true;
            $out[] = [
                'id' => self::customerKey((int) $person['id'], $companyId, true),
                'kind' => 'company',
                'label' => trim((string) ($companies[$companyId]['business_name'] ?? '')) ?: 'Azienda #'.$companyId,
                'detail' => implode(' · ', array_filter(['Azienda', 'referente '.Contacts::displayName($person), trim((string) ($companies[$companyId]['pi'] ?? '')) !== '' ? 'P.IVA '.trim((string) $companies[$companyId]['pi']) : ''])),
            ];
        }

        return $out;
    }

    /**
     * La chiave composta del cliente: `company:N` se c'è l'azienda, altrimenti
     * `contact:N`. Con `$withReferent` l'azienda porta anche la persona
     * (`company:N:P`).
     */
    public static function customerKey(int $contactId, int $companyId, bool $withReferent = false): string
    {
        if ($companyId > 0) {
            return 'company:'.$companyId.($withReferent && $contactId > 0 ? ':'.$contactId : '');
        }

        return $contactId > 0 ? 'contact:'.$contactId : '';
    }

    /**
     * Da chiave composta a `[contact_id, company_id]`. Un numero da solo è una
     * persona (le ricerche di prima); `company:N:P` dà anche il referente P;
     * una chiave non valida dà `[0, 0]`.
     *
     * @return array{0: int, 1: int}
     */
    public static function parseCustomerKey(string $key): array
    {
        $key = trim($key);

        if (preg_match('/^company:(\d+)(?::(\d+))?$/', $key, $m) === 1) {
            return [(int) ($m[2] ?? 0), (int) $m[1]];
        }

        if (preg_match('/^contact:(\d+)$/', $key, $m) === 1) {
            return [(int) $m[1], 0];
        }

        return ctype_digit($key) ? [(int) $key, 0] : [0, 0];
    }

    /** @param array<string, mixed> $row */
    public static function companyDetail(array $row): string
    {
        $pi = trim((string) ($row['pi'] ?? ''));

        return implode(' · ', array_filter([
            'Azienda',
            $pi !== '' ? 'P.IVA '.$pi : '',
            trim((string) ($row['email'] ?? '')),
            trim((string) ($row['city'] ?? '')),
        ]));
    }

    /** @return list<string> */
    private static function words(string $q): array
    {
        return array_slice(preg_split('/\s+/u', trim($q), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 5);
    }

    /**
     * Le schede clienti attive che contengono ogni parola in una delle colonne.
     *
     * @param class-string<Contact|Company> $model
     * @param list<string> $words
     * @param list<string> $columns
     * @return list<array<string, mixed>>
     */
    private static function rows(string $model, array $words, array $columns, bool $customers = true): array
    {
        $conditions = $customers ? ["`deleted` = 'false'", "`is_customer` = 'true'", "`active` = 'true'"] : ["`deleted` = 'false'"];

        foreach ($words as $word) {
            $like = "'%".addcslashes($model::connection()->real_escape_string($word), '%_')."%'";
            $conditions[] = '('.implode(' OR ', array_map(static fn (string $c): string => '`'.$c.'` LIKE '.$like, $columns)).')';
        }

        // Le persone dei referenti non filtrano per visibilità: conta quella dell'azienda.
        $where = implode(' AND ', $conditions);
        $where = $customers ? RowVisibility::applySql($model::$table, $where, RowVisibility::currentUser()) : $where;

        return self::list($model::find($where, self::CUSTOMER_LIMIT, 'id', 'DESC'));
    }

    /** @return list<array<string, mixed>> */
    private static function list(mixed $rows): array
    {
        return is_array($rows) ? (isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'))) : [];
    }

    /**
     * Prezzo di listino (lo scontato, se più basso) e disponibile della sede.
     *
     * @param list<array<string, mixed>> $versions
     * @return list<array<string, mixed>>
     */
    private static function enrich(array $versions, int $locationId, bool $keepInactive = false): array
    {
        if (!$keepInactive) {
            $versions = array_values(array_filter($versions, static fn (array $v): bool => empty($v['inactive'])));
        }

        $ids = array_map(static fn (array $v): int => (int) $v['id'], $versions);

        if ($ids === []) {
            return [];
        }

        $location = $locationId > 0 ? $locationId : Locations::mainId();
        $levels = Levels::forSale($ids, $location);
        $products = Product::find('`id` IN ('.implode(', ', $ids).')');
        $products = is_array($products) ? (isset($products['id']) ? [$products] : array_values(array_filter($products, 'is_array'))) : [];
        $prices = [];

        foreach ($products as $product) {
            $price = round((float) ($product['price'] ?? 0), 2);
            $sale = round((float) ($product['sale_price'] ?? 0), 2);
            $prices[(int) $product['id']] = $sale > 0 && $sale < $price ? $sale : $price;
        }

        foreach ($versions as $i => $version) {
            $id = (int) $version['id'];
            $versions[$i]['price'] = $prices[$id] ?? 0.0;
            $versions[$i]['available'] = round((float) ($levels[$id]['available'] ?? 0), 3);
        }

        return $versions;
    }
}
