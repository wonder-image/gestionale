<?php

namespace Wonder\Plugin\Gestionale\Support\Pricing;

use InvalidArgumentException;
use Wonder\Plugin\Gestionale\Support\Catalog\Exports\ProductFeed;
use Wonder\Plugin\Gestionale\Support\Promotions\ProductScope;

/**
 * Il prezzo di un prodotto su un canale (#162): l'unico punto da cui feed,
 * export e integrazioni (Google, Meta, eBay, marketplace, …) leggono il
 * prezzo di listino.
 *
 * Il canale deve stare in `PriceListChannels`: una chiave sconosciuta è un
 * errore, non un prezzo base silenzioso. Senza listino sul canale, o con la
 * funzionalità `customer_price_lists` spenta, vale il prezzo di catalogo.
 *
 * Gli ordini non passano di qui: `Cart` mette prima il listino del cliente
 * (`PriceLists::candidates()`).
 */
final class ChannelPrice
{
    /**
     * I listini validi del canale, pronti per `applyLists()` / `PriceLists::resolve()`.
     *
     * @return list<array<string, mixed>>
     */
    public static function lists(string $channel, ?string $now = null, ?string $currency = null): array
    {
        self::assertKnown($channel);

        return PriceLists::loadAll(
            [PriceLists::forChannel($channel)],
            $now ?? date('Y-m-d H:i:s'),
            $currency ?? ProductFeed::currency()
        );
    }

    /**
     * Il prezzo di listino di un prodotto sul canale, nella base IVA chiesta;
     * `null` se nessun listino lo prezza (vale `$basePrice`).
     *
     * @return array{price: float, price_list_id: int}|null
     */
    public static function of(string $channel, int $productId, float $basePrice, bool $includesTax, float $taxRate, float $quantity = 1.0): ?array
    {
        $hit = PriceLists::resolve(self::lists($channel), $productId, $quantity, $basePrice, $includesTax, $taxRate);

        return $hit['price'] > 0 ? $hit : null;
    }

    /**
     * Le righe di un export (`id`, `price`, `tax_rate`) con il prezzo di listino del canale.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function apply(array $rows, string $channel, bool $includesTax): array
    {
        $lists = self::lists($channel);
        $facts = null;

        if (PriceLists::needsFacts($lists)) {
            // Un feed ha molte righe: i fatti si leggono una volta per tutto il catalogo.
            $catalog = ProductScope::catalog();
            $facts = static fn (int $id): array => $catalog[$id]['facts'] ?? ProductScope::facts($id);
        }

        return self::applyLists($rows, $lists, $includesTax, $facts);
    }

    /**
     * Puro: per una unità, il prezzo del primo listino che trova la riga al
     * posto di quello di catalogo, con `price_list_id`; scontato e campagna
     * cadono. La riga che nessun listino trova resta com'è.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<array<string, mixed>> $lists da `lists()` o `PriceLists::loadAll()`
     * @param (callable(int): array<string, mixed>)|null $facts i fatti per il selettore, come in `PriceLists::resolve()`
     * @return list<array<string, mixed>>
     */
    public static function applyLists(array $rows, array $lists, bool $includesTax, ?callable $facts = null): array
    {
        if ($lists === []) {
            return $rows;
        }

        foreach ($rows as $index => $row) {
            $hit = PriceLists::resolve($lists, (int) ($row['id'] ?? 0), 1.0, (float) ($row['price'] ?? 0), $includesTax, (float) ($row['tax_rate'] ?? 0), $facts);

            if ($hit['price'] > 0) {
                $rows[$index]['price'] = $hit['price'];
                $rows[$index]['price_list_id'] = $hit['price_list_id'];
                $rows[$index]['sale_price'] = null;
                unset($rows[$index]['campaign']);
            }
        }

        return $rows;
    }

    private static function assertKnown(string $channel): void
    {
        if (!PriceListChannels::has($channel)) {
            throw new InvalidArgumentException('Canale listino sconosciuto: '.$channel.'. Registralo in price_lists.channels.');
        }
    }
}
