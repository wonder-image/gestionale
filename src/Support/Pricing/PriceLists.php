<?php

namespace Wonder\Plugin\Gestionale\Support\Pricing;

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Pricing\PriceList;
use Wonder\Plugin\Gestionale\Models\Pricing\PriceListChannel;
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
 * Una riga può avere più listini candidati (#162): prima quello del cliente,
 * poi quello del canale dell'ordine. `resolve()` li prova in ordine e vince il
 * primo che «trova» il prodotto: una riga propria del prodotto batte la regola
 * in percentuale, e un listino con la percentuale trova sempre il prodotto.
 * Le percentuali non si sommano mai. Se nessuno lo trova vale il prezzo base.
 *
 * `load()`, `forContact()` e `forChannel()` leggono il database; `applies()`,
 * `tier()`, `toBasis()`, `byRule()` e `resolve()` sono pure e si provano senza.
 */
final class PriceLists
{
    /** Le regole in percentuale: nessuna, ricarico o sconto sul prezzo base. */
    public const RULES = ['none', 'markup', 'discount'];

    /**
     * Il listino e le sue righe per prodotto, o `null` se non vale.
     *
     * @return array{id: int, prices_include_tax: bool, rule: string, percent: float, items: array<int, list<array{min_quantity: float, price: float}>>}|null
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

        $rule = (string) ($list['price_rule'] ?? 'none');

        return [
            'id' => $priceListId,
            'prices_include_tax' => ($list['prices_include_tax'] ?? 'true') === 'true',
            'rule' => in_array($rule, self::RULES, true) ? $rule : 'none',
            'percent' => max(0.0, (float) ($list['percent'] ?? 0)),
            'items' => $items,
        ];
    }

    /**
     * I listini che valgono, nell'ordine dato, senza doppioni né quelli che
     * non valgono (scaduti, spenti, d'altra valuta).
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public static function loadAll(array $ids, string $now, string $currency): array
    {
        $lists = [];

        foreach (array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)) as $id) {
            $list = self::load($id, $now, $currency);

            if ($list !== null) {
                $lists[] = $list;
            }
        }

        return $lists;
    }

    /** Il listino di un canale di vendita, 0 se nessuno o con la funzionalità spenta. */
    public static function forChannel(string $channel): int
    {
        return Gestionale::feature('customer_price_lists') ? self::forChannelAny($channel) : 0;
    }

    /** Il listino assegnato al canale, anche con la funzionalità spenta (per il form). */
    public static function forChannelAny(string $channel): int
    {
        if (trim($channel) === '') {
            return 0;
        }

        $found = PriceListChannel::find(['channel' => $channel]);
        $row = is_array($found) && array_key_exists('id', $found) ? $found : (is_array($found) ? (array_values(array_filter($found, 'is_array'))[0] ?? null) : null);

        return is_array($row) ? max(0, (int) ($row['price_list_id'] ?? 0)) : 0;
    }

    /**
     * I canali assegnati a un listino.
     *
     * @return list<string>
     */
    public static function channelsOf(int $priceListId): array
    {
        if ($priceListId <= 0) {
            return [];
        }

        $found = PriceListChannel::find(['price_list_id' => $priceListId]);
        $rows = is_array($found) ? (array_key_exists('id', $found) ? [$found] : array_filter($found, 'is_array')) : [];

        return array_values(array_unique(array_map(static fn (array $row): string => (string) $row['channel'], $rows)));
    }

    /**
     * Assegna al listino esattamente questi canali. Un canale ha un solo
     * listino: chi chiama controlla prima che non sia già di un altro.
     *
     * @param list<string> $channels
     */
    public static function assignChannels(int $priceListId, array $channels): void
    {
        if ($priceListId <= 0) {
            return;
        }

        PriceListChannel::query()->Delete(PriceListChannel::$table, 'WHERE `price_list_id` = '.$priceListId);

        foreach (array_values(array_unique($channels)) as $channel) {
            PriceListChannel::create(['channel' => $channel, 'price_list_id' => $priceListId]);
        }
    }

    /**
     * I listini candidati di una riga: prima quello del cliente, poi quello
     * del canale.
     *
     * @return list<int>
     */
    public static function candidates(int $contactListId, string $channel): array
    {
        return array_values(array_unique(array_filter([$contactListId, self::forChannel($channel)], static fn (int $id): bool => $id > 0)));
    }

    /**
     * Il prezzo di una riga fra più listini, già nella base dell'ordine.
     *
     * Vince il primo listino che trova il prodotto: con una riga propria
     * (lo scaglione per quella quantità) o, se non ce l'ha, con la regola in
     * percentuale sul prezzo base. `$basePrice` è il prezzo pieno del
     * catalogo, già nella base dell'ordine; il prezzo scontato non conta.
     * Prezzo 0 e listino 0 se nessuno lo trova.
     *
     * @param list<array<string, mixed>|null> $lists
     * @return array{price: float, price_list_id: int}
     */
    public static function resolve(array $lists, int $productId, float $quantity, float $basePrice, bool $orderIncludesTax, float $taxRate): array
    {
        foreach ($lists as $list) {
            if (!is_array($list)) {
                continue;
            }

            $price = self::unitPrice($list, $productId, $quantity, $orderIncludesTax, $taxRate);

            if ($price <= 0.0) {
                $price = self::byRule((string) ($list['rule'] ?? 'none'), (float) ($list['percent'] ?? 0), $basePrice);
            }

            if ($price > 0.0) {
                return ['price' => $price, 'price_list_id' => (int) ($list['id'] ?? 0)];
            }
        }

        return ['price' => 0.0, 'price_list_id' => 0];
    }

    /**
     * Il listino da registrare sulla testata: il primo dei candidati usato da
     * una riga, altrimenti il primo che vale, 0 se nessuno.
     *
     * @param list<array<string, mixed>> $lists
     * @param list<int> $used
     */
    public static function headerOf(array $lists, array $used): int
    {
        foreach ($lists as $list) {
            if (in_array((int) ($list['id'] ?? 0), $used, true)) {
                return (int) $list['id'];
            }
        }

        return (int) ($lists[0]['id'] ?? 0);
    }

    /** Il prezzo base con il ricarico o lo sconto in percentuale; 0 se la regola non c'è. */
    public static function byRule(string $rule, float $percent, float $basePrice): float
    {
        if ($basePrice <= 0.0 || $percent <= 0.0) {
            return 0.0;
        }

        return match ($rule) {
            'markup' => round($basePrice * (1 + $percent / 100), 2),
            'discount' => $percent < 100 ? round($basePrice * (1 - $percent / 100), 2) : 0.0,
            default => 0.0,
        };
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
