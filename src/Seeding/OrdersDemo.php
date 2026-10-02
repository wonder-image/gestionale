<?php

namespace Wonder\Plugin\Gestionale\Seeding;

use Wonder\Plugin\Gestionale\Console\Demo\DemoData;
use Wonder\Plugin\Gestionale\Gestionale;
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
use Wonder\Plugin\Gestionale\Models\Sales\SalesReturn;
use Wonder\Plugin\Gestionale\Models\Sales\SalesReturnItem;
use Wonder\Plugin\Gestionale\Models\Sales\SalesReturnStatusLog;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Models\Stock\StockReservation;
use Wonder\Plugin\Gestionale\Support\Catalog\Bundles;
use Wonder\Plugin\Gestionale\Support\Catalog\Customizations;
use Wonder\Plugin\Gestionale\Support\Mail\Mailer;
use Wonder\Plugin\Gestionale\Support\Orders\Cart;
use Wonder\Plugin\Gestionale\Support\Orders\Checkout;
use Wonder\Plugin\Gestionale\Support\Orders\Lifecycle;
use Wonder\Plugin\Gestionale\Support\Orders\OrderLines;
use Wonder\Plugin\Gestionale\Support\Payments\Ledger;
use Wonder\Plugin\Gestionale\Support\Returns\Returns;
use Wonder\Plugin\Gestionale\Support\Stock\Allocation;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\App\Support\DefaultRows;
use Wonder\Sql\Transaction;

/**
 * Dati di prova delle vendite: **sette ordini**, uno per ogni faccia che
 * l'elenco e la scheda devono saper mostrare, e **quattro ordini di
 * multiprodotti** (in attesa, confermato, annullato, evaso con un reso della
 * confezione) che nascono solo a funzionalità `bundles` accesa.
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
        // I multiprodotti di CatalogDemo: la madre e le figlie con le scelte del cliente (la prima opzione di ogni gruppo).
        'cesto-in-attesa' => ['method' => 'bank-transfer', 'end' => 'pending', 'days' => 1, 'bundle' => 'cesto-componibile'],
        'cesto-confermato' => ['method' => 'stripe', 'end' => 'paid', 'days' => 2, 'bundle' => 'cesto-completo'],
        'cesto-annullato' => ['method' => 'bank-transfer', 'end' => 'cancelled', 'days' => 4, 'bundle' => 'cesto-degustazione'],
        'cesto-evaso' => ['method' => 'stripe', 'end' => 'fulfilled', 'days' => 8, 'bundle' => 'cesto-degustazione'],
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
        $bundles = Gestionale::feature('bundles');
        $missing = array_filter(
            array_keys(self::ORDERS),
            static fn (string $ref): bool => ($bundles || empty(self::ORDERS[$ref]['bundle'])) && self::find($ref) === []
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

                if (!empty($plan['bundle'])) {
                    $created += self::place($ref, $plan, self::bundle((string) $plan['bundle']), $turn);
                    $turn++;

                    continue;
                }

                $product = $products[$turn % count($products)];

                // Ai giri pari l'incisione ci vuole: si passa a un articolo che la offre.
                if ($turn % 2 === 0 && self::engraving($product['id']) === []) {
                    foreach ($products as $other) {
                        if (self::engraving($other['id']) !== []) {
                            $product = $other;
                            break;
                        }
                    }
                }

                $created += self::place($ref, $plan, $product, $turn);
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
     * @param array{id: int, price?: float, choices?: list<int>} $product
     */
    private static function place(string $ref, array $plan, array $product, int $turn): int
    {
        $method = self::method((string) $plan['method']);

        if (empty($product['id'])) {
            DemoData::note('Il multiprodotto «'.$plan['bundle'].'» non c\'è: l\'ordine «'.$ref.'» non è stato creato (crea prima il catalogo).');

            return 0;
        }

        if ($method === []) {
            DemoData::note('Nessun metodo di pagamento attivo per il sito: l\'ordine «'.$ref.'» non è stato creato.');

            return 0;
        }

        $guest = !empty($plan['guest']);
        // L'ospite compra senza scheda: è il caso in cui il cliente non è un link.
        $customer = !empty($plan['company']) ? self::customer('rossi-abbigliamento') : ($guest ? 0 : self::customer('verdi'));
        $email = $guest ? 'ospite.demo@example.com' : (!empty($plan['company']) ? 'amministrazione.rossi@example.com' : 'anna.verdi@example.com');
        $cartId = (int) Cart::open([
            'cart_token' => 'demo-'.$ref,
            'customer_id' => $customer,
            'email' => $email,
        ])['id'];

        $bundle = !empty($plan['bundle']);

        Cart::add($cartId, [
            'product_id' => $product['id'],
            'quantity' => $bundle ? 1 : 1 + ($turn % 3),
            'customization' => !$bundle && $turn % 2 === 0 ? self::engraving((int) $product['id']) : [],
            'choices' => $product['choices'] ?? [],
        ]);

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
            'fulfilled' => self::fulfilled($orderId, $ref),
            'cancelled' => Lifecycle::cancel($orderId, ['reason' => 'Ordine di prova annullato', 'notify' => false, 'merchant_notice' => false, 'source' => 'system']),
            'partial' => self::partial($orderId, (float) $placed['total']),
            default => null,
        };

        // Gli ordini si spargono sugli ultimi giorni.
        $when = date('Y-m-d H:i:s', strtotime('-'.(int) $plan['days'].' days -'.($turn * 7).' minutes'));
        Order::update(['ordered_at' => $when], $orderId);

        return 1;
    }

    /**
     * L'incisione «Auguri», se l'articolo ce l'ha e la funzionalità è accesa:
     * così una riga d'ordine su due porta una personalizzazione vera, pagata
     * e scritta dal flusso del carrello.
     *
     * @return array<int, string> valori per `Cart::add()`, vuoto se non c'è
     */
    private static function engraving(int $productId): array
    {
        if (!Gestionale::feature('customizations')) {
            return [];
        }

        $product = Product::findById($productId);

        foreach (Customizations::forModel((int) ($product['product_model_id'] ?? 0)) as $entry) {
            if ($entry['kind'] === 'text' && !$entry['required']) {
                return [$entry['id'] => 'Auguri'];
            }
        }

        return [];
    }

    private static function fulfilled(int $orderId, string $ref): void
    {
        Lifecycle::confirm($orderId, ['payment' => true, 'notify' => false, 'source' => 'system']);
        Lifecycle::fulfill($orderId, 'fulfilled', ['notify' => false, 'source' => 'system']);

        if (Gestionale::feature('returns')) {
            self::returned($orderId, $ref);
        }
    }

    /**
     * Il multiprodotto di prova: il suo prodotto e la prima opzione di ogni gruppo.
     *
     * @return array{id: int, choices: list<int>}|array{}
     */
    private static function bundle(string $ref): array
    {
        $model = ProductModel::find(['code' => DemoCode::forModel(ProductModel::class, $ref), 'deleted' => 'false'], 1);
        $product = is_array($model) && isset($model['id'])
            ? Product::find(['product_model_id' => (int) $model['id'], 'deleted' => 'false'], 1)
            : null;

        if (!is_array($product) || !isset($product['id'])) {
            return [];
        }

        $choices = [];

        foreach (Bundles::forModel((int) $model['id'])['groups'] as $group) {
            if ($group['options'] !== []) {
                $choices[] = (int) $group['options'][0]['id'];
            }
        }

        return ['id' => (int) $product['id'], 'choices' => $choices];
    }

    /** Un pezzo della prima riga è tornato indietro: reso ricevuto e rientrato a magazzino. */
    private static function returned(int $orderId, string $ref): void
    {
        $item = OrderItem::find(['order_id' => $orderId, 'type' => 'product', 'deleted' => 'false'], 1);

        if (!is_array($item) || !isset($item['id'])) {
            return;
        }

        $all = self::rows(OrderItem::find(['order_id' => $orderId, 'deleted' => 'false']));
        $kids = OrderLines::children($all, (int) $item['id']);

        // Una confezione torna intera: la madre e i componenti, di cui il primo è «difettoso» e non rientra.
        $line = $kids === []
            ? ['order_item_id' => (int) $item['id'], 'quantity' => 1, 'reason' => 'changed_mind']
            : [
                'order_item_id' => (int) $item['id'],
                'quantity' => 1,
                'reason' => 'defective',
                'children_restock' => array_combine(
                    array_map(static fn (array $kid): int => (int) $kid['id'], $kids),
                    array_map(static fn (int $i): bool => $i > 0, array_keys($kids))
                ),
            ];

        $done = Returns::register($orderId, [$line], ['internal_note' => 'Reso di prova', 'source' => 'system']);

        // Il codice col segno: serve a riconoscere il reso alla pulizia.
        SalesReturn::update(['code' => DemoCode::forModel(SalesReturn::class, $ref)], $done['return_id']);
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

            // I resi hanno già rimesso a scaffale una parte: si restituisce solo il resto.
            $back = self::restocked($id);

            if (in_array($status, ['confirmed', 'processing', 'completed'], true)) {
                // La madre di una confezione non ha mai toccato il magazzino: tornano le figlie.
                foreach (OrderLines::goods(self::rows(OrderItem::find(['order_id' => $id, 'deleted' => 'false']))) as $item) {
                    if ((int) ($item['product_id'] ?? 0) <= 0 || (string) $item['type'] !== 'product') {
                        continue;
                    }

                    $quantity = (float) $item['quantity'] - ($back[(int) $item['id']] ?? 0.0);

                    if ($quantity <= 0) {
                        continue;
                    }

                    Allocation::restore([
                        'product_id' => (int) $item['product_id'],
                        'quantity' => $quantity,
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

            // I resi dell'ordine, di prova o fatti a mano: l'ordine se ne va e i resi con lui,
            // con la merce rientrata, prima delle righe d'ordine a cui sono legati.
            foreach (self::rows(SalesReturn::find(['order_id' => $id])) as $return) {
                $returnId = (int) $return['id'];

                sqlDelete(StockMovement::$table, "reference_type = 'sales_return' AND reference_id = ".$returnId);
                sqlDelete(SalesReturnStatusLog::$table, 'sales_return_id = '.$returnId);
                sqlDelete(SalesReturnItem::$table, 'sales_return_id = '.$returnId);
                sqlDelete(SalesReturn::$table, 'id = '.$returnId);
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

    /**
     * I pezzi rientrati a magazzino con i resi di quell'ordine, per riga.
     *
     * @return array<int, float>
     */
    private static function restocked(int $orderId): array
    {
        $out = [];

        foreach (self::rows(SalesReturn::find(['order_id' => $orderId])) as $return) {
            foreach (self::rows(SalesReturnItem::find(['sales_return_id' => (int) $return['id'], 'restock' => 'true'])) as $item) {
                $out[(int) $item['order_item_id']] = ($out[(int) $item['order_item_id']] ?? 0.0) + (float) $item['quantity'];
            }
        }

        return $out;
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
