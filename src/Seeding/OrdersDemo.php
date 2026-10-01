<?php

namespace Wonder\Plugin\Gestionale\Seeding;

use Wonder\Plugin\Gestionale\Console\Demo\DemoData;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentStatusLog;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Sales\OrderStatusLog;
use Wonder\Plugin\Gestionale\Models\Sales\OrderTaxSummary;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Models\Stock\StockReservation;
use Wonder\Plugin\Gestionale\Support\Mail\Mailer;
use Wonder\Plugin\Gestionale\Support\Orders\Cart;
use Wonder\Plugin\Gestionale\Support\Orders\Checkout;
use Wonder\Plugin\Gestionale\Support\Orders\Lifecycle;
use Wonder\Plugin\Gestionale\Support\Payments\Ledger;
use Wonder\Plugin\Gestionale\Support\Stock\Allocation;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\App\Support\DefaultRows;
use Wonder\Sql\Transaction;

/**
 * Dati di prova delle vendite: **sette ordini**, uno per ogni faccia che
 * l'elenco e la scheda devono saper mostrare.
 *
 * Nascono dal flusso vero — carrello, `Checkout::place()`, `Lifecycle` e
 * `Ledger` — e non da righe scritte a mano: così prenotazioni, scarichi,
 * pagamenti e storico sono quelli che il sito produrrebbe, e se il flusso
 * cambia lo vedono anche i dati di prova.
 *
 * Gli articoli sono quelli di `CatalogDemo`: senza, non c'è niente da
 * vendere e il comando lo dice. Le date si spargono sugli ultimi giorni, per
 * dare un senso ai periodi dell'elenco. Non partono email.
 *
 * La pulizia rimette in magazzino la merce degli ordini che l'avevano
 * presa, poi toglie ordini e tutto quello che portano. I numeri d'ordine già
 * usati restano usati: la numerazione non torna indietro.
 */
final class OrdersDemo
{
    /** Chiave nel registro dei dati di prova. */
    public const KEY = 'ordini-base';

    /** Gli ordini, nell'ordine in cui nascono: riferimento, come finiscono, giorni fa. */
    private const ORDERS = [
        'bonifico-in-attesa' => ['method' => 'bank-transfer', 'end' => 'pending', 'days' => 0],
        'carta-pagata' => ['method' => 'stripe', 'end' => 'paid', 'days' => 1],
        'evaso' => ['method' => 'stripe', 'end' => 'fulfilled', 'days' => 9],
        'annullato' => ['method' => 'bank-transfer', 'end' => 'cancelled', 'days' => 5],
        'pagamento-parziale' => ['method' => 'bank-transfer', 'end' => 'partial', 'days' => 3],
        'ospite' => ['method' => 'stripe', 'end' => 'paid', 'days' => 2, 'guest' => true],
        'azienda' => ['method' => 'bank-transfer', 'end' => 'paid', 'days' => 6, 'company' => true],
    ];

    public static function register(): void
    {
        DemoData::register(
            self::KEY,
            'Ordini di prova',
            static fn (): int => self::create(),
            static fn (): int => self::clear()
        );
    }

    /** @return int ordini creati */
    public static function create(): int
    {
        $missing = array_filter(
            array_keys(self::ORDERS),
            static fn (string $ref): bool => self::find($ref) === []
        );

        if ($missing === []) {
            return 0;
        }

        $products = self::products();

        if ($products === []) {
            DemoData::note('Gli ordini di prova vendono gli articoli di prova: creali prima (catalogo) e rilancia il comando.');

            return 0;
        }

        $created = 0;
        $turn = 0;

        // Niente email: sono ordini finti, e i destinatari sono indirizzi di prova.
        Mailer::useTransport(static fn (): bool => true);

        try {
            foreach (self::ORDERS as $ref => $plan) {
                if (!in_array($ref, $missing, true)) {
                    continue;
                }

                $created += self::place($ref, $plan, $products[$turn % count($products)], $turn);
                $turn++;
            }
        } finally {
            Mailer::useTransport(null);
        }

        return $created;
    }

    /**
     * Toglie gli ordini di prova.
     *
     * @return int ordini tolti
     */
    public static function clear(): int
    {
        $removed = 0;

        foreach (self::demoOrders() as $order) {
            self::purge($order);
            $removed++;
        }

        return $removed;
    }

    /**
     * Un ordine, dal carrello alla fine che gli spetta.
     *
     * @param array<string, mixed> $plan
     * @param array{id: int, price: float} $product
     */
    private static function place(string $ref, array $plan, array $product, int $turn): int
    {
        $method = self::method((string) $plan['method']);

        if ($method === []) {
            DemoData::note('Nessun metodo di pagamento attivo per il sito: l\'ordine «'.$ref.'» non è stato creato.');

            return 0;
        }

        $customer = !empty($plan['company']) ? self::customer('rossi-abbigliamento') : 0;
        $guest = !empty($plan['guest']);
        $email = $guest ? 'ospite.demo@example.com' : ($customer > 0 ? 'amministrazione.rossi@example.com' : 'anna.verdi@example.com');
        $cartId = (int) Cart::open([
            'cart_token' => 'demo-'.$ref,
            'customer_id' => $customer,
            'email' => $email,
        ])['id'];

        Cart::add($cartId, ['product_id' => $product['id'], 'quantity' => 1 + ($turn % 3)]);

        // Il codice col segno: serve a riconoscere l'ordine alla pulizia.
        Order::update(['code' => DemoCode::forModel(Order::class, $ref)], $cartId);

        $billing = !empty($plan['company'])
            ? ['business_name' => 'Rossi Abbigliamento Srl', 'name' => 'Marco', 'surname' => 'Rossi', 'pi' => '00743110157',
                'country' => 'IT', 'province' => 'MI', 'city' => 'Sesto San Giovanni', 'cap' => '20099', 'street' => 'Viale Italia', 'number' => '40']
            : ['name' => $guest ? 'Luca' : 'Anna', 'surname' => $guest ? 'Bianchi' : 'Verdi',
                'country' => 'IT', 'province' => 'RM', 'city' => 'Roma', 'cap' => '00100', 'street' => 'Via del Corso', 'number' => (string) (10 + $turn)];

        $placed = Checkout::place($cartId, [
            'email' => $email,
            'customer_id' => $customer,
            'payment_method_id' => (int) $method['id'],
            'fulfillment_type' => 'shipping',
            'billing' => $billing,
            'source' => 'system',
        ]);

        $orderId = (int) $placed['order_id'];

        match ((string) $plan['end']) {
            'paid' => Lifecycle::confirm($orderId, ['payment' => true, 'notify' => false, 'source' => 'system']),
            'fulfilled' => self::fulfilled($orderId),
            'cancelled' => Lifecycle::cancel($orderId, ['reason' => 'Ordine di prova annullato', 'notify' => false, 'merchant_notice' => false, 'source' => 'system']),
            'partial' => self::partial($orderId, (float) $placed['total']),
            default => null,
        };

        // Gli ordini si spargono sugli ultimi giorni.
        $when = date('Y-m-d H:i:s', strtotime('-'.(int) $plan['days'].' days -'.($turn * 7).' minutes'));
        Order::update(['ordered_at' => $when], $orderId);

        return 1;
    }

    private static function fulfilled(int $orderId): void
    {
        Lifecycle::confirm($orderId, ['payment' => true, 'notify' => false, 'source' => 'system']);
        Lifecycle::fulfill($orderId, 'fulfilled', ['notify' => false, 'source' => 'system']);
    }

    /** Confermato, con metà del denaro arrivato. */
    private static function partial(int $orderId, float $total): void
    {
        Lifecycle::confirm($orderId, ['payment' => false, 'notify' => false, 'source' => 'system']);

        $order = Order::findById($orderId);

        Ledger::register([
            'order_id' => $orderId,
            'amount' => round($total / 2, 2),
            'customer_id' => (int) ($order['customer_id'] ?? 0),
            'payment_method_id' => (int) ($order['payment_method_id'] ?? 0),
            'currency' => (string) ($order['currency'] ?? 'EUR'),
            'provider' => 'manual',
            'source' => 'system',
        ]);
    }

    /**
     * Gli articoli di prova con merce da vendere, per prezzo e disponibilità.
     *
     * @return list<array{id: int, price: float}>
     */
    private static function products(): array
    {
        $out = [];
        $prefix = DemoCode::prefixOf(ProductModel::class);

        foreach (self::rows(ProductModel::find("code LIKE '".$prefix."demo-%' AND deleted = 'false'")) as $model) {
            foreach (self::rows(Product::find(['product_model_id' => (int) $model['id'], 'deleted' => 'false'])) as $product) {
                $price = (float) ($product['price'] ?? 0);

                if ($price > 0 && Levels::of((int) $product['id'])['available'] >= 5) {
                    $out[] = ['id' => (int) $product['id'], 'price' => $price];
                }
            }
        }

        return $out;
    }

    /**
     * Il metodo di pagamento con quel codice, se è attivo; altrimenti il primo
     * attivo e offerto online per ogni consegna (la carta, per esempio, parte
     * spenta finché il commerciante non mette le chiavi).
     *
     * Su un sito che non ha ancora i metodi predefiniti li semina come
     * l'installazione, una volta: sono righe che «ensure» aggiunge solo se mancano.
     *
     * @return array<string, mixed>
     */
    private static function method(string $code): array
    {
        for ($pass = 0; $pass < 2; $pass++) {
            $row = PaymentMethod::find(['code' => $code, 'active' => 'true', 'applies_online' => 'true', 'available_for' => 'all', 'deleted' => 'false'], 1);

            if (!is_array($row) || !isset($row['id'])) {
                $row = PaymentMethod::find(['active' => 'true', 'applies_online' => 'true', 'available_for' => 'all', 'deleted' => 'false'], 1, 'position', 'ASC');
            }

            if (is_array($row) && isset($row['id'])) {
                return $row;
            }

            if ($pass === 0) {
                Defaults::seed(new DefaultRows());
            }
        }

        return [];
    }

    private static function customer(string $ref): int
    {
        $row = Contact::find(['code' => DemoCode::forModel(Contact::class, $ref), 'deleted' => 'false'], 1);

        return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
    }

    /** @return array<string, mixed> */
    private static function find(string $ref): array
    {
        $row = Order::find(['code' => DemoCode::forModel(Order::class, $ref), 'deleted' => 'false'], 1);

        return is_array($row) && isset($row['id']) ? $row : [];
    }

    /**
     * Gli ordini col segno, anche quelli cancellati: il codice è unico e un
     * ordine messo da parte lo bloccherebbe per sempre.
     *
     * @return list<array<string, mixed>>
     */
    private static function demoOrders(): array
    {
        $prefix = DemoCode::prefixOf(Order::class);

        return array_values(array_filter(
            self::rows(Order::find("code LIKE '".$prefix."demo-%'")),
            static fn (array $row): bool => DemoCode::is((string) ($row['code'] ?? ''))
        ));
    }

    /**
     * Un ordine di prova se ne va con tutto quello che ha intorno. Prima la
     * merce torna al suo posto, poi si tolgono righe e storia: la giacenza
     * deve essere quella di prima, anche se il catalogo resta.
     *
     * @param array<string, mixed> $order
     */
    private static function purge(array $order): void
    {
        $id = (int) $order['id'];

        Transaction::run(static function () use ($id, $order): void {
            $status = (string) ($order['status'] ?? '');

            if (in_array($status, ['confirmed', 'processing', 'completed'], true)) {
                foreach (self::rows(OrderItem::find(['order_id' => $id, 'deleted' => 'false'])) as $item) {
                    if ((int) ($item['product_id'] ?? 0) <= 0 || (string) $item['type'] !== 'product') {
                        continue;
                    }

                    Allocation::restore([
                        'product_id' => (int) $item['product_id'],
                        'quantity' => (float) $item['quantity'],
                        'location_id' => (int) ($order['location_id'] ?? 0),
                        'order_id' => $id,
                        'order_item_id' => (int) $item['id'],
                        'source' => 'system',
                    ]);
                }
            } else {
                Allocation::release(['order_id' => $id]);
            }

            foreach (self::rows(Payment::find(['order_id' => $id])) as $payment) {
                sqlDelete(PaymentStatusLog::$table, 'payment_id = '.(int) $payment['id']);
            }

            sqlDelete(StockMovement::$table, "reference_type = 'order' AND reference_id = ".$id);
            sqlDelete(StockReservation::$table, 'order_id = '.$id);
            sqlDelete(Payment::$table, 'order_id = '.$id);
            sqlDelete(OrderStatusLog::$table, 'order_id = '.$id);
            sqlDelete(OrderTaxSummary::$table, 'order_id = '.$id);
            sqlDelete(OrderItem::$table, 'order_id = '.$id);
            sqlDelete(Order::$table, 'id = '.$id);
        });
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $result): array
    {
        if (!is_array($result) || $result === []) {
            return [];
        }

        return isset($result['id']) ? [$result] : array_values(array_filter($result, 'is_array'));
    }
}
