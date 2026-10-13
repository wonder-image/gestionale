<?php

namespace Wonder\Plugin\Gestionale\Support\Sales;

use RuntimeException;
use Throwable;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Contacts\ContactAddress;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Sales\OrderStatusLog;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Support\Catalog\Bundles;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\Customizations;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductPhotos;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Documents\DocumentSequences;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Numbers;
use Wonder\Plugin\Gestionale\Support\Orders\Cart;
use Wonder\Plugin\Gestionale\Support\Orders\Lifecycle;
use Wonder\Plugin\Gestionale\Support\Orders\OrderLines;
use Wonder\Plugin\Gestionale\Support\Orders\OrderNotifier;
use Wonder\Plugin\Gestionale\Support\Payments\Installments;
use Wonder\Plugin\Gestionale\Support\Pricing\LinePrice;
use Wonder\Plugin\Gestionale\Support\Pricing\OrderTotals;
use Wonder\Plugin\Gestionale\Support\Shipping\Shipments;
use Wonder\Plugin\Gestionale\Support\Status\StatusLogger;
use Wonder\Plugin\Gestionale\Support\Stock\Allocation;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Plugin\Gestionale\Support\Stock\ProductNames;
use Wonder\Plugin\Gestionale\Support\Stock\Stock;
use Wonder\Sql\Transaction;

/**
 * L'ordine da ufficio (G9 §2): **l'unica porta** che scrive una bozza
 * d'ordine, la conferma e ne annulla le righe dopo.
 *
 * La bozza è un ordine vero (`stage` `order`, `status` `draft`, `channel`
 * `office`) senza numero e senza merce dietro: niente prenotazione, i prezzi si
 * rifanno a ogni salvataggio con il motore del carrello. La conferma scarica
 * direttamente (`Lifecycle::confirm()` senza pagamento: le rate ne prendono il
 * posto) e dà il numero. Dopo, le righe non si modificano: si annullano.
 */
final class OfficeOrders
{
    /** Quello che la testata scrive così com'è (oltre agli indirizzi). */
    private const HEADER = [
        'customer_id', 'email', 'phone', 'location_id', 'shipping_method_id',
        'payment_method_id', 'payment_term_id', 'customer_note', 'internal_note', 'document_note',
    ];

    /**
     * Salva la bozza: testata e righe dall'editor, poi il ricalcolo. Con
     * `$orderId` 0 nasce una bozza nuova.
     *
     * Le righe si riscrivono tutte: la bozza non ha merce né storia dietro,
     * e riscriverle è più semplice che confrontarle.
     *
     * @param array<string, mixed> $data testata (vedi `HEADER`, `billing_*`, `shipping_*`, `fulfillment_type`, sconto, `shipping_amount`) e `lines`
     * @return int l'id della bozza
     */
    public static function save(int $orderId, array $data, int $userId = 0): int
    {
        $header = OfficeOrderRules::header($data);
        $lines = OfficeOrderRules::lines(is_array($data['lines'] ?? null) ? $data['lines'] : []);
        $shipping = self::shippingAmount($data['shipping_amount'] ?? null);

        return Transaction::run(static function () use ($orderId, $data, $userId, $header, $lines, $shipping): int {
            $order = $orderId > 0 ? self::draft($orderId) : null;
            $values = self::headerValues($data, $header);

            if ($order === null) {
                $settings = self::settings();
                $created = Order::create($values + [
                    'code' => Code::make(Order::class, Codes::ORDER),
                    'stage' => 'order',
                    'status' => 'draft',
                    'channel' => 'office',
                    'payment_status' => 'unpaid',
                    'currency' => 'EUR',
                    'prices_include_tax' => (string) ($settings['catalog_prices_include_tax'] ?? 'true') === 'false' ? 'false' : 'true',
                ]);
                $orderId = (int) ($created->insert_id ?? 0);

                if ($orderId <= 0) {
                    throw new RuntimeException('Bozza d\'ordine non scritta.');
                }

                // Chi l'ha fatta resta nello storico.
                StatusLogger::record(OrderStatusLog::class, $orderId, 'status', '', 'draft', 'user', $userId ?: null);
            } else {
                Order::update($values, $orderId);
            }

            self::writeLines($orderId, $lines);
            self::writeShipping($orderId, $shipping);
            Cart::recalculate($orderId);

            return $orderId;
        });
    }

    /**
     * I dati che la scheda del cliente propone alla bozza: email, telefono,
     * fatturazione, la consegna predefinita (o la fatturazione), metodo e
     * condizione di pagamento.
     *
     * @return array<string, mixed>
     */
    public static function customerDefaults(int $contactId): array
    {
        $contact = $contactId > 0 ? Contact::findById($contactId) : null;

        if (!is_array($contact) || $contact === [] || (string) ($contact['deleted'] ?? 'false') === 'true') {
            return [];
        }

        $out = [
            'customer_id' => $contactId,
            'email' => (string) ($contact['email'] ?? ''),
            'phone' => trim((string) ($contact['phone_prefix'] ?? '').' '.(string) ($contact['phone'] ?? '')),
            'payment_method_id' => (int) ($contact['payment_method_id'] ?? 0),
            'payment_term_id' => (int) ($contact['payment_term_id'] ?? 0),
        ];

        foreach (self::billingKeys() as $key) {
            $out['billing_'.$key] = (string) ($contact[$key] ?? '');
        }

        $address = self::defaultAddress($contactId);

        foreach (self::shippingKeys() as $key) {
            $source = $address !== [] ? $address : $contact;
            $out['shipping_'.$key] = (string) ($source[$key] ?? '');
        }

        return $out;
    }

    /** Una bozza si elimina; un ordine confermato no, si annulla. */
    public static function delete(int $orderId): void
    {
        Transaction::run(static function () use ($orderId): void {
            $order = Order::findForUpdate(['id' => $orderId], 1);

            if (!is_array($order) || $order === [] || (string) ($order['status'] ?? '') !== 'draft' || (string) ($order['stage'] ?? '') !== 'order') {
                throw UserError::make('office_order.cannot_delete');
            }

            sqlDelete(OrderItem::$table, 'order_id = '.$orderId);
            sqlDelete(OrderStatusLog::$table, 'order_id = '.$orderId);
            sqlDelete(Order::$table, 'id = '.$orderId);
        });
    }

    /**
     * Conferma la bozza: ricalcolo e controlli, la merce che esce (o il
     * rifiuto col nome della versione), il numero, le rate. Con `notify` parte
     * l'email di conferma al cliente.
     *
     * `installments_from` fa partire le rate da un altro giorno (dati di
     * prova, ordini ripresi da un altro gestionale); senza, da oggi.
     *
     * @param array{source?: string, user_id?: int, notify?: bool, installments_from?: \DateTimeImmutable} $options
     * @return array{order_id: int, order_number: string, installments: int}
     */
    public static function confirm(int $orderId, array $options = []): array
    {
        $result = Transaction::run(static function () use ($orderId, $options): array {
            self::draft($orderId);

            // Una versione spenta dopo il salvataggio il ricalcolo la toglie:
            // la conferma la rifiuta invece di vendere un ordine diverso.
            $removed = (array) (Cart::recalculate($orderId)['removed'] ?? []);

            if ($removed !== []) {
                throw UserError::make('office_order.product_unknown', ['name' => (string) $removed[0]]);
            }

            $order = (array) Order::findById($orderId);
            $items = self::items($orderId);

            OfficeOrderRules::assertConfirmable($order, $items);

            $locationId = (int) ($order['location_id'] ?? 0) > 0 ? (int) $order['location_id'] : Locations::mainId();
            self::assertStock($items, $locationId);

            $number = DocumentSequences::next('order');
            Order::update([
                'order_number' => $number,
                'ordered_at' => date('Y-m-d H:i:s'),
                'location_id' => $locationId,
            ], $orderId);

            Lifecycle::confirm($orderId, [
                'payment' => false,
                'notify' => false,
                'source' => (string) ($options['source'] ?? 'user'),
                'user_id' => (int) ($options['user_id'] ?? 0),
            ]);

            return [
                'order_id' => $orderId,
                'order_number' => $number,
                'installments' => count(Installments::create($orderId, $options['installments_from'] ?? null)),
            ];
        });

        if (($options['notify'] ?? false) === true) {
            // Fuori dalla transazione, come `Lifecycle`: la posta è lenta.
            OrderNotifier::send('confirmed', $orderId);
        }

        return $result;
    }

    /**
     * Annulla righe di un ordine da ufficio confermato: al massimo quanto non
     * è ancora partito. La merce rientra sullo stesso secchio, i totali si
     * rifanno con i prezzi scritti (un ordine confermato non si riprezza), le
     * rate si ricalcolano. Annullare tutto è annullare l'ordine.
     *
     * @param array<int, mixed> $wanted riga => quantità
     * @param array{source?: string, user_id?: int, reason?: string} $options
     * @return array{cancelled_order: bool, lines: int}
     */
    public static function cancelLines(int $orderId, array $wanted, array $options = []): array
    {
        return Transaction::run(static function () use ($orderId, $wanted, $options): array {
            $order = Order::findForUpdate(['id' => $orderId], 1);

            if (!is_array($order) || $order === []
                || (string) ($order['stage'] ?? '') !== 'order'
                || (string) ($order['channel'] ?? '') !== 'office'
                || !in_array((string) ($order['status'] ?? ''), Lifecycle::COMMITTED, true)) {
                throw UserError::make('office_order.not_cancellable');
            }

            $items = self::items($orderId);
            $remaining = self::cancellableQuantities($order, $items);
            $names = array_column($items, 'name', 'id');
            $cancel = OfficeOrderRules::cancellable($wanted, $remaining, array_map('strval', $names));
            $source = (string) ($options['source'] ?? 'user');
            $userId = (int) ($options['user_id'] ?? 0);

            // Tutto quello che resta si annulla: è l'ordine che si annulla.
            $left = 0.0;

            foreach ($items as $item) {
                if (in_array((string) $item['type'], ['product', 'custom'], true) && (int) ($item['parent_item_id'] ?? 0) === 0) {
                    $left += max(0.0, (float) $item['quantity'] - ($cancel[(int) $item['id']] ?? 0.0));
                }
            }

            if ($left <= 0.0005) {
                Lifecycle::cancel($orderId, [
                    'reason' => (string) ($options['reason'] ?? 'Righe annullate'),
                    'source' => $source,
                    'user_id' => $userId,
                    'notify' => false,
                    'merchant_notice' => false,
                ]);
                Installments::recalculate($orderId);

                return ['cancelled_order' => true, 'lines' => count($cancel)];
            }

            $byId = array_column($items, null, 'id');

            foreach ($cancel as $itemId => $quantity) {
                $item = $byId[$itemId];

                if ((string) $item['type'] === 'product') {
                    Allocation::restore([
                        'product_id' => (int) $item['product_id'],
                        'quantity' => $quantity,
                        'location_id' => (int) ($order['location_id'] ?? 0),
                        'order_id' => $orderId,
                        'order_item_id' => $itemId,
                        'source' => $source,
                        'user_id' => $userId,
                        'note' => 'Riga annullata',
                    ]);
                }

                OrderItem::update([
                    'quantity' => number_format(round((float) $item['quantity'] - $quantity, 3), 3, '.', ''),
                    'quantity_cancelled' => number_format(round((float) ($item['quantity_cancelled'] ?? 0) + $quantity, 3), 3, '.', ''),
                ], $itemId);

                StatusLogger::record(
                    OrderStatusLog::class,
                    $orderId,
                    'items',
                    mb_substr((string) $item['name'], 0, 100),
                    'annullati '.OfficeOrderRules::plain($quantity),
                    $source,
                    $userId ?: null,
                    (string) ($options['reason'] ?? '')
                );
            }

            self::retotal($orderId);
            Installments::recalculate($orderId);

            return ['cancelled_order' => false, 'lines' => count($cancel)];
        });
    }

    /**
     * Quanto si può annullare di ogni riga: prodotti non ancora assegnati a
     * una spedizione, righe libere per intero. I multiprodotti si annullano
     * solo con l'ordine intero.
     *
     * @param array<string, mixed> $order
     * @param list<array<string, mixed>> $items
     * @return array<int, float>
     */
    public static function cancellableQuantities(array $order, array $items): array
    {
        $parents = [];

        foreach ($items as $item) {
            if ((int) ($item['parent_item_id'] ?? 0) > 0) {
                $parents[(int) $item['parent_item_id']] = true;
            }
        }

        $shipping = Gestionale::feature('shipping') ? Shipments::remaining((int) $order['id']) : null;
        $done = (string) ($order['fulfillment_status'] ?? '') === 'fulfilled';
        $out = [];

        foreach ($items as $item) {
            $id = (int) $item['id'];

            if ((int) ($item['parent_item_id'] ?? 0) > 0 || isset($parents[$id])) {
                continue;
            }

            $quantity = round((float) ($item['quantity'] ?? 0), 3);

            if ((string) $item['type'] === 'custom') {
                $out[$id] = $quantity;
            } elseif ((string) $item['type'] === 'product') {
                $out[$id] = $done ? 0.0 : ($shipping === null ? $quantity : min($quantity, (float) ($shipping[$id] ?? 0.0)));
            }
        }

        return array_filter($out, static fn (float $q): bool => $q > 0);
    }

    /**
     * I totali di un ordine confermato rifatti con i prezzi scritti: la
     * quantità è cambiata, il prezzo unitario no.
     */
    private static function retotal(int $orderId): void
    {
        $order = (array) Order::findById($orderId);
        $computed = [];

        foreach (self::items($orderId) as $item) {
            if ((string) $item['type'] === 'text' || (int) ($item['parent_item_id'] ?? 0) > 0) {
                continue;
            }

            $computed[] = [
                'id' => (int) $item['id'],
                'type' => (string) $item['type'],
                'product_id' => (int) ($item['product_id'] ?? 0),
                'weight' => 0.0,
                'tax_id' => (int) ($item['tax_id'] ?? 0),
                'tax_rate' => (float) ($item['tax_rate'] ?? 0),
                'tax_nature' => (string) ($item['tax_nature'] ?? ''),
                'discount_campaign_id' => (int) ($item['discount_campaign_id'] ?? 0),
            ] + LinePrice::of([
                'quantity' => $item['quantity'] ?? 0,
                'price' => $item['unit_price'] ?? 0,
                'manual_unit_price' => (string) ($item['unit_price'] ?? '0'),
            ]);
        }

        $totals = OrderTotals::of($computed, [
            'prices_include_tax' => (string) ($order['prices_include_tax'] ?? 'true') === 'true',
            'discount_type' => (string) ($order['manual_discount_type'] ?? 'none'),
            'discount_value' => (float) ($order['manual_discount_value'] ?? 0),
        ]);

        foreach ($totals['lines'] as $line) {
            OrderItem::update([
                'order_discount_amount' => $line['order_discount_amount'],
                'line_total' => $line['line_total'],
            ], (int) $line['id']);
        }

        Order::update([
            'products_total' => $totals['products_total'],
            'discount_total' => $totals['discount_total'],
            'shipping_total' => $totals['shipping_total'],
            'fees_total' => $totals['fees_total'],
            'taxable_total' => $totals['taxable_total'],
            'tax_total' => $totals['tax_total'],
            'total' => $totals['total'],
        ], $orderId);
    }

    /**
     * La merce c'è? Per versione e sede, sommando le righe; un articolo che si
     * vende senza giacenza passa. Il rifiuto dice il nome (G9.7).
     *
     * @param list<array<string, mixed>> $items
     */
    private static function assertStock(array $items, int $locationId): void
    {
        $wanted = [];
        $names = [];

        foreach (OrderLines::goods($items) as $item) {
            $id = (int) $item['product_id'];
            $wanted[$id] = round(($wanted[$id] ?? 0.0) + (float) $item['quantity'], 3);
            $names[$id] ??= (string) $item['name'];
        }

        if ($wanted === []) {
            return;
        }

        $levels = Levels::forSale(array_keys($wanted), $locationId);

        foreach ($wanted as $productId => $quantity) {
            $available = (float) ($levels[$productId]['available'] ?? 0.0);

            if ($available + 0.0005 >= $quantity) {
                continue;
            }

            $product = Product::findById($productId);

            if (is_array($product) && Stock::allowsBackorder($product)) {
                continue;
            }

            throw UserError::make('office_order.out_of_stock', [
                'name' => $names[$productId],
                'available' => OfficeOrderRules::plain(max(0.0, $available)),
                'wanted' => OfficeOrderRules::plain($quantity),
            ]);
        }
    }

    /**
     * Le righe dell'editor al posto di quelle di prima, spedizione a parte.
     *
     * @param list<array<string, mixed>> $lines da `OfficeOrderRules::lines()`
     */
    private static function writeLines(int $orderId, array $lines): void
    {
        foreach (self::items($orderId) as $old) {
            if ((string) $old['type'] !== 'shipping') {
                OrderItem::delete((int) $old['id']);
            }
        }

        $position = 0;

        foreach ($lines as $line) {
            $position += 10;
            $price = $line['unit_price'] !== null ? number_format($line['unit_price'], 2, '.', '') : null;
            $row = [
                'order_id' => $orderId,
                'type' => $line['type'],
                'position' => $position,
                'quantity' => number_format($line['quantity'], 3, '.', ''),
                'manual_unit_price' => $price,
                'discount_type' => $line['discount_type'],
                'discount_value' => number_format($line['discount_value'], 2, '.', ''),
                'batch_id' => $line['batch_id'],
                'supplier_id' => $line['supplier_id'],
            ];

            if ($line['type'] === 'product') {
                $row += self::productCopy($line['product_id']);
            } elseif ($line['type'] === 'custom') {
                $row += [
                    'name' => $line['name'],
                    'list_price' => $price,
                    'unit_price' => $price,
                    'price_source' => 'manual',
                    'tax_category_id' => $line['tax_category_id'],
                    'unit' => 'pz',
                ];
            } else {
                $row = [
                    'order_id' => $orderId,
                    'type' => 'text',
                    'position' => $position,
                    'name' => $line['name'],
                    'quantity' => '0.000',
                ];
            }

            OrderItem::create($row);
        }
    }

    /**
     * La spedizione scritta a mano (§4.11): una riga `manual` che toglie il
     * posto a quella calcolata. Vuota, si torna al listino.
     */
    private static function writeShipping(int $orderId, ?float $amount): void
    {
        $manual = array_values(array_filter(
            self::items($orderId),
            static fn (array $i): bool => (string) $i['type'] === 'shipping' && (string) $i['price_source'] === 'manual'
        ));

        if ($amount === null) {
            foreach ($manual as $row) {
                OrderItem::delete((int) $row['id']);
            }

            return;
        }

        $price = number_format($amount, 2, '.', '');
        $values = [
            'list_price' => $price,
            'unit_price' => $price,
            'manual_unit_price' => $price,
            'price_source' => 'manual',
            'quantity' => '1.000',
        ];

        if ($manual === []) {
            // La calcolata se ne va: comanda quella a mano.
            foreach (self::items($orderId) as $row) {
                if ((string) $row['type'] === 'shipping') {
                    OrderItem::delete((int) $row['id']);
                }
            }

            OrderItem::create($values + [
                'order_id' => $orderId,
                'type' => 'shipping',
                'position' => 800,
                'name' => 'Spedizione',
                'tax_category_id' => 0,
            ]);

            return;
        }

        OrderItem::update($values, (int) $manual[0]['id']);
    }

    /**
     * La copia della versione sulla riga, come `Cart::add()`: nome, SKU, foto,
     * unità e categoria IVA. Una versione che da ufficio non si vende così si
     * rifiuta col suo nome.
     *
     * @return array<string, mixed>
     */
    private static function productCopy(int $productId): array
    {
        $product = Product::findById($productId);

        if (!is_array($product) || $product === [] || (string) ($product['deleted'] ?? 'false') === 'true' || ($product['active'] ?? 'false') !== 'true') {
            throw UserError::make('office_order.product_unknown', ['name' => is_array($product) ? (string) ($product['sku'] ?? '') : '#'.$productId]);
        }

        $model = ProductModel::findById((int) $product['product_model_id']);
        $name = ProductNames::full($product, ProductNames::models([$product]));

        foreach (Gestionale::feature('customizations') ? Customizations::forModel((int) $product['product_model_id']) : [] as $entry) {
            if (!empty($entry['required'])) {
                throw UserError::make('office_order.customization_required', ['name' => $name]);
            }
        }

        if (Bundles::isBundle($productId)) {
            if (!Gestionale::feature('bundles')) {
                throw UserError::make('bundle.feature_off');
            }

            try {
                Bundles::resolve($productId, []);
            } catch (UserError) {
                throw UserError::make('office_order.bundle_choice_required', ['name' => $name]);
            }
        }

        return [
            'product_id' => $productId,
            'sku' => (string) $product['sku'],
            'name' => $name,
            'image' => ProductPhotos::forProduct($productId),
            'unit' => (string) (is_array($model) ? ($model['unit'] ?? 'pz') : 'pz'),
            'tax_category_id' => (int) (is_array($model) ? ($model['tax_category_id'] ?? 0) : 0),
            'price_source' => 'base',
        ];
    }

    /**
     * La testata da scrivere: i campi ammessi, gli indirizzi, consegna e
     * sconto già puliti. Un cliente scelto deve esistere; i campi vuoti della
     * fatturazione e della consegna li riempie la sua scheda.
     *
     * @param array<string, mixed> $data
     * @param array{fulfillment_type: string, manual_discount_type: string, manual_discount_value: float} $header
     * @return array<string, mixed>
     */
    private static function headerValues(array $data, array $header): array
    {
        $values = [
            'fulfillment_type' => $header['fulfillment_type'],
            'manual_discount_type' => $header['manual_discount_type'],
            'manual_discount_value' => number_format($header['manual_discount_value'], 2, '.', ''),
        ];

        foreach (self::HEADER as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            $values[$key] = str_ends_with($key, '_id') ? max(0, (int) $data[$key]) : trim((string) $data[$key]);
        }

        $customerId = (int) ($values['customer_id'] ?? 0);
        $defaults = [];

        if ($customerId > 0) {
            $contact = Contact::findById($customerId);

            if (!is_array($contact) || $contact === [] || (string) ($contact['deleted'] ?? 'false') === 'true') {
                throw UserError::make('office_order.customer_unknown');
            }

            $defaults = self::customerDefaults($customerId);
            // `user_id` è l'account del cliente sul sito, come per gli ordini
            // online: così l'ordine compare nella sua area. L'operatore resta
            // nello storico, mai qui (lo vedrebbe come suo).
            $values['user_id'] = (int) ($contact['user_id'] ?? 0);
        }

        foreach (array_merge(
            array_map(static fn (string $k): string => 'billing_'.$k, self::billingKeys()),
            array_map(static fn (string $k): string => 'shipping_'.$k, self::shippingKeys())
        ) as $key) {
            $value = trim((string) ($data[$key] ?? ''));
            $values[$key] = $value !== '' ? $value : (string) ($defaults[$key] ?? '');
        }

        foreach (['email', 'phone'] as $key) {
            if (trim((string) ($values[$key] ?? '')) === '' && isset($defaults[$key])) {
                $values[$key] = $defaults[$key];
            }
        }

        // Coordinate vuote: NULL, non una stringa vuota in una colonna numerica.
        foreach (['shipping_lat', 'shipping_lng'] as $key) {
            if (($values[$key] ?? '') === '') {
                unset($values[$key]);
            }
        }

        if ((int) ($values['location_id'] ?? 0) > 0 && !in_array((int) $values['location_id'], array_column(Locations::alive(), 'id'), true)) {
            throw UserError::make('stock_document.location_unknown');
        }

        return $values;
    }

    private static function shippingAmount(mixed $value): ?float
    {
        $number = Numbers::fromForm($value);

        if ($number === null) {
            return null;
        }

        if (!is_numeric($number) || (float) $number < 0 || (float) $number > OfficeOrderRules::MAX_PRICE) {
            throw UserError::make('office_order.invalid_price', ['row' => 'spedizione']);
        }

        return round((float) $number, 2);
    }

    /** @return array<string, mixed> */
    private static function draft(int $orderId): array
    {
        $order = Order::findForUpdate(['id' => $orderId], 1);

        if (!is_array($order) || $order === [] || (string) ($order['deleted'] ?? 'false') === 'true') {
            throw UserError::make('office_order.not_draft');
        }

        if ((string) $order['status'] !== 'draft' || (string) $order['stage'] !== 'order') {
            throw UserError::make('office_order.not_draft');
        }

        return $order;
    }

    /** @return list<array<string, mixed>> */
    private static function items(int $orderId): array
    {
        $rows = OrderItem::find(['order_id' => $orderId, 'deleted' => 'false'], null, 'position', 'ASC');

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }

    /** @return array<string, mixed> */
    private static function defaultAddress(int $contactId): array
    {
        try {
            $rows = ContactAddress::find(['contact_id' => $contactId, 'deleted' => 'false'], null, 'position', 'ASC');
        } catch (Throwable) {
            return [];
        }

        $rows = is_array($rows) ? (isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'))) : [];

        foreach ($rows as $row) {
            if ((string) ($row['is_default'] ?? '') === 'true') {
                return $row;
            }
        }

        return $rows[0] ?? [];
    }

    /** @return list<string> le chiavi della fatturazione, senza prefisso */
    private static function billingKeys(): array
    {
        return ['country', 'province', 'city', 'cap', 'street', 'number', 'more', 'phone_prefix', 'phone',
            'name', 'surname', 'type', 'business_name', 'cf', 'pi', 'sdi', 'pec'];
    }

    /** @return list<string> le chiavi della consegna, senza prefisso */
    private static function shippingKeys(): array
    {
        return ['country', 'province', 'city', 'cap', 'street', 'number', 'more', 'phone_prefix', 'phone',
            'name', 'surname', 'place_id', 'lat', 'lng'];
    }

    /** @return array<string, mixed> */
    private static function settings(): array
    {
        try {
            return Setting::current();
        } catch (Throwable) {
            return [];
        }
    }
}
