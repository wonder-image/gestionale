<?php

namespace Wonder\Plugin\Gestionale\Support\Orders;

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Models\Tax\Tax;
use Wonder\Plugin\Gestionale\Models\Tax\TaxRule;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\Customizations;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductPhotos;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Pricing\LinePrice;
use Wonder\Plugin\Gestionale\Support\Pricing\OrderTotals;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Plugin\Gestionale\Support\Stock\ProductNames;
use Wonder\Plugin\Gestionale\Support\Stock\Stock;
use Wonder\Plugin\Gestionale\Support\Tax\TaxResolver;
use Wonder\Sql\Transaction;

/**
 * Il carrello: una riga di `gst_orders` con `stage='cart'` e le sue righe.
 *
 * **Non prenota niente.** La merce resta libera per tutti finché qualcuno non
 * arriva al checkout: tenerla impegnata da qui vorrebbe dire un magazzino
 * bloccato da carrelli abbandonati. La disponibilità si guarda lo stesso —
 * all'aggiunta, per non promettere quello che non c'è — ma è una fotografia,
 * non un impegno, e il checkout la rifà con la riga bloccata.
 *
 * Ogni operazione ricalcola prezzi e totali da capo: il prezzo di listino può
 * essere cambiato da quando la riga è entrata, e il carrello deve mostrare
 * quello che il cliente pagherà adesso, non quello che avrebbe pagato ieri.
 */
final class Cart
{
    /**
     * Il carrello dell'ospite o del cliente: quello che c'è, o uno nuovo.
     *
     * @param array{cart_token?: string, customer_id?: int, channel?: string, currency?: string, email?: string} $context
     * @return array<string, mixed>
     */
    public static function open(array $context): array
    {
        return Transaction::run(static function () use ($context): array {
            $customerId = (int) ($context['customer_id'] ?? 0);
            $token = trim((string) ($context['cart_token'] ?? ''));
            $found = null;

            if ($customerId > 0) {
                $found = Order::find(
                    ['stage' => 'cart', 'customer_id' => $customerId, 'deleted' => 'false'],
                    1
                );
            }

            if (!is_array($found) && $token !== '') {
                $found = Order::find(['stage' => 'cart', 'cart_token' => $token, 'deleted' => 'false'], 1);
            }

            if (is_array($found) && isset($found['id'])) {
                return self::touch((int) $found['id']);
            }

            $settings = Setting::current();
            $channel = (string) ($context['channel'] ?? 'online');
            $email = trim((string) ($context['email'] ?? ''));
            $created = Order::create([
                'code' => Code::make(Order::class, Codes::ORDER),
                'stage' => 'cart',
                'channel' => in_array($channel, Order::CHANNELS, true) ? $channel : 'online',
                'status' => 'draft',
                'payment_status' => 'unpaid',
                'fulfillment_status' => 'unfulfilled',
                'customer_id' => $customerId,
                'currency' => (string) ($context['currency'] ?? 'EUR'),
                'prices_include_tax' => (string) ($settings['catalog_prices_include_tax'] ?? 'true'),
                'cart_token' => $token !== '' ? $token : bin2hex(random_bytes(16)),
                'last_activity_at' => date('Y-m-d H:i:s'),
                // Un indirizzo vuoto non passa la validazione del campo: l'ospite
                // che non l'ha ancora scritto lo lascia fuori invece di tenerlo a "".
            ] + ($email !== '' ? ['email' => $email] : []));

            if (($created->success ?? false) !== true) {
                throw UserError::make('cart.not_opened');
            }

            $row = Order::findById((int) ($created->insert_id ?? 0));

            return is_array($row) ? $row : [];
        });
    }

    /**
     * Mette un articolo nel carrello, o ne aumenta la quantità.
     *
     * `customization` è `id della personalizzazione => testo o id dell'opzione`:
     * il sovrapprezzo lo decide il server dall'anagrafica, mai il client.
     *
     * @param array{product_id: int, quantity?: float, customization?: array<int|string, mixed>} $line
     * @return array{order: array<string, mixed>, items: list<array<string, mixed>>, removed: list<string>}
     */
    public static function add(int $cartId, array $line): array
    {
        return Transaction::run(static function () use ($cartId, $line): array {
            $cart = self::cart($cartId);
            $quantity = round((float) ($line['quantity'] ?? 1), 3);

            if ($quantity <= 0) {
                throw UserError::make('order.zero_quantity');
            }

            $productId = (int) ($line['product_id'] ?? 0);
            $product = self::product($productId);
            $resolved = self::resolveCustomization($product, (array) ($line['customization'] ?? []));
            $existing = self::itemLike($cartId, $productId, Customizations::signature($resolved['fields']));
            $wanted = round($quantity + (float) ($existing['quantity'] ?? 0), 3);

            self::assertAvailable($product, $wanted);

            if (is_array($existing)) {
                OrderItem::update(['quantity' => self::number($wanted)], (int) $existing['id']);
            } else {
                $model = ProductModel::findById((int) $product['product_model_id']);
                OrderItem::create([
                    'order_id' => $cartId,
                    'type' => 'product',
                    'product_id' => $productId,
                    'position' => self::nextPosition($cartId),
                    'sku' => (string) $product['sku'],
                    'name' => ProductNames::full($product, ProductNames::models([$product])),
                    'image' => ProductPhotos::forProduct($productId),
                    'unit' => (string) (is_array($model) ? ($model['unit'] ?? 'pz') : 'pz'),
                    'quantity' => self::number($wanted),
                    'tax_category_id' => (int) (is_array($model) ? ($model['tax_category_id'] ?? 0) : 0),
                    'customization' => Customizations::encode($resolved['fields']),
                    'customization_surcharge' => $resolved['surcharge'],
                ]);
            }

            unset($cart);

            return self::recalculate($cartId);
        });
    }

    /**
     * Riprezza tutte le righe e riscrive i totali.
     *
     * Le righe di un prodotto che non c'è più — cancellato o spento mentre
     * stava nel carrello — escono, e chi chiama le trova elencate in
     * `removed`: sparire in silenzio vorrebbe dire un totale che cala da solo.
     *
     * @return array{order: array<string, mixed>, items: list<array<string, mixed>>, removed: list<string>}
     */
    public static function recalculate(int $cartId): array
    {
        return Transaction::run(static function () use ($cartId): array {
            $cart = self::cart($cartId);
            $settings = Setting::current();
            $rules = self::rows(TaxRule::find(['deleted' => 'false']));
            $fallback = (int) ($settings['fallback_tax_id'] ?? 0);
            $country = strtoupper(trim((string) ($cart['billing_country'] ?? ''))) ?: 'IT';
            $customerType = trim((string) ($cart['billing_pi'] ?? '')) !== '' ? 'business' : 'private';
            $removed = [];
            $computed = [];
            $rewritten = [];

            foreach (self::items($cartId) as $item) {
                $productId = (int) ($item['product_id'] ?? 0);
                $product = $productId > 0 ? Product::findById($productId) : null;

                if ((string) $item['type'] === 'product'
                    && (!is_array($product) || ($product['active'] ?? 'false') !== 'true')) {
                    OrderItem::delete((int) $item['id']);
                    $removed[] = (string) $item['name'];

                    continue;
                }

                // Con la funzionalità spenta le righe già personalizzate restano
                // come sono: il sovrapprezzo scritto è quello che il cliente ha
                // visto, e rifarlo dall'anagrafica non si può.
                if ((string) $item['type'] === 'product' && Gestionale::feature('customizations')) {
                    try {
                        $resolved = Customizations::resolve(
                            (int) ($product['product_model_id'] ?? 0),
                            Customizations::valuesOf(Customizations::decode($item['customization'] ?? ''))
                        );
                    } catch (UserError) {
                        // L'anagrafica è cambiata sotto il carrello (personalizzazione
                        // spenta, opzione tolta, obbligo nuovo): la riga non è più
                        // vendibile così, e chi chiama lo trova in `removed`.
                        OrderItem::delete((int) $item['id']);
                        $removed[] = (string) $item['name'];

                        continue;
                    }

                    $item['customization_surcharge'] = $resolved['surcharge'];
                    // Le etichette cambiate in anagrafica si copiano finché la
                    // riga sta nel carrello; dopo l'ordine non si toccano più.
                    $rewritten[(int) $item['id']] = [
                        'customization' => Customizations::encode($resolved['fields']),
                        'customization_surcharge' => $resolved['surcharge'],
                    ];
                }

                $price = LinePrice::of([
                    'quantity' => $item['quantity'] ?? 0,
                    'price' => is_array($product) ? ($product['price'] ?? 0) : ($item['list_price'] ?? 0),
                    'sale_price' => is_array($product) ? ($product['sale_price'] ?? 0) : 0,
                    'manual_unit_price' => (string) $item['price_source'] === 'manual'
                        ? ($item['unit_price'] ?? '')
                        : '',
                    'discount_type' => $item['discount_type'] ?? 'none',
                    'discount_value' => $item['discount_value'] ?? 0,
                    'customization_surcharge' => $item['customization_surcharge'] ?? 0,
                ]);

                $taxId = TaxResolver::resolve(
                    $rules,
                    $country,
                    $customerType,
                    (int) ($item['tax_category_id'] ?? 0),
                    $fallback
                );
                $tax = $taxId > 0 ? Tax::findById($taxId) : null;

                $computed[] = $item['id'] ? [
                    'id' => (int) $item['id'],
                    'type' => (string) $item['type'],
                    'weight' => is_array($product) ? (float) ($product['weight'] ?? 0) : 0.0,
                    'tax_id' => $taxId,
                    'tax_rate' => is_array($tax) ? (float) ($tax['rate'] ?? 0) : 0.0,
                    'tax_nature' => is_array($tax) ? (string) ($tax['nature'] ?? '') : '',
                ] + $price : [];
            }

            $totals = OrderTotals::of($computed, [
                'prices_include_tax' => (string) ($cart['prices_include_tax'] ?? 'true') === 'true',
                'discount_type' => (string) ($cart['manual_discount_type'] ?? 'none'),
                'discount_value' => (float) ($cart['manual_discount_value'] ?? 0),
            ]);

            foreach ($totals['lines'] as $line) {
                OrderItem::update([
                    'list_price' => $line['list_price'],
                    'unit_price' => $line['unit_price'],
                    'price_source' => $line['price_source'],
                    'discount_type' => $line['discount_type'],
                    'discount_value' => $line['discount_value'],
                    'order_discount_amount' => $line['order_discount_amount'],
                    'quantity' => $line['quantity'],
                    'tax_id' => (int) $line['tax_id'],
                    'tax_rate' => number_format((float) $line['tax_rate'], 2, '.', ''),
                    'tax_nature' => (string) $line['tax_nature'],
                    'line_total' => $line['line_total'],
                ] + ($rewritten[(int) $line['id']] ?? []), (int) $line['id']);
            }

            Order::update([
                'products_total' => $totals['products_total'],
                'discount_total' => $totals['discount_total'],
                'shipping_total' => $totals['shipping_total'],
                'fees_total' => $totals['fees_total'],
                'taxable_total' => $totals['taxable_total'],
                'tax_total' => $totals['tax_total'],
                'total' => $totals['total'],
                'total_weight' => $totals['total_weight'],
                'last_activity_at' => date('Y-m-d H:i:s'),
            ], $cartId);

            return self::contents($cartId) + ['removed' => $removed];
        });
    }

    /**
     * Cambia la quantità di una riga. Zero vuol dire toglierla.
     *
     * @return array{order: array<string, mixed>, items: list<array<string, mixed>>, removed: list<string>}
     */
    public static function setQuantity(int $cartId, int $itemId, float $quantity): array
    {
        return Transaction::run(static function () use ($cartId, $itemId, $quantity): array {
            self::cart($cartId);
            $item = self::item($cartId, $itemId);
            $quantity = round($quantity, 3);

            if ($quantity <= 0) {
                OrderItem::delete($itemId);

                return self::recalculate($cartId);
            }

            if ((int) ($item['product_id'] ?? 0) > 0) {
                self::assertAvailable(self::product((int) $item['product_id']), $quantity);
            }

            OrderItem::update(['quantity' => self::number($quantity)], $itemId);

            return self::recalculate($cartId);
        });
    }

    /**
     * Toglie una riga dal carrello.
     *
     * @return array{order: array<string, mixed>, items: list<array<string, mixed>>, removed: list<string>}
     */
    public static function remove(int $cartId, int $itemId): array
    {
        return Transaction::run(static function () use ($cartId, $itemId): array {
            self::cart($cartId);
            self::item($cartId, $itemId);
            OrderItem::delete($itemId);

            return self::recalculate($cartId);
        });
    }

    /**
     * Versa il carrello dell'ospite in quello di chi ha appena fatto l'accesso.
     *
     * Le righe uguali si sommano, ma non oltre quello che c'è: due pezzi più
     * due, con tre sul banco, fanno tre. Scriverne quattro sposterebbe il
     * rifiuto al checkout, dove il cliente ha già messo l'indirizzo.
     *
     * @return array{order: array<string, mixed>, items: list<array<string, mixed>>, removed: list<string>}
     */
    public static function merge(int $guestCartId, int $targetCartId): array
    {
        return Transaction::run(static function () use ($guestCartId, $targetCartId): array {
            if ($guestCartId === $targetCartId) {
                return self::recalculate($targetCartId);
            }

            self::cart($targetCartId);
            self::cart($guestCartId);

            foreach (self::items($guestCartId) as $item) {
                $productId = (int) ($item['product_id'] ?? 0);
                $signature = Customizations::signature(Customizations::decode($item['customization'] ?? ''));
                $existing = self::itemLike($targetCartId, $productId, $signature);
                $wanted = round((float) $item['quantity'] + (float) ($existing['quantity'] ?? 0), 3);
                $wanted = self::capped($productId, $wanted);

                if ($wanted <= 0) {
                    continue;
                }

                if (is_array($existing)) {
                    OrderItem::update(['quantity' => self::number($wanted)], (int) $existing['id']);

                    continue;
                }

                OrderItem::update([
                    'order_id' => $targetCartId,
                    'position' => self::nextPosition($targetCartId),
                    'quantity' => self::number($wanted),
                ], (int) $item['id']);
            }

            // Le righe sommate a una già presente, o azzerate dalla giacenza, sono
            // ancora qui: la cancellazione fisica dell'ordine si ferma sulla
            // chiave esterna finché ce n'è una.
            foreach (self::items($guestCartId) as $left) {
                OrderItem::delete((int) $left['id']);
            }

            // Quello dell'ospite ha finito il suo mestiere: se restasse, il
            // prossimo accesso con lo stesso gettone lo ritroverebbe vuoto e
            // sembrerebbe che la roba sia sparita.
            Order::delete($guestCartId);

            return self::recalculate($targetCartId);
        });
    }

    /**
     * L'ordine e le sue righe, come stanno adesso.
     *
     * @return array{order: array<string, mixed>, items: list<array<string, mixed>>}
     */
    public static function contents(int $cartId): array
    {
        $order = Order::findById($cartId);

        return [
            'order' => is_array($order) ? $order : [],
            'items' => array_map(static function (array $item): array {
                $item['customization'] = Customizations::decode($item['customization'] ?? '');

                return $item;
            }, self::items($cartId)),
        ];
    }

    /**
     * La riga del carrello, bloccata, con la garanzia che sia un carrello.
     *
     * @return array<string, mixed>
     */
    private static function cart(int $cartId): array
    {
        $row = Order::findForUpdate(['id' => $cartId], 1);

        if (!is_array($row) || $row === []) {
            throw UserError::make('cart.not_a_cart');
        }

        if ((string) $row['stage'] !== 'cart') {
            // Un ordine già fatto non si modifica dal carrello: le sue righe
            // hanno merce prenotata dietro.
            throw UserError::make('cart.not_a_cart');
        }

        return $row;
    }

    /**
     * Segna il carrello come toccato adesso e lo restituisce.
     *
     * @return array<string, mixed>
     */
    private static function touch(int $cartId): array
    {
        Order::update(['last_activity_at' => date('Y-m-d H:i:s')], $cartId);
        $row = Order::findById($cartId);

        return is_array($row) ? $row : [];
    }

    /**
     * La riga, ma solo se è di questo carrello.
     *
     * @return array<string, mixed>
     */
    private static function item(int $cartId, int $itemId): array
    {
        foreach (self::items($cartId) as $item) {
            if ((int) $item['id'] === $itemId) {
                return $item;
            }
        }

        throw UserError::make('cart.item_not_found');
    }

    /** La quantità chiesta, tagliata a quello che c'è davvero. */
    private static function capped(int $productId, float $wanted): float
    {
        if ($productId <= 0) {
            return $wanted;
        }

        $product = Product::findById($productId);

        if (!is_array($product) || ($product['active'] ?? 'false') !== 'true') {
            return 0.0;
        }

        if (Stock::allowsBackorder($product)) {
            return $wanted;
        }

        return min($wanted, max(0.0, Levels::of($productId)['available']));
    }

    /** @return array<string, mixed> */
    private static function product(int $productId): array
    {
        $product = $productId > 0 ? Product::findById($productId) : null;

        if (!is_array($product) || ($product['active'] ?? 'false') !== 'true') {
            throw UserError::make('cart.product_unavailable');
        }

        return $product;
    }

    /**
     * Quello che c'è basta? Con la vendita scoperta accesa, sempre.
     *
     * @param array<string, mixed> $product
     */
    private static function assertAvailable(array $product, float $wanted): void
    {
        if (Stock::allowsBackorder($product)) {
            return;
        }

        $available = Levels::of((int) $product['id'])['available'];

        if ($wanted > $available) {
            throw UserError::make('cart.not_enough_stock', [
                'name' => (string) $product['name'],
                'available' => rtrim(rtrim(number_format(max(0.0, $available), 3, ',', ''), '0'), ','),
            ]);
        }
    }

    /**
     * La riga uguale a quella che sta entrando, se c'è: stesso articolo e
     * stessi valori di personalizzazione, qualunque ne sia l'ordine.
     *
     * @return array<string, mixed>|null
     */
    private static function itemLike(int $cartId, int $productId, string $signature): ?array
    {
        foreach (self::items($cartId) as $item) {
            if ((string) $item['type'] === 'product'
                && (int) $item['product_id'] === $productId
                && Customizations::signature(Customizations::decode($item['customization'] ?? '')) === $signature) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Le personalizzazioni di una riga che sta entrando, controllate e
     * prezzate sull'anagrafica.
     *
     * Con la funzionalità spenta i valori mandati si ignorano; ma un articolo
     * che ne pretende una non si può comprare così, e lo si dice invece di
     * vendere un articolo incompleto.
     *
     * @param array<string, mixed>     $product
     * @param array<int|string, mixed> $values
     * @return array{fields: list<array<string, mixed>>, surcharge: string}
     */
    private static function resolveCustomization(array $product, array $values): array
    {
        $modelId = (int) ($product['product_model_id'] ?? 0);

        if (Gestionale::feature('customizations')) {
            return Customizations::resolve($modelId, $values);
        }

        foreach (Customizations::forModel($modelId) as $definition) {
            if ($definition['required']) {
                throw UserError::make('customization.unavailable', ['name' => (string) $product['name']]);
            }
        }

        return ['fields' => [], 'surcharge' => '0.00'];
    }

    private static function nextPosition(int $cartId): int
    {
        return count(self::items($cartId)) + 1;
    }

    /** @return list<array<string, mixed>> */
    private static function items(int $cartId): array
    {
        return self::rows(OrderItem::find(['order_id' => $cartId, 'deleted' => 'false']));
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $result): array
    {
        if (!is_array($result) || $result === []) {
            return [];
        }

        return isset($result['id']) ? [$result] : array_values(array_filter($result, 'is_array'));
    }

    private static function number(float $value): string
    {
        return number_format($value, 3, '.', '');
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
