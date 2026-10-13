<?php

namespace Wonder\Plugin\Gestionale\Support\Sales;

use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Support\Contacts\Contacts;
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
            $conditions[] = '(`name` LIKE '.$like.' OR `surname` LIKE '.$like.' OR `business_name` LIKE '.$like
                .' OR `email` LIKE '.$like.' OR `pi` LIKE '.$like.' OR `cf` LIKE '.$like.')';
        }

        $rows = Contact::find(implode(' AND ', $conditions), self::CUSTOMER_LIMIT, 'id', 'DESC');
        $rows = is_array($rows) ? (isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'))) : [];

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'label' => Contacts::displayName($row),
            'detail' => trim(implode(' · ', array_filter([(string) ($row['email'] ?? ''), (string) ($row['city'] ?? '')]))),
        ], $rows);
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
