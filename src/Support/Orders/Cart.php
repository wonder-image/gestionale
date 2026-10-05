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
use Wonder\Plugin\Gestionale\Support\Catalog\Bundles;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\Customizations;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductPhotos;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Pricing\LinePrice;
use Wonder\Plugin\Gestionale\Support\Pricing\OrderTotals;
use Wonder\Plugin\Gestionale\Support\Promotions\Campaigns;
use Wonder\Plugin\Gestionale\Support\Promotions\Coupons;
use Wonder\Plugin\Gestionale\Support\Shipping\Shipping;
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
     * Per un multiprodotto `choices` sono gli id delle opzioni scelte: la madre
     * porta il prezzo e le figlie, a prezzo zero, i componenti da scaricare.
     *
     * @param array{product_id: int, quantity?: float, customization?: array<int|string, mixed>, choices?: array<int|string, mixed>} $line
     * @return array{order: array<string, mixed>, items: list<array<string, mixed>>, removed: list<string>, coupon_dropped: string, shipping_dropped: string, shipping_saved: string}
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
            $bundle = null;

            if (Bundles::isBundle($productId)) {
                if (!Gestionale::feature('bundles')) {
                    throw UserError::make('bundle.feature_off');
                }

                $bundle = Bundles::resolve($productId, (array) ($line['choices'] ?? []));
            }

            $existing = self::itemLike(
                $cartId,
                $productId,
                Customizations::signature($resolved['fields']),
                self::bundleKey($bundle['children'] ?? [])
            );
            $wanted = round($quantity + (float) ($existing['quantity'] ?? 0), 3);

            // Il multiprodotto non ha giacenza sua: contano i pezzi dei componenti.
            if ($bundle !== null) {
                self::assertPacks($bundle['children'], $wanted);
            } else {
                self::assertAvailable($product, $wanted);
            }

            if (is_array($existing)) {
                OrderItem::update(['quantity' => self::number($wanted)], (int) $existing['id']);
            } else {
                $model = ProductModel::findById((int) $product['product_model_id']);
                $created = OrderItem::create([
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

                // Le figlie nascono con la madre: il ricalcolo le rifà dalle
                // scelte che trova scritte, e senza figlie non ne troverebbe.
                if ($bundle !== null) {
                    $mother = OrderItem::findById((int) ($created->insert_id ?? 0));
                    self::writeChildren($cartId, is_array($mother) ? $mother : [], $bundle['children'], $wanted);
                }
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
     * Lo stesso vale per un coupon che non vale più: esce dal carrello e il
     * motivo (una chiave di `gestionale.errors.coupon`) sta in `coupon_dropped`,
     * vuoto se non è successo niente.
     *
     * Con la funzionalità `shipping` accesa la riga di spedizione si rifà da
     * capo a ogni ricalcolo dal listino del metodo scelto; se il metodo non
     * copre più la destinazione la riga esce e la frase sta in
     * `shipping_dropped`. `shipping_saved` è quanto ha tolto un coupon di
     * spedizione gratuita.
     *
     * @return array{order: array<string, mixed>, items: list<array<string, mixed>>, removed: list<string>, coupon_dropped: string, shipping_dropped: string, shipping_saved: string}
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
            $now = date('Y-m-d H:i:s');
            $channel = (string) ($cart['channel'] ?? 'online');
            $removed = [];
            $computed = [];
            $rewritten = [];
            $packs = [];
            $all = self::items($cartId);
            $mothers = [];

            foreach ($all as $row) {
                if ((int) ($row['parent_item_id'] ?? 0) === 0) {
                    $mothers[(int) $row['id']] = true;
                }
            }

            $shipping = Gestionale::feature('shipping');

            foreach ($all as $item) {
                // La spedizione calcolata si rifà dopo, quando i prodotti sono
                // prezzati: quella a mano si prezza come ogni altra riga.
                if ($shipping && (string) $item['type'] === 'shipping' && (string) $item['price_source'] !== 'manual') {
                    continue;
                }

                // Le figlie non si prezzano né pesano: le riscrive la loro madre.
                if ((int) ($item['parent_item_id'] ?? 0) > 0) {
                    if (!isset($mothers[(int) $item['parent_item_id']])) {
                        OrderItem::delete((int) $item['id']);
                    }

                    continue;
                }

                $productId = (int) ($item['product_id'] ?? 0);
                $product = $productId > 0 ? Product::findById($productId) : null;

                if ((string) $item['type'] === 'product'
                    && (!is_array($product) || ($product['active'] ?? 'false') !== 'true')) {
                    self::dropWithChildren($cartId, $item);
                    $removed[] = (string) $item['name'];

                    continue;
                }

                // Una confezione già nel carrello si rifà dall'anagrafica: se
                // non è più vendibile così esce intera, madre e figlie. Con la
                // funzionalità spenta resta com'è, come le personalizzate.
                $bundle = null;

                if ((string) $item['type'] === 'product' && Bundles::isBundle($productId)
                    && Gestionale::feature('bundles')) {
                    try {
                        $bundle = Bundles::resolve($productId, self::optionIds($all, (int) $item['id']));
                    } catch (UserError) {
                        self::dropWithChildren($cartId, $item);
                        $removed[] = (string) $item['name'];

                        continue;
                    }
                }

                $fieldsSurcharge = null;
                $written = $item['customization_surcharge'] ?? 0;

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
                        self::dropWithChildren($cartId, $item);
                        $removed[] = (string) $item['name'];

                        continue;
                    }

                    $fieldsSurcharge = (float) $resolved['surcharge'];
                    $item['customization_surcharge'] = $resolved['surcharge'];

                    // Una confezione con la funzionalità spenta non si rifà dalle
                    // opzioni, ma il loro sovrapprezzo è già nel prezzo scritto:
                    // si tiene la parte che non viene dalle personalizzazioni.
                    if ((string) $item['type'] === 'product' && Bundles::isBundle($productId) && !Gestionale::feature('bundles')) {
                        $kept = max(0.0, (float) $written - array_sum(array_column(Customizations::decode($item['customization'] ?? ''), 'surcharge')));
                        $item['customization_surcharge'] = self::money($fieldsSurcharge + $kept);
                    }

                    // Le etichette cambiate in anagrafica si copiano finché la
                    // riga sta nel carrello; dopo l'ordine non si toccano più.
                    $rewritten[(int) $item['id']] = [
                        'customization' => Customizations::encode($resolved['fields']),
                        'customization_surcharge' => $item['customization_surcharge'],
                    ];
                }

                if ($bundle !== null) {
                    $fieldsSurcharge ??= array_sum(array_column(Customizations::decode($item['customization'] ?? ''), 'surcharge'));
                    $surcharge = self::money((float) $fieldsSurcharge + (float) $bundle['surcharge']);
                    $item['customization_surcharge'] = $surcharge;
                    $rewritten[(int) $item['id']]['customization_surcharge'] = $surcharge;
                    $packs[] = ['item' => $item, 'children' => $bundle['children']];
                }

                // La campagna dà il prezzo solo alle righe di prodotto a prezzo
                // automatico: il prezzo a mano non si tocca, le figlie non si prezzano.
                $campaign = $productId > 0 && (string) $item['type'] === 'product'
                    && (string) $item['price_source'] !== 'manual'
                    ? Campaigns::forProduct($productId, $now, $channel)
                    : null;

                $price = LinePrice::of([
                    'quantity' => $item['quantity'] ?? 0,
                    'price' => is_array($product) ? ($product['price'] ?? 0) : ($item['list_price'] ?? 0),
                    'sale_price' => is_array($product) ? ($product['sale_price'] ?? 0) : 0,
                    'manual_unit_price' => (string) $item['price_source'] === 'manual'
                        ? ($item['unit_price'] ?? '')
                        : '',
                    'campaign_price' => $campaign !== null ? number_format($campaign['price'], 2, '.', '') : '',
                    'discount_type' => $item['discount_type'] ?? 'none',
                    'discount_value' => $item['discount_value'] ?? 0,
                    'customization_surcharge' => $item['customization_surcharge'] ?? 0,
                ]);

                // Il prezzo di campagna vale solo se ha vinto davvero: con uno sconto
                // di riga a mano la riga è «manual» e la campagna non c'entra.
                $campaignId = $campaign !== null && $price['price_source'] === 'campaign' ? $campaign['campaign_id'] : 0;

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
                    'product_id' => $productId,
                    'weight' => is_array($product) ? (float) ($product['weight'] ?? 0) : 0.0,
                    'tax_id' => $taxId,
                    'tax_rate' => is_array($tax) ? (float) ($tax['rate'] ?? 0) : 0.0,
                    'tax_nature' => is_array($tax) ? (string) ($tax['nature'] ?? '') : '',
                    'discount_campaign_id' => $campaignId,
                ] + $price : [];
            }

            $shippingDropped = $shipping ? self::writeShipping($cart, $all, $computed, $fallback) : '';

            // Il coupon si rivaluta a ogni ricalcolo: se non vale più esce dal
            // carrello e il motivo arriva a chi chiama. Lo sconto scritto a mano
            // sulla testata non si somma mai: tra i due vince il manuale.
            $coupon = Coupons::context($cartId, $computed, $now);
            $dropped = (string) ($coupon['dropped'] ?? '');
            $context = [
                'prices_include_tax' => (string) ($cart['prices_include_tax'] ?? 'true') === 'true',
                'discount_type' => (string) ($cart['manual_discount_type'] ?? 'none'),
                'discount_value' => (float) ($cart['manual_discount_value'] ?? 0),
            ];

            if ($coupon !== [] && $dropped === '') {
                $context['discount_type'] = $coupon['discount_type'];
                $context['discount_value'] = $coupon['discount_value'];
                $context['free_shipping'] = $coupon['free_shipping'];

                foreach ($computed as $index => $line) {
                    $computed[$index]['discountable'] = (bool) ($coupon['discountable'][(int) $line['id']] ?? false);
                }
            }

            $totals = OrderTotals::of($computed, $context);

            foreach ($totals['lines'] as $line) {
                OrderItem::update([
                    'list_price' => $line['list_price'],
                    'unit_price' => $line['unit_price'],
                    'price_source' => $line['price_source'],
                    'discount_campaign_id' => (int) ($line['discount_campaign_id'] ?? 0),
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

            foreach ($packs as $pack) {
                self::writeChildren($cartId, $pack['item'], $pack['children'], (float) $pack['item']['quantity']);
            }

            if ($packs !== []) {
                self::renumber($cartId);
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

            return self::contents($cartId) + [
                'removed' => $removed,
                'coupon_dropped' => $dropped,
                'shipping_dropped' => $shippingDropped,
                'shipping_saved' => $totals['shipping_saved'],
            ];
        });
    }

    /**
     * Scrive la riga di spedizione calcolata e la mette tra le righe da
     * totalizzare; ridà la frase di `shipping_dropped`.
     *
     * Una sola riga, `base`, in fondo ai prodotti: se esiste si aggiorna, se il
     * metodo non c'è più o non copre la destinazione, o si ritira, esce. Una
     * riga `manual` messa dall'ufficio non si tocca e toglie il posto a quella
     * calcolata. Il totale per la soglia gratuita sono i prodotti a prezzo di
     * riga: lo sconto del coupon si ripartisce dopo, in `OrderTotals`, e non
     * ne fa parte.
     *
     * @param array<string, mixed> $cart
     * @param list<array<string, mixed>> $all
     * @param list<array<string, mixed>> $computed
     */
    private static function writeShipping(array $cart, array $all, array &$computed, int $fallback): string
    {
        $stored = array_values(array_filter($all, static fn (array $row): bool => (string) $row['type'] === 'shipping'));
        $calculated = array_values(array_filter($stored, static fn (array $row): bool => (string) $row['price_source'] !== 'manual'));
        $manual = count($stored) > count($calculated);
        $resolved = $manual ? ['line' => null, 'dropped' => ''] : Shipping::resolveLine($cart, $computed);
        $line = $resolved['line'];

        if ($line === null) {
            foreach ($calculated as $row) {
                OrderItem::delete((int) $row['id']);
            }

            return $resolved['dropped'];
        }

        $keep = array_shift($calculated);

        foreach ($calculated as $extra) {
            OrderItem::delete((int) $extra['id']);
        }

        $id = (int) ($keep['id'] ?? 0);

        if ($id === 0) {
            $id = (int) (OrderItem::create([
                'order_id' => (int) $cart['id'],
                'type' => 'shipping',
                'position' => 800,
                'name' => $line['name'],
                'quantity' => '1.000',
                'price_source' => 'base',
                'tax_category_id' => 0,
            ])->insert_id ?? 0);
        } elseif ((string) $keep['name'] !== $line['name']) {
            OrderItem::update(['name' => $line['name']], $id);
        }

        $taxId = $line['tax_id'] > 0 ? $line['tax_id'] : $fallback;
        $tax = $taxId > 0 ? Tax::findById($taxId) : null;
        $computed[] = [
            'id' => $id,
            'type' => 'shipping',
            'product_id' => 0,
            'weight' => 0.0,
            'tax_id' => $taxId,
            'tax_rate' => is_array($tax) ? (float) ($tax['rate'] ?? 0) : 0.0,
            'tax_nature' => is_array($tax) ? (string) ($tax['nature'] ?? '') : '',
            'discount_campaign_id' => 0,
        ] + LinePrice::of(['quantity' => 1, 'price' => $line['amount']]);

        return '';
    }

    /**
     * Cambia la quantità di una riga. Zero vuol dire toglierla.
     *
     * @return array{order: array<string, mixed>, items: list<array<string, mixed>>, removed: list<string>, coupon_dropped: string, shipping_dropped: string, shipping_saved: string}
     */
    public static function setQuantity(int $cartId, int $itemId, float $quantity): array
    {
        return Transaction::run(static function () use ($cartId, $itemId, $quantity): array {
            self::cart($cartId);
            $item = self::item($cartId, $itemId);
            $quantity = round($quantity, 3);

            self::assertNotChild($item);

            if ($quantity <= 0) {
                self::dropWithChildren($cartId, $item);

                return self::recalculate($cartId);
            }

            $productId = (int) ($item['product_id'] ?? 0);

            if ($productId > 0 && Bundles::isBundle($productId)) {
                self::assertBundleQuantity($cartId, $item, $quantity);
            } elseif ($productId > 0) {
                self::assertAvailable(self::product($productId), $quantity);
            }

            OrderItem::update(['quantity' => self::number($quantity)], $itemId);

            return self::recalculate($cartId);
        });
    }

    /**
     * Toglie una riga dal carrello.
     *
     * @return array{order: array<string, mixed>, items: list<array<string, mixed>>, removed: list<string>, coupon_dropped: string, shipping_dropped: string, shipping_saved: string}
     */
    public static function remove(int $cartId, int $itemId): array
    {
        return Transaction::run(static function () use ($cartId, $itemId): array {
            self::cart($cartId);
            $item = self::item($cartId, $itemId);
            self::assertNotChild($item);
            self::dropWithChildren($cartId, $item);

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
     * @return array{order: array<string, mixed>, items: list<array<string, mixed>>, removed: list<string>, coupon_dropped: string, shipping_dropped: string, shipping_saved: string}
     */
    public static function merge(int $guestCartId, int $targetCartId): array
    {
        return Transaction::run(static function () use ($guestCartId, $targetCartId): array {
            if ($guestCartId === $targetCartId) {
                return self::recalculate($targetCartId);
            }

            self::cart($targetCartId);
            self::cart($guestCartId);

            $guest = self::items($guestCartId);
            $moved = false;

            foreach ($guest as $item) {
                if ((int) ($item['parent_item_id'] ?? 0) > 0) {
                    continue;
                }

                $productId = (int) ($item['product_id'] ?? 0);
                $signature = Customizations::signature(Customizations::decode($item['customization'] ?? ''));
                $kids = self::childrenOf($guest, (int) $item['id']);
                $existing = self::itemLike($targetCartId, $productId, $signature, self::bundleKey($kids));
                $wanted = round((float) $item['quantity'] + (float) ($existing['quantity'] ?? 0), 3);
                $wanted = $kids === []
                    ? self::capped($productId, $wanted)
                    : self::cappedPacks(self::perPack($item, $kids), $wanted);

                if ($wanted <= 0) {
                    continue;
                }

                if (is_array($existing)) {
                    OrderItem::update(['quantity' => self::number($wanted)], (int) $existing['id']);

                    // A funzionalità spenta `recalculate` non rifà le figlie: se la
                    // madre cresce senza di loro il magazzino scala meno del venduto.
                    if ($kids !== [] && !Gestionale::feature('bundles')) {
                        self::scaleChildren($targetCartId, $existing, $wanted);
                    }

                    continue;
                }

                OrderItem::update([
                    'order_id' => $targetCartId,
                    'position' => self::nextPosition($targetCartId),
                    'quantity' => self::number($wanted),
                ], (int) $item['id']);

                // La confezione si sposta con le sue figlie.
                foreach ($kids as $kid) {
                    OrderItem::update(['order_id' => $targetCartId], (int) $kid['id']);
                    $moved = true;
                }
            }

            if ($moved) {
                self::renumber($targetCartId);
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
        $all = self::items($cartId);
        $decode = static function (array $item): array {
            $item['customization'] = Customizations::decode($item['customization'] ?? '');

            return $item;
        };
        $top = array_values(array_filter($all, static fn (array $item): bool => (int) ($item['parent_item_id'] ?? 0) === 0));
        usort($top, static fn (array $a, array $b): int => [(int) $a['position'], (int) $a['id']] <=> [(int) $b['position'], (int) $b['id']]);

        return [
            'order' => is_array($order) ? $order : [],
            'items' => array_map(static function (array $item) use ($all, $decode): array {
                $item = $decode($item);
                $item['children'] = array_map($decode, self::childrenOf($all, (int) $item['id']));

                return $item;
            }, $top),
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
            throw self::stockError((string) $product['name'], $available);
        }
    }

    private static function stockError(string $name, float $available): UserError
    {
        return UserError::make('cart.not_enough_stock', [
            'name' => $name,
            'available' => rtrim(rtrim(number_format(max(0.0, $available), 3, ',', ''), '0'), ','),
        ]);
    }

    /**
     * Le confezioni chieste stanno nei pezzi dei componenti? Si guarda ogni
     * prodotto, e il primo che manca dà il nome nell'errore.
     *
     * @param list<array{product_id: int, quantity: float, bundle_option_id: int}> $children per una confezione
     */
    private static function assertPacks(array $children, float $packs): void
    {
        $components = [];
        $chosen = [];

        foreach ($children as $child) {
            if ((int) $child['bundle_option_id'] > 0) {
                $chosen[] = ['product_id' => (int) $child['product_id']];
            } else {
                $components[] = ['product_id' => (int) $child['product_id'], 'quantity' => (float) $child['quantity']];
            }
        }

        $pieces = Bundles::pieces($components, $chosen, $packs);
        $levels = Levels::forProducts(array_keys($pieces));
        $available = [];
        $backorder = [];
        $products = [];

        foreach (array_keys($pieces) as $id) {
            $product = Product::findById($id);
            $products[$id] = is_array($product) ? $product : [];
            $backorder[$id] = is_array($product) && Stock::allowsBackorder($product);
            $available[$id] = (float) ($levels[$id]['available'] ?? 0.0);
        }

        $short = Bundles::shortfall($pieces, $available, $backorder);

        if ($short !== null) {
            $product = $products[$short['product_id']];

            throw self::stockError(
                $product === [] ? '' : ProductNames::full($product, ProductNames::models([$product])),
                $short['available']
            );
        }
    }

    /** Una riga figlia la gestisce la sua madre: da sola non si cambia né si toglie. */
    private static function assertNotChild(array $item): void
    {
        if ((int) ($item['parent_item_id'] ?? 0) > 0) {
            throw UserError::make('cart.child_line');
        }
    }

    /**
     * Cambiare le confezioni: con la funzionalità accesa si controlla la
     * giacenza dei componenti sulla composizione di adesso; spenta, le figlie
     * si scalano in proporzione perché il magazzino non resti indietro.
     *
     * @param array<string, mixed> $item
     */
    private static function assertBundleQuantity(int $cartId, array $item, float $quantity): void
    {
        if (Gestionale::feature('bundles')) {
            $bundle = Bundles::resolve((int) $item['product_id'], self::optionIds(self::items($cartId), (int) $item['id']));
            self::assertPacks($bundle['children'], $quantity);

            return;
        }

        self::scaleChildren($cartId, $item, $quantity);
    }

    /**
     * Le figlie di una madre seguono la sua quantità, in proporzione.
     *
     * @param array<string, mixed> $item la madre, con la quantità che aveva
     */
    private static function scaleChildren(int $cartId, array $item, float $quantity): void
    {
        $ratio = $quantity / max((float) $item['quantity'], 0.001);

        foreach (self::childrenOf(self::items($cartId), (int) $item['id']) as $kid) {
            OrderItem::update(['quantity' => self::number(round((float) $kid['quantity'] * $ratio, 3))], (int) $kid['id']);
        }
    }

    /**
     * Toglie una riga e, se è una madre, le sue figlie.
     *
     * @param array<string, mixed> $item
     */
    private static function dropWithChildren(int $cartId, array $item): void
    {
        foreach (self::childrenOf(self::items($cartId), (int) $item['id']) as $kid) {
            OrderItem::delete((int) $kid['id']);
        }

        OrderItem::delete((int) $item['id']);
    }

    /**
     * @param list<array<string, mixed>> $all
     * @return list<array<string, mixed>>
     */
    private static function childrenOf(array $all, int $motherId): array
    {
        $kids = array_values(array_filter(
            $all,
            static fn (array $row): bool => (int) ($row['parent_item_id'] ?? 0) === $motherId && $motherId > 0
        ));
        usort($kids, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);

        return $kids;
    }

    /**
     * Le opzioni scelte di una madre, lette dalle sue figlie.
     *
     * @param list<array<string, mixed>> $all
     * @return list<int>
     */
    private static function optionIds(array $all, int $motherId): array
    {
        return array_values(array_filter(array_map(
            static fn (array $kid): int => (int) ($kid['bundle_option_id'] ?? 0),
            self::childrenOf($all, $motherId)
        )));
    }

    /**
     * L'impronta delle scelte: due confezioni con le stesse opzioni, in
     * qualunque ordine, sono la stessa riga.
     *
     * @param list<array<string, mixed>> $children figlie, di una confezione o di una riga
     */
    private static function bundleKey(array $children): string
    {
        $ids = array_values(array_filter(array_map(
            static fn (array $child): int => (int) ($child['bundle_option_id'] ?? 0),
            $children
        )));
        sort($ids);

        return implode(',', $ids);
    }

    /**
     * I pezzi per una confezione, dalle figlie scritte nel carrello.
     *
     * @param array<string, mixed>       $mother
     * @param list<array<string, mixed>> $kids
     * @return list<array{product_id: int, quantity: float, bundle_option_id: int}>
     */
    private static function perPack(array $mother, array $kids): array
    {
        $packs = max((float) $mother['quantity'], 0.001);

        return array_map(static fn (array $kid): array => [
            'product_id' => (int) $kid['product_id'],
            'quantity' => (float) $kid['quantity'] / $packs,
            'bundle_option_id' => (int) $kid['bundle_option_id'],
        ], $kids);
    }

    /**
     * Le confezioni chieste, tagliate a quelle intere che i componenti
     * permettono. Ogni prodotto si guarda per quanto serve in tutto, anche
     * se sta tra i fissi e tra le opzioni.
     *
     * @param list<array{product_id: int, quantity: float, bundle_option_id: int}> $perPack
     */
    private static function cappedPacks(array $perPack, float $wanted): float
    {
        $need = [];

        foreach ($perPack as $child) {
            $id = (int) $child['product_id'];
            $need[$id] = ($need[$id] ?? 0.0) + (float) $child['quantity'];
        }

        foreach ($need as $id => $pieces) {
            if ($pieces > 0) {
                $wanted = min($wanted, floor(round(self::capped($id, $wanted * $pieces) / $pieces, 6)));
            }
        }

        return max(0.0, $wanted);
    }

    /**
     * Scrive le figlie di una madre: quelle che ci sono si aggiornano, le
     * mancanti si creano, quelle che non servono più si tolgono. Il prezzo è
     * sempre zero: il cliente paga la madre, le figlie muovono solo la merce.
     *
     * @param array<string, mixed>                                                  $mother
     * @param list<array{product_id: int, quantity: float, bundle_option_id: int}> $children per una confezione
     */
    private static function writeChildren(int $cartId, array $mother, array $children, float $packs): void
    {
        $current = [];

        foreach (self::childrenOf(self::items($cartId), (int) $mother['id']) as $row) {
            $current[(int) $row['product_id'].'/'.(int) $row['bundle_option_id']] = $row;
        }

        $zero = [
            'unit_price' => '0.00',
            'list_price' => '0.00',
            'line_total' => '0.00',
            'tax_id' => 0,
            'tax_rate' => '0.00',
            'tax_nature' => '',
        ];

        foreach ($children as $child) {
            $key = (int) $child['product_id'].'/'.(int) $child['bundle_option_id'];
            $quantity = self::number(round((float) $child['quantity'] * $packs, 3));

            if (isset($current[$key])) {
                OrderItem::update(['quantity' => $quantity] + $zero, (int) $current[$key]['id']);
                unset($current[$key]);

                continue;
            }

            $product = Product::findById((int) $child['product_id']);

            if (!is_array($product)) {
                continue;
            }

            $model = ProductModel::findById((int) $product['product_model_id']);
            OrderItem::create([
                'order_id' => $cartId,
                'type' => 'product',
                'product_id' => (int) $child['product_id'],
                'parent_item_id' => (int) $mother['id'],
                'bundle_option_id' => (int) $child['bundle_option_id'],
                'position' => self::nextPosition($cartId),
                'sku' => (string) $product['sku'],
                'name' => ProductNames::full($product, ProductNames::models([$product])),
                'image' => ProductPhotos::forProduct((int) $child['product_id']),
                'unit' => (string) (is_array($model) ? ($model['unit'] ?? 'pz') : 'pz'),
                'quantity' => $quantity,
                'tax_category_id' => (int) ($mother['tax_category_id'] ?? 0),
            ] + $zero);
        }

        foreach ($current as $stale) {
            OrderItem::delete((int) $stale['id']);
        }
    }

    /** Rimette le posizioni in fila: ogni madre seguita dalle sue figlie. */
    private static function renumber(int $cartId): void
    {
        $all = self::items($cartId);
        $top = array_values(array_filter($all, static fn (array $row): bool => (int) ($row['parent_item_id'] ?? 0) === 0));
        usort($top, static fn (array $a, array $b): int => [(int) $a['position'], (int) $a['id']] <=> [(int) $b['position'], (int) $b['id']]);
        $position = 1;

        foreach ($top as $row) {
            foreach ([$row, ...self::childrenOf($all, (int) $row['id'])] as $line) {
                if ((int) $line['position'] !== $position) {
                    OrderItem::update(['position' => $position], (int) $line['id']);
                }

                $position++;
            }
        }
    }

    /**
     * La riga uguale a quella che sta entrando, se c'è: stesso articolo e
     * stessi valori di personalizzazione e stesse opzioni del multiprodotto,
     * qualunque ne sia l'ordine. Le figlie non contano: sono di una madre.
     *
     * @return array<string, mixed>|null
     */
    private static function itemLike(int $cartId, int $productId, string $signature, string $bundleKey = ''): ?array
    {
        $all = self::items($cartId);

        foreach ($all as $item) {
            if ((string) $item['type'] === 'product'
                && (int) ($item['parent_item_id'] ?? 0) === 0
                && (int) $item['product_id'] === $productId
                && Customizations::signature(Customizations::decode($item['customization'] ?? '')) === $signature
                && self::bundleKey(self::childrenOf($all, (int) $item['id'])) === $bundleKey) {
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
