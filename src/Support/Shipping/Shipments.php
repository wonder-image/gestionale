<?php

namespace Wonder\Plugin\Gestionale\Support\Shipping;

use Throwable;
use Wonder\App\Support\SocietyLocations;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Locations\Location;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Shipping\Carrier;
use Wonder\Plugin\Gestionale\Models\Shipping\Shipment;
use Wonder\Plugin\Gestionale\Models\Shipping\ShipmentItem;
use Wonder\Plugin\Gestionale\Models\Shipping\ShipmentStatusLog;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Numbers;
use Wonder\Plugin\Gestionale\Support\Orders\Lifecycle;
use Wonder\Plugin\Gestionale\Support\Orders\OrderNotifier;
use Wonder\Plugin\Gestionale\Support\Status\StatusLogger;
use Wonder\Sql\Transaction;

/**
 * Le spedizioni vere di un ordine: cosa esce, in quante tranche, con che stato.
 *
 * Questa classe è l'unica porta che scrive `Shipment` e `ShipmentItem`. Una
 * riga d'ordine si può spedire in più tranche, ma mai più di quanto è stato
 * ordinato: il conto delle quantità già assegnate si fa **con le righe
 * dell'ordine bloccate** (`SELECT … FOR UPDATE`), così due operatori che
 * creano una spedizione nello stesso istante si mettono in fila invece di
 * assegnare entrambi l'ultimo pezzo.
 */
final class Shipments
{
    /** Gli stati di una spedizione che non occupano più la merce. */
    public const RELEASED = ['cancelled', 'returned'];

    /**
     * Quanto resta da spedire di ogni riga spedibile dell'ordine.
     *
     * @return array<int, float> riga d'ordine => quantità
     */
    public static function remaining(int $orderId): array
    {
        $items = self::items($orderId);

        return ShippableLines::remaining(self::describe($items), self::assigned($orderId));
    }

    /**
     * Crea una spedizione di consegna in attesa (`pending`) con le righe date.
     *
     * @param array<int, mixed> $quantities riga d'ordine => quantità (vuota o zero = la riga non parte)
     * @param array{carrier_id?: int, tracking_number?: string, note?: string, source?: string, user_id?: int} $options
     * @return int l'id della spedizione
     * @throws UserError
     */
    public static function create(int $orderId, array $quantities, array $options = []): int
    {
        if (!Gestionale::feature('shipping')) {
            throw UserError::make('shipment.feature_off');
        }

        return Transaction::run(static function () use ($orderId, $quantities, $options): int {
            $order = Order::findForUpdate(['id' => $orderId], 1);

            if (!is_array($order) || $order === []) {
                throw UserError::make('order.not_found');
            }

            if ((string) $order['stage'] !== 'order'
                || !in_array((string) $order['status'], Lifecycle::COMMITTED, true)
                || (string) $order['fulfillment_type'] !== 'shipping') {
                throw UserError::make('shipment.order_not_open');
            }

            $wanted = self::wanted($quantities);

            if ($wanted === []) {
                throw UserError::make('shipment.nothing_to_ship');
            }

            // Prima il blocco delle righe, poi il conto di quanto è già assegnato.
            $items = self::items($orderId, true);
            $remaining = ShippableLines::remaining(self::describe($items), self::assigned($orderId, true));

            foreach ($wanted as $itemId => $quantity) {
                if (!isset($remaining[$itemId]) || $quantity > $remaining[$itemId] + 0.0005) {
                    throw UserError::make('shipment.over_quantity');
                }
            }

            $shipmentId = (int) (Shipment::create([
                'code' => Code::make(Shipment::class, Codes::SHIPMENT),
                'order_id' => $orderId,
                'type' => 'delivery',
                'status' => 'pending',
                'carrier_id' => (int) ($options['carrier_id'] ?? 0),
                'tracking_number' => trim((string) ($options['tracking_number'] ?? '')),
                'note' => trim((string) ($options['note'] ?? '')),
            ])->insert_id ?? 0);

            foreach ($wanted as $itemId => $quantity) {
                ShipmentItem::create([
                    'shipment_id' => $shipmentId,
                    'order_item_id' => $itemId,
                    'quantity' => number_format($quantity, 3, '.', ''),
                ]);
            }

            StatusLogger::record(
                ShipmentStatusLog::class,
                $shipmentId,
                'status',
                '',
                'pending',
                (string) ($options['source'] ?? 'user'),
                (int) ($options['user_id'] ?? 0) ?: null
            );

            return $shipmentId;
        });
    }

    /**
     * Fa partire una spedizione: vettore, tracking e data, poi `in_transit`.
     *
     * Con un vettore che ha un modello di link il tracking è obbligatorio: il
     * cliente lo usa per seguire il pacco. Da `pending` o `label_created`;
     * una spedizione già partita resta com'è (nessuna scrittura).
     *
     * @param array{carrier_id?: int, tracking_number?: string, shipped_at?: string, source?: string, user_id?: int} $data
     * @throws UserError
     */
    public static function ship(int $shipmentId, array $data = []): void
    {
        self::guardFeature();

        $changed = Transaction::run(static fn (): bool => self::shipInside($shipmentId, $data));

        if ($changed) {
            self::notify($shipmentId, 'shipped');
        }
    }

    /**
     * Il lavoro di `ship` dentro la transazione, senza l'email: la chiama anche
     * `advance`, che manda la sua una volta sola a transazione chiusa.
     *
     * @param array<string, mixed> $data
     * @return bool vero se la spedizione è partita adesso
     */
    private static function shipInside(int $shipmentId, array $data): bool
    {
        $orderId = self::orderOf($shipmentId);
        $order = self::lockOrder($orderId);

        if ((string) $order['stage'] !== 'order' || !in_array((string) $order['status'], Lifecycle::COMMITTED, true)) {
            throw UserError::make('shipment.order_not_open');
        }

        $shipment = self::lock($shipmentId);

        if ((string) $shipment['type'] !== 'delivery') {
            throw UserError::make('shipment.bad_transition');
        }

        if ((string) $shipment['status'] === 'in_transit') {
            return false;
        }

        if (!in_array('in_transit', ShipmentFlow::allowed('delivery', (string) $shipment['status']), true)) {
            throw UserError::make('shipment.bad_transition');
        }

        $values = self::shippingValues($shipment, $data);
        $values['status'] = 'in_transit';

        self::write($shipment, $values, 'in_transit', $data);
        self::syncOrder($orderId, $data);

        return true;
    }

    /**
     * Crea il ritiro in sede di un ordine: una spedizione `pickup` in attesa con
     * tutto quello che resta da consegnare.
     *
     * La sede è quella dell'ordine e deve essere attiva, di ritiro e aperta;
     * di ritiri vivi ce n'è uno solo per ordine (uno annullato non conta).
     *
     * @param array{note?: string, source?: string, user_id?: int} $options
     * @return int l'id della spedizione
     * @throws UserError
     */
    public static function createPickup(int $orderId, array $options = []): int
    {
        self::guardFeature();

        return Transaction::run(static function () use ($orderId, $options): int {
            $order = self::lockOrder($orderId);

            if ((string) $order['stage'] !== 'order'
                || !in_array((string) $order['status'], Lifecycle::COMMITTED, true)
                || (string) $order['fulfillment_type'] !== 'pickup') {
                throw UserError::make('shipment.order_not_open');
            }

            $locationId = (int) ($order['location_id'] ?? 0);
            $location = $locationId > 0 ? Location::findById($locationId) : null;

            if (!is_array($location) || $location === []
                || (string) ($location['deleted'] ?? 'false') === 'true'
                || (string) $location['active'] !== 'true'
                || (string) $location['is_pickup_point'] !== 'true') {
                throw UserError::make('shipment.not_pickup_point');
            }

            $place = SocietyLocations::find((int) $location['society_location_id']);

            if ($place === null || !SocietyLocations::isOpen($place)) {
                throw UserError::make('shipment.location_closed');
            }

            // Prima il blocco delle righe, poi il conto di quanto è già assegnato.
            $items = self::items($orderId, true);
            $assigned = self::assigned($orderId, true);

            foreach (self::rows(Shipment::find(['order_id' => $orderId, 'type' => 'pickup', 'deleted' => 'false'])) as $existing) {
                if ((string) $existing['status'] !== 'cancelled') {
                    throw UserError::make('shipment.pickup_exists');
                }
            }

            $remaining = array_filter(
                ShippableLines::remaining(self::describe($items), $assigned),
                static fn (float $quantity): bool => $quantity > 0.0005
            );

            if ($remaining === []) {
                throw UserError::make('shipment.nothing_to_ship');
            }

            $shipmentId = (int) (Shipment::create([
                'code' => Code::make(Shipment::class, Codes::SHIPMENT),
                'order_id' => $orderId,
                'type' => 'pickup',
                'status' => 'pending',
                'location_id' => $locationId,
                'note' => trim((string) ($options['note'] ?? '')),
            ])->insert_id ?? 0);

            foreach ($remaining as $itemId => $quantity) {
                ShipmentItem::create([
                    'shipment_id' => $shipmentId,
                    'order_item_id' => $itemId,
                    'quantity' => number_format($quantity, 3, '.', ''),
                ]);
            }

            StatusLogger::record(
                ShipmentStatusLog::class,
                $shipmentId,
                'status',
                '',
                'pending',
                (string) ($options['source'] ?? 'user'),
                (int) ($options['user_id'] ?? 0) ?: null
            );

            return $shipmentId;
        });
    }

    /**
     * Il ritiro è pronto: il cliente può passare. L'ordine diventa
     * `ready_for_pickup`.
     *
     * @param array{source?: string, user_id?: int} $options
     * @throws UserError
     */
    public static function ready(int $shipmentId, array $options = []): void
    {
        self::advance($shipmentId, 'ready_for_pickup', $options);
    }

    /**
     * Il cliente ha ritirato: la merce è evasa, e con l'ordine pagato si chiude.
     *
     * @param array{source?: string, user_id?: int} $options
     * @throws UserError
     */
    public static function pickedUp(int $shipmentId, array $options = []): void
    {
        self::advance($shipmentId, 'picked_up', $options);
    }

    /**
     * Porta una spedizione allo stato dato, se la strada è consentita.
     *
     * `in_transit` da `pending`/`label_created` passa da `ship()` (serve il
     * tracking), `cancelled` da `cancel()`. Gli stati intermedi si possono
     * saltare in avanti; `delivered` scrive `delivered_at`.
     *
     * @param array{source?: string, user_id?: int, carrier_id?: int, tracking_number?: string, shipped_at?: string} $options
     * @throws UserError
     */
    public static function advance(int $shipmentId, string $to, array $options = []): void
    {
        self::guardFeature();

        // L'email dice quale sia: parte a transazione chiusa, una volta sola.
        $mail = Transaction::run(static function () use ($shipmentId, $to, $options): string {
            $orderId = self::orderOf($shipmentId);
            $order = self::lockOrder($orderId);
            $shipment = self::lock($shipmentId);
            $from = (string) $shipment['status'];

            if ($from === $to) {
                return '';
            }

            if (!in_array($to, ShipmentFlow::allowed((string) $shipment['type'], $from), true)) {
                throw UserError::make('shipment.bad_transition');
            }

            if ($to === 'cancelled') {
                self::cancel($shipmentId, $options);

                return '';
            }

            $mail = '';

            if ((string) $shipment['type'] === 'delivery' && in_array($from, ['pending', 'label_created'], true)) {
                // Dal magazzino al cliente passa dal «partita»: servono vettore e tracking.
                $mail = self::shipInside($shipmentId, $options) ? 'shipped' : '';
                $shipment = self::lock($shipmentId);
                $from = (string) $shipment['status'];

                if ($from === $to) {
                    return $mail;
                }
            }

            $values = ['status' => $to];

            if ($to === 'delivered') {
                $values['delivered_at'] = date('Y-m-d H:i:s');
            }

            self::write($shipment, $values, $to, $options);
            self::syncOrder((int) $order['id'], $options);

            return $to === 'ready_for_pickup' ? 'ready_for_pickup' : $mail;
        });

        if ($mail !== '') {
            self::notify($shipmentId, $mail);
        }
    }

    /**
     * Annulla una spedizione non ancora partita: la merce torna spedibile.
     *
     * @param array{source?: string, user_id?: int} $options
     * @throws UserError
     */
    public static function cancel(int $shipmentId, array $options = []): void
    {
        self::guardFeature();

        Transaction::run(static function () use ($shipmentId, $options): void {
            $orderId = self::orderOf($shipmentId);
            self::lockOrder($orderId);
            $shipment = self::lock($shipmentId);

            if ((string) $shipment['status'] === 'cancelled') {
                return;
            }

            if (!in_array('cancelled', ShipmentFlow::allowed((string) $shipment['type'], (string) $shipment['status']), true)) {
                throw UserError::make('shipment.bad_transition');
            }

            self::write($shipment, ['status' => 'cancelled'], 'cancelled', $options);
            self::syncOrder($orderId, $options);
        });
    }

    /**
     * Ricalcola l'evasione dell'ordine dalle spedizioni che contano e la
     * passa a `Lifecycle::fulfill` solo se cambia: la chiusura automatica e la
     * storia dell'ordine restano quelle di sempre.
     *
     * @param array{source?: string, user_id?: int} $options
     */
    public static function syncOrder(int $orderId, array $options = []): void
    {
        Transaction::run(static function () use ($orderId, $options): void {
            $order = Order::findForUpdate(['id' => $orderId], 1);

            if (!is_array($order) || $order === []) {
                return;
            }

            $ordered = ShippableLines::remaining(self::describe(self::items($orderId)), []);
            $counted = self::assigned($orderId, false, true);
            $lines = [];

            foreach ($ordered as $itemId => $quantity) {
                $lines[] = ['ordered' => $quantity, 'shipped' => $counted[$itemId] ?? 0.0];
            }

            $target = ShipmentFlow::fulfillment(
                (string) $order['fulfillment_status'],
                $lines,
                self::pickupReady($orderId)
            );

            if ($target !== (string) $order['fulfillment_status']) {
                Lifecycle::fulfill($orderId, $target, [
                    'source' => (string) ($options['source'] ?? 'user'),
                    'user_id' => (int) ($options['user_id'] ?? 0),
                ]);
            }
        });
    }

    /**
     * Scrive al cliente che la spedizione è partita o il ritiro è pronto.
     *
     * Non lancia mai: la merce si è già mossa, e un'email che non parte (niente
     * indirizzo, posta che rifiuta) la registra `Mailer` come per ogni altra.
     */
    private static function notify(int $shipmentId, string $key): void
    {
        try {
            $shipment = Shipment::findById($shipmentId);

            if (!is_array($shipment) || $shipment === []) {
                return;
            }

            $extra = [];

            if ($key === 'shipped') {
                $carrier = (int) ($shipment['carrier_id'] ?? 0) > 0 ? Carrier::findById((int) $shipment['carrier_id']) : null;
                $extra = [
                    'carrier' => is_array($carrier) ? (string) ($carrier['name'] ?? '') : '',
                    'tracking' => (string) ($shipment['tracking_number'] ?? ''),
                    'url' => (string) ($shipment['tracking_url'] ?? ''),
                ];
            } else {
                $extra = ['location' => self::placeOf((int) ($shipment['location_id'] ?? 0))];
            }

            OrderNotifier::send($key, (int) $shipment['order_id'], $extra);
        } catch (Throwable) {
            // Vedi sopra: l'esito della posta non decide l'esito della spedizione.
        }
    }

    /** «Nome, via numero, cap città» della sede di ritiro, quel che c'è. */
    private static function placeOf(int $locationId): string
    {
        $location = $locationId > 0 ? Location::findById($locationId) : null;
        $place = is_array($location) && $location !== []
            ? SocietyLocations::find((int) $location['society_location_id'])
            : null;

        if (!is_object($place)) {
            return '';
        }

        $parts = [
            trim((string) ($place->label ?? '')),
            trim(trim((string) ($place->street ?? '')).' '.trim((string) ($place->number ?? ''))),
            trim(trim((string) ($place->cap ?? '')).' '.trim((string) ($place->city ?? ''))),
        ];

        return implode(', ', array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    private static function guardFeature(): void
    {
        if (!Gestionale::feature('shipping')) {
            throw UserError::make('shipment.feature_off');
        }
    }

    /** L'ordine di una spedizione (lettura senza blocco, serve per bloccare nell'ordine giusto). */
    private static function orderOf(int $shipmentId): int
    {
        $shipment = $shipmentId > 0 ? Shipment::findById($shipmentId) : null;

        if (!is_array($shipment) || $shipment === [] || (string) ($shipment['deleted'] ?? 'false') === 'true') {
            throw UserError::make('shipment.not_found');
        }

        return (int) $shipment['order_id'];
    }

    /** @return array<string, mixed> */
    private static function lockOrder(int $orderId): array
    {
        $order = Order::findForUpdate(['id' => $orderId], 1);

        if (!is_array($order) || $order === []) {
            throw UserError::make('order.not_found');
        }

        return $order;
    }

    /** @return array<string, mixed> */
    private static function lock(int $shipmentId): array
    {
        $shipment = Shipment::findForUpdate(['id' => $shipmentId], 1);

        if (!is_array($shipment) || $shipment === []) {
            throw UserError::make('shipment.not_found');
        }

        return $shipment;
    }

    /**
     * Vettore, tracking, link e data di partenza: quelli dati, altrimenti
     * quelli già sulla spedizione.
     *
     * @param array<string, mixed> $shipment
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     * @throws UserError
     */
    private static function shippingValues(array $shipment, array $data): array
    {
        $carrierId = array_key_exists('carrier_id', $data) && (int) $data['carrier_id'] > 0
            ? (int) $data['carrier_id']
            : (int) $shipment['carrier_id'];
        $carrier = $carrierId > 0 ? Carrier::findById($carrierId) : null;

        if (!is_array($carrier) || $carrier === []) {
            $carrier = null;
            $carrierId = 0;
        }

        $tracking = array_key_exists('tracking_number', $data)
            ? trim((string) $data['tracking_number'])
            : trim((string) $shipment['tracking_number']);
        $url = $carrier !== null ? Carriers::trackingUrl($carrier, $tracking) : '';

        if ($carrier !== null && trim((string) ($carrier['tracking_url_template'] ?? '')) !== '' && $tracking === '') {
            throw UserError::make('shipment.tracking_required');
        }

        $when = trim((string) ($data['shipped_at'] ?? ''));

        if ($when === '') {
            $shippedAt = date('Y-m-d H:i:s');
        } else {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $when) ?: \DateTimeImmutable::createFromFormat('!d/m/Y', $when);

            if ($date === false || ($date->format('Y-m-d') !== $when && $date->format('d/m/Y') !== $when)) {
                throw UserError::make('shipment.bad_date');
            }

            $shippedAt = $date->format('Y-m-d') . ' 00:00:00';
        }

        return [
            'carrier_id' => $carrierId,
            'tracking_number' => $tracking,
            'tracking_url' => $url,
            'shipped_at' => $shippedAt,
        ];
    }

    /**
     * Scrive i valori e la riga di storico del passaggio di stato.
     *
     * @param array<string, mixed> $shipment la riga com'era
     * @param array<string, mixed> $values
     * @param array<string, mixed> $options
     */
    private static function write(array $shipment, array $values, string $to, array $options): void
    {
        Shipment::update($values, (int) $shipment['id']);

        StatusLogger::record(
            ShipmentStatusLog::class,
            (int) $shipment['id'],
            'status',
            (string) $shipment['status'],
            $to,
            (string) ($options['source'] ?? 'user'),
            (int) ($options['user_id'] ?? 0) ?: null
        );
    }

    /** C'è un ritiro pronto che aspetta il cliente? */
    private static function pickupReady(int $orderId): bool
    {
        $rows = self::rows(Shipment::find(['order_id' => $orderId, 'type' => 'pickup', 'status' => 'ready_for_pickup', 'deleted' => 'false']));

        return $rows !== [];
    }

    /**
     * Le quantità chieste, pulite: le righe vuote o a zero si tolgono.
     *
     * @param array<int, mixed> $quantities
     * @return array<int, float>
     */
    private static function wanted(array $quantities): array
    {
        $wanted = [];

        foreach ($quantities as $itemId => $quantity) {
            $number = Numbers::fromForm($quantity);

            if ((int) $itemId <= 0 || $number === null || (float) $number <= 0) {
                continue;
            }

            $wanted[(int) $itemId] = round((float) $number, 3);
        }

        return $wanted;
    }

    /**
     * Le righe dell'ordine con il dato che manca per decidere se si spediscono.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private static function describe(array $items): array
    {
        $products = [];
        $models = [];

        foreach ($items as &$item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $products[$productId] ??= $productId > 0 ? Product::findById($productId) : null;
            $modelId = is_array($products[$productId]) ? (int) ($products[$productId]['product_model_id'] ?? 0) : 0;
            $models[$modelId] ??= $modelId > 0 ? ProductModel::findById($modelId) : null;

            // Un articolo che non si trova più resta spedibile: la riga dell'ordine è una copia.
            $item['requires_shipping'] = !is_array($models[$modelId])
                || (string) ($models[$modelId]['requires_shipping'] ?? 'true') === 'true';
        }

        return $items;
    }

    /** @return list<array<string, mixed>> */
    private static function items(int $orderId, bool $lock = false): array
    {
        $where = ['order_id' => $orderId, 'deleted' => 'false'];
        $rows = $lock ? OrderItem::findForUpdate($where) : OrderItem::find($where);

        return self::rows($rows);
    }

    /**
     * Le quantità già in spedizioni che occupano la merce, per riga d'ordine.
     * Con `$onlyCounted` solo quelle che contano come evase.
     *
     * @return array<int, float>
     */
    private static function assigned(int $orderId, bool $lock = false, bool $onlyCounted = false): array
    {
        $where = ['order_id' => $orderId, 'deleted' => 'false'];
        $shipments = self::rows($lock ? Shipment::findForUpdate($where) : Shipment::find($where));
        $assigned = [];

        foreach ($shipments as $shipment) {
            if (in_array((string) $shipment['status'], self::RELEASED, true)) {
                continue;
            }

            if ($onlyCounted && !self::counts($shipment)) {
                continue;
            }

            $where = ['shipment_id' => (int) $shipment['id'], 'deleted' => 'false'];

            foreach (self::rows($lock ? ShipmentItem::findForUpdate($where) : ShipmentItem::find($where)) as $row) {
                $itemId = (int) $row['order_item_id'];
                $assigned[$itemId] = round(($assigned[$itemId] ?? 0.0) + (float) $row['quantity'], 3);
            }
        }

        return $assigned;
    }

    /** Una spedizione conta come evasa solo da `in_transit` in poi (un ritiro, da `picked_up`). */
    private static function counts(array $shipment): bool
    {
        return in_array(
            (string) $shipment['status'],
            (string) $shipment['type'] === 'pickup' ? ShipmentFlow::PICKUP_COUNTED : ShipmentFlow::DELIVERY_COUNTED,
            true
        );
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $found): array
    {
        if (!is_array($found) || $found === []) {
            return [];
        }

        $rows = array_key_exists('id', $found) ? [$found] : array_values(array_filter($found, 'is_array'));
        usort($rows, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);

        return $rows;
    }
}
