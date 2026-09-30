<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use RuntimeException;
use Throwable;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Sales\OrderStatusLog;
use Wonder\Plugin\Gestionale\Models\Stock\Stock as StockRow;
use Wonder\Plugin\Gestionale\Models\Stock\StockReservation;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Status\StatusLogger;
use Wonder\Sql\Transaction;

/**
 * **L'unico punto che tocca il magazzino per una vendita.**
 *
 * Cinque metodi, in ordine di vita di un ordine: `reserve()` mette la merce da
 * parte, `release()` la lascia andare, `commit()` la scarica davvero,
 * `restore()` la fa rientrare da un ordine annullato, `returnGoods()` da un
 * reso. Nessuno di loro scrive un movimento di suo: lo chiede a `Stock`, che
 * resta l'unica porta di scrittura del magazzino. L'unica tabella che
 * `Allocation` scrive è `gst_stock_reservations`.
 *
 * Tutti e cinque prendono sede, lotto e fornitore. Oggi la sede è quella
 * principale e lotto e fornitore sono zero, ma la firma è già quella giusta:
 * G3 cambierà il dentro dei metodi senza riaprire gli ordini.
 *
 * Il lock è l'antioverselling: le righe di `gst_stock` si leggono con
 * `FOR UPDATE` **prima** delle prenotazioni, così due checkout sull'ultimo
 * pezzo si mettono in fila e il secondo legge un disponibile che tiene già
 * conto del primo.
 */
final class Allocation
{
    /**
     * Mette da parte la merce di una riga d'ordine.
     *
     * @param array<string, mixed> $line `product_id`, `quantity`, `location_id`,
     *     `batch_id`, `supplier_id`, `order_id`, `order_item_id`,
     *     `expires_at` (facoltativa: vince sulle impostazioni)
     * @return array{reservation_id: int, quantity: float, available: float, expires_at: string}
     */
    public static function reserve(array $line): array
    {
        $context = self::context($line);

        if ($context['quantity'] <= 0) {
            throw UserError::make('order.zero_quantity');
        }

        return Transaction::run(static function () use ($context, $line): array {
            $onHand = self::lockedQuantity($context);
            $reservations = self::liveReservations($context['product_id'], $context['location_id']);
            $available = Availability::of($onHand, $reservations);

            if ($available < $context['quantity'] && !Stock::allowsBackorder($context['product'])) {
                throw UserError::make('stock.insufficient', ['product' => self::label($context['product'])]);
            }

            $expiresAt = array_key_exists('expires_at', $line)
                ? trim((string) $line['expires_at'])
                : self::defaultExpiry();

            $created = StockReservation::create([
                'product_id' => $context['product_id'],
                'location_id' => $context['location_id'],
                'quantity' => self::number($context['quantity']),
                'order_id' => (int) ($line['order_id'] ?? 0),
                'order_item_id' => (int) ($line['order_item_id'] ?? 0),
                'expires_at' => $expiresAt,
            ]);

            if (($created->success ?? false) !== true) {
                // Non è un errore da far leggere: è un guasto, e la transazione
                // riporta indietro tutto.
                throw new RuntimeException('Prenotazione di magazzino non scritta.');
            }

            return [
                'reservation_id' => (int) ($created->insert_id ?? 0),
                'quantity' => $context['quantity'],
                'available' => round($available - $context['quantity'], 3),
                'expires_at' => $expiresAt,
            ];
        });
    }

    /**
     * Lascia andare la merce messa da parte: tutta quella di un ordine, o solo
     * quella di una sua riga.
     *
     * Una prenotazione già chiusa non si richiude: torna quante ne ha chiuse
     * davvero, che è zero quando il carrello era già scaduto.
     *
     * @param array<string, mixed> $line `order_id` (obbligatoria),
     *     `order_item_id`, `product_id`, `location_id`, `batch_id`, `supplier_id`
     */
    public static function release(array $line): int
    {
        $orderId = (int) ($line['order_id'] ?? 0);

        if ($orderId <= 0) {
            return 0;
        }

        $itemId = (int) ($line['order_item_id'] ?? 0);
        $productId = (int) ($line['product_id'] ?? 0);
        $now = date('Y-m-d H:i:s');
        $closed = 0;

        foreach (self::reservationsOfOrder($orderId) as $reservation) {
            if ($itemId > 0 && (int) ($reservation['order_item_id'] ?? 0) !== $itemId) {
                continue;
            }

            if ($productId > 0 && (int) ($reservation['product_id'] ?? 0) !== $productId) {
                continue;
            }

            if (!Availability::isActive($reservation, $now)) {
                continue;
            }

            StockReservation::update(['released_at' => $now], (int) $reservation['id']);
            ++$closed;
        }

        return $closed;
    }

    /**
     * Scarica davvero la merce di una riga d'ordine.
     *
     * Con una prenotazione valida scarica **sempre**, anche se il magazzino
     * nel frattempo si è svuotato: quel pezzo era di questo cliente. Senza
     * prenotazione, e senza merce, decide il resto — `backorders` accesa manda
     * sotto zero; spenta, un ordine già pagato va sotto zero e il commerciante
     * viene avvisato; spenta e non pagato, l'ordine resta in attesa.
     *
     * @param array<string, mixed> $line come `reserve()`, più `payment_ok`
     *     (bool: il pagamento è riuscito), `source` e `user_id`
     * @return array{movement_id: int, before: float, after: float, reserved: float, oversold: bool, merchant_alert: bool}
     */
    public static function commit(array $line): array
    {
        $context = self::context($line);

        if ($context['quantity'] <= 0) {
            throw UserError::make('order.zero_quantity');
        }

        return Transaction::run(static function () use ($context, $line): array {
            $onHand = self::lockedQuantity($context);
            $reserved = self::reservedFor($line, $context);
            $hasReservation = $reserved + 0.0005 >= $context['quantity'];
            $backorder = Stock::allowsBackorder($context['product']);
            $paid = ($line['payment_ok'] ?? false) === true;

            if (!$hasReservation && $onHand < $context['quantity'] && !$backorder && !$paid) {
                // La merce non c'è, nessuno l'aveva messa da parte e nessuno ha
                // ancora pagato: l'ordine aspetta invece di scavare un buco.
                throw UserError::make('order.reservation_lost');
            }

            if ($hasReservation) {
                self::release($line);
            }

            $movement = Stock::apply([
                'product_id' => $context['product_id'],
                'location_id' => $context['location_id'],
                'batch_id' => $context['batch_id'],
                'supplier_id' => $context['supplier_id'],
                'quantity' => -$context['quantity'],
                'type' => 'sale',
                'reference_type' => 'order',
                'reference_id' => (int) ($line['order_id'] ?? 0),
                'source' => (string) ($line['source'] ?? 'backend'),
                'user_id' => (int) ($line['user_id'] ?? 0),
                'allow_negative' => $hasReservation || $backorder || $paid,
            ]);

            $oversold = $movement['after'] < 0;
            $alert = $oversold && !$backorder;

            if ($alert) {
                self::warnMerchant((int) ($line['order_id'] ?? 0), $context, $movement['after'], $line);
            }

            return [
                'movement_id' => $movement['movement_id'],
                'before' => $movement['before'],
                'after' => $movement['after'],
                'reserved' => $reserved,
                'oversold' => $oversold,
                'merchant_alert' => $alert,
            ];
        });
    }

    /**
     * Quanta merce di questa riga era messa da parte e vale ancora.
     *
     * Con `order_item_id` conta solo quella riga; senza, tutte le prenotazioni
     * di quel prodotto in quell'ordine — un ordine dal banco non ha righe
     * numerate.
     *
     * @param array<string, mixed> $line
     * @param array{product_id: int, location_id: int} $context
     */
    private static function reservedFor(array $line, array $context): float
    {
        $orderId = (int) ($line['order_id'] ?? 0);

        if ($orderId <= 0) {
            return 0.0;
        }

        $itemId = (int) ($line['order_item_id'] ?? 0);
        $now = date('Y-m-d H:i:s');
        $total = 0.0;

        foreach (self::reservationsOfOrder($orderId) as $reservation) {
            if ((int) ($reservation['product_id'] ?? 0) !== $context['product_id']) {
                continue;
            }

            if ($itemId > 0 && (int) ($reservation['order_item_id'] ?? 0) !== $itemId) {
                continue;
            }

            if (Availability::isActive($reservation, $now)) {
                $total += (float) ($reservation['quantity'] ?? 0);
            }
        }

        return round($total, 3);
    }

    /**
     * Scrive nel log dell'ordine che si è venduto qualcosa che non c'era.
     *
     * Qui resta scritto; l'email al commerciante la manda il piano 3, che
     * porta tutte le email di G4. Un log che non si scrive non ferma una
     * vendita già incassata.
     *
     * @param array{product: array<string, mixed>, location_id: int} $context
     * @param array<string, mixed> $line
     */
    private static function warnMerchant(int $orderId, array $context, float $after, array $line): void
    {
        if ($orderId <= 0) {
            return;
        }

        StatusLogger::record(
            OrderStatusLog::class,
            $orderId,
            'stock',
            'available',
            'oversold',
            (string) ($line['source'] ?? 'system') === 'backend' ? 'user' : 'system',
            (int) ($line['user_id'] ?? 0) ?: null,
            sprintf(
                'Venduto senza giacenza: %s resta a %s pezzi nella sede %d.',
                self::label($context['product']),
                self::number($after),
                $context['location_id']
            )
        );
    }

    /**
     * Prodotto, quantità e chiavi di magazzino, controllati una volta sola.
     *
     * @param array<string, mixed> $line
     * @return array{product: array<string, mixed>, product_id: int, quantity: float, location_id: int, batch_id: int, supplier_id: int}
     */
    private static function context(array $line): array
    {
        $productId = (int) ($line['product_id'] ?? 0);
        $product = $productId > 0 ? Product::findById($productId) : null;

        if (!is_array($product) || $product === []) {
            throw UserError::make('stock.product_missing');
        }

        $locationId = (int) ($line['location_id'] ?? 0) ?: Locations::mainId();

        if ($locationId <= 0) {
            throw UserError::make('stock.no_location');
        }

        return [
            'product' => $product,
            'product_id' => $productId,
            'quantity' => round((float) ($line['quantity'] ?? 0), 3),
            'location_id' => $locationId,
            'batch_id' => (int) ($line['batch_id'] ?? 0),
            'supplier_id' => (int) ($line['supplier_id'] ?? 0),
        ];
    }

    /**
     * La giacenza della sede, letta con il lock.
     *
     * Blocca tutte le righe del prodotto in quella sede — lotti e fornitori
     * diversi sono righe diverse — perché è la sede l'unità che si vende. Con
     * lotto o fornitore nella richiesta si restringe a quella riga: è G3 che
     * comincerà a passarli.
     *
     * @param array{product_id: int, location_id: int, batch_id: int, supplier_id: int} $context
     */
    private static function lockedQuantity(array $context): float
    {
        $where = [
            'product_id' => $context['product_id'],
            'location_id' => $context['location_id'],
            'deleted' => 'false',
        ];

        if ($context['batch_id'] > 0) {
            $where['batch_id'] = $context['batch_id'];
        }

        if ($context['supplier_id'] > 0) {
            $where['supplier_id'] = $context['supplier_id'];
        }

        $quantity = 0.0;

        foreach (self::rows(StockRow::findForUpdate($where)) as $row) {
            $quantity += (float) ($row['quantity'] ?? 0);
        }

        return round($quantity, 3);
    }

    /**
     * Le prenotazioni vive del prodotto in quella sede.
     *
     * Non si restringono per lotto: la tabella non ha la colonna, e contarle
     * tutte è il verso prudente dell'errore.
     *
     * @return list<array<string, mixed>>
     */
    private static function liveReservations(int $productId, int $locationId): array
    {
        return self::rows(StockReservation::find(
            "product_id = {$productId} AND location_id = {$locationId} AND deleted = 'false'"
        ));
    }

    /** @return list<array<string, mixed>> */
    private static function reservationsOfOrder(int $orderId): array
    {
        return self::rows(StockReservation::find(
            "order_id = {$orderId} AND deleted = 'false'"
        ));
    }

    /**
     * Il ritorno di `find()` in una forma sola: una riga sola arriva senza
     * l'indice, e senza database non arriva niente.
     *
     * @return list<array<string, mixed>>
     */
    private static function rows(mixed $rows): array
    {
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }

    /**
     * La scadenza dalle impostazioni; zero minuti vuol dire che non scade.
     */
    private static function defaultExpiry(): string
    {
        try {
            $minutes = (int) (Setting::current()['order_reservation_minutes'] ?? 30);
        } catch (Throwable) {
            $minutes = 30;
        }

        return $minutes > 0 ? date('Y-m-d H:i:s', time() + $minutes * 60) : '';
    }

    /** Il nome che legge una persona, o lo SKU se il nome manca. */
    private static function label(array $product): string
    {
        $name = trim((string) ($product['name'] ?? ''));

        return $name !== '' ? $name : (string) ($product['sku'] ?? '');
    }

    /** La forma che il database accetta sempre: punto, non virgola. */
    private static function number(float $value): string
    {
        return number_format($value, 3, '.', '');
    }
}
