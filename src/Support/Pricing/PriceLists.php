<?php

namespace Wonder\Plugin\Gestionale\Support\Pricing;

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Pricing\PriceList;
use Wonder\Plugin\Gestionale\Models\Pricing\PriceListItem;

/**
 * Il prezzo di listino di una riga (architettura 4.6): se il listino vale,
 * quale scaglione, e la conversione fra IVA inclusa ed esclusa.
 *
 * Il listino vale se è attivo, se oggi sta fra `valid_from` e `valid_to`
 * (estremi compresi, vuoti = senza limite) e se la sua valuta è quella
 * dell'ordine. Altrimenti non c'è: le righe tornano al prezzo di catalogo e
 * `LinePrice` sceglie fra campagna, prezzo scontato e prezzo base.
 *
 * `load()` e `forContact()` leggono il database; `applies()`, `tier()` e
 * `toBasis()` sono pure e si provano senza.
 */
final class PriceLists
{
    /**
     * Il listino e le sue righe per prodotto, o `null` se non vale.
     *
     * @return array{id: int, prices_include_tax: bool, items: array<int, list<array{min_quantity: float, price: float}>>}|null
     */
    public static function load(int $priceListId, string $now, string $currency): ?array
    {
        if ($priceListId <= 0 || !Gestionale::feature('customer_price_lists')) {
            return null;
        }

        $list = PriceList::findById($priceListId);

        if (!is_array($list) || !self::applies($list, $now, $currency)) {
            return null;
        }

        $found = PriceListItem::find(['price_list_id' => $priceListId]);
        $rows = is_array($found) ? (array_key_exists('id', $found) ? [$found] : array_filter($found, 'is_array')) : [];
        $items = [];

        foreach ($rows as $row) {
            $items[(int) $row['product_id']][] = [
                'min_quantity' => (float) ($row['min_quantity'] ?? 1),
                'price' => (float) ($row['price'] ?? 0),
            ];
        }

        return [
            'id' => $priceListId,
            'prices_include_tax' => ($list['prices_include_tax'] ?? 'true') === 'true',
            'items' => $items,
        ];
    }

    /** Il listino assegnato a un cliente, 0 se nessuno o con la funzionalità spenta. */
    public static function forContact(int $contactId): int
    {
        if ($contactId <= 0 || !Gestionale::feature('customer_price_lists')) {
            return 0;
        }

        $contact = Contact::findById($contactId);

        return is_array($contact) ? max(0, (int) ($contact['price_list_id'] ?? 0)) : 0;
    }

    /**
     * Il prezzo di listino di una riga, già nella base dell'ordine; 0 se il
     * listino non copre il prodotto per quella quantità.
     */
    public static function unitPrice(?array $list, int $productId, float $quantity, bool $orderIncludesTax, float $taxRate): float
    {
        if ($list === null || !isset($list['items'][$productId])) {
            return 0.0;
        }

        $price = self::tier($list['items'][$productId], $quantity);

        return $price > 0.0 ? self::toBasis($price, $list['prices_include_tax'], $orderIncludesTax, $taxRate) : 0.0;
    }

    /** Se il listino vale oggi e in quella valuta. @param array<string, mixed> $list */
    public static function applies(array $list, string $now, string $currency): bool
    {
        if (($list['active'] ?? 'false') !== 'true') {
            return false;
        }

        if (strtoupper(trim((string) ($list['currency'] ?? 'EUR'))) !== strtoupper(trim($currency))) {
            return false;
        }

        $today = substr($now, 0, 10);
        $from = substr(trim((string) ($list['valid_from'] ?? '')), 0, 10);
        $to = substr(trim((string) ($list['valid_to'] ?? '')), 0, 10);

        // Le date «zero» di MySQL valgono come vuote.
        if ($from !== '' && $from !== '0000-00-00' && $today < $from) {
            return false;
        }

        return $to === '' || $to === '0000-00-00' || $today <= $to;
    }

    /**
     * Lo scaglione: la riga con la quantità minima più alta che non supera la
     * quantità ordinata. 0 se nessuna ci arriva.
     *
     * @param list<array{min_quantity: float, price: float}> $tiers
     */
    public static function tier(array $tiers, float $quantity): float
    {
        $best = null;

        foreach ($tiers as $tier) {
            $min = max(0.0, (float) $tier['min_quantity']);

            if ($min <= $quantity + 1e-9 && ($best === null || $min > $best['min_quantity'])) {
                $best = ['min_quantity' => $min, 'price' => (float) $tier['price']];
            }
        }

        return $best === null ? 0.0 : round(max(0.0, $best['price']), 2);
    }

    /** Il prezzo del listino portato alla base dell'ordine, IVA inclusa o esclusa. */
    public static function toBasis(float $price, bool $listIncludesTax, bool $orderIncludesTax, float $taxRate): float
    {
        if ($listIncludesTax === $orderIncludesTax || $taxRate <= 0.0) {
            return round($price, 2);
        }

        return $orderIncludesTax
            ? round($price * (1 + $taxRate / 100), 2)
            : round($price / (1 + $taxRate / 100), 2);
    }
}
