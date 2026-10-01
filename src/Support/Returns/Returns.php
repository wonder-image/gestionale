<?php

namespace Wonder\Plugin\Gestionale\Support\Returns;

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Sales\SalesReturn;
use Wonder\Plugin\Gestionale\Models\Sales\SalesReturnItem;
use Wonder\Plugin\Gestionale\Models\Sales\SalesReturnStatusLog;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Documents\DocumentSequences;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Status\StatusLogger;
use Wonder\Plugin\Gestionale\Support\Stock\Allocation;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Sql\Transaction;

/**
 * I resi di un ordine: registrarli, chiuderli, annullarli.
 *
 * Il reso nasce `received` — la merce è già in mano al commerciante — e il
 * rientro a magazzino passa da `Allocation::returnGoods()`, riga per riga, solo
 * per quelle con la spunta. Il rimborso del denaro non è qui: lo fa il gateway.
 * Tutto dentro una transazione e con l'ordine bloccato: due invii contemporanei
 * si mettono in fila e il secondo non può rendere più di quanto resta.
 */
final class Returns
{
    /** Gli stati in cui un reso non conta più sulla quantità resa. */
    private const NOT_COUNTED = ['cancelled', 'rejected'];

    /**
     * Quanto è già stato reso di una riga d'ordine, resi annullati esclusi.
     *
     * Dentro una transazione blocca le righe (chi registra o annulla deve
     * vedere un conteggio che non cambia); fuori, per la sola lettura della
     * pagina, legge e basta.
     */
    public static function returned(int $orderItemId): float
    {
        $total = 0.0;
        $statuses = [];
        $where = ['order_item_id' => $orderItemId, 'deleted' => 'false'];
        $found = Transaction::active() ? SalesReturnItem::findForUpdate($where) : SalesReturnItem::find($where);

        foreach (self::rows($found) as $item) {
            $returnId = (int) $item['sales_return_id'];
            $statuses[$returnId] ??= (string) (SalesReturn::findById($returnId)['status'] ?? 'cancelled');

            if (!in_array($statuses[$returnId], self::NOT_COUNTED, true)) {
                $total += (float) $item['quantity'];
            }
        }

        return round($total, 3);
    }

    /**
     * Le righe prodotto di un ordine con quanto se ne può ancora rendere.
     *
     * @return list<array{order_item_id: int, name: string, ordered: float, returned: float, max: float}>
     */
    public static function lines(int $orderId): array
    {
        $lines = [];

        foreach (self::productItems($orderId) as $item) {
            $ordered = round((float) $item['quantity'], 3);
            $returned = self::returned((int) $item['id']);

            $lines[] = [
                'order_item_id' => (int) $item['id'],
                'name' => (string) $item['name'],
                'ordered' => $ordered,
                'returned' => $returned,
                'max' => ReturnRules::returnable($ordered, $returned),
            ];
        }

        return $lines;
    }

    /**
     * Registra un reso.
     *
     * @param list<array{order_item_id: int, quantity: mixed, reason: string, restock?: ?bool, note?: string}> $lines
     *     quantità vuota o zero = riga non resa; `restock` null = quello proposto dal motivo
     * @param array{location_id?: int, customer_note?: string, internal_note?: string, source?: string, user_id?: int} $options
     * @return array{return_id: int, number: string, restocked: int, kept: int}
     */
    public static function register(int $orderId, array $lines, array $options = []): array
    {
        return Transaction::run(static function () use ($orderId, $lines, $options): array {
            $order = Order::findForUpdate(['id' => $orderId], 1);

            if (!is_array($order) || $order === []) {
                throw UserError::make('order.not_found');
            }

            if (!ReturnRules::eligibleOrder($order, ['returns' => Gestionale::feature('returns')])) {
                throw UserError::make('return.order_not_returnable');
            }

            $items = [];

            foreach (self::productItems($orderId) as $item) {
                $items[(int) $item['id']] = $item;
            }

            $wanted = self::wanted($lines, $items);

            if ($wanted === []) {
                throw UserError::make('return.nothing_selected');
            }

            $source = StatusLogger::normalizeSource((string) ($options['source'] ?? 'user'));
            $userId = (int) ($options['user_id'] ?? 0);
            $locationId = (int) ($options['location_id'] ?? 0) ?: Locations::mainId();
            $now = date('Y-m-d H:i:s');
            $number = DocumentSequences::next('sales_return');

            $created = SalesReturn::create([
                'code' => Code::make(SalesReturn::class, Codes::SALES_RETURN),
                'number' => $number,
                'order_id' => $orderId,
                'customer_id' => (int) ($order['customer_id'] ?? 0),
                'channel' => 'office',
                'status' => 'received',
                'location_id' => $locationId,
                'received_at' => $now,
                'customer_note' => trim((string) ($options['customer_note'] ?? '')),
                'internal_note' => trim((string) ($options['internal_note'] ?? '')),
                'user_id' => $userId,
            ]);
            $returnId = (int) ($created->insert_id ?? 0);
            $restocked = 0;
            $kept = 0;

            foreach ($wanted as $want) {
                $item = $items[$want['order_item_id']];

                SalesReturnItem::create([
                    'sales_return_id' => $returnId,
                    'order_item_id' => $want['order_item_id'],
                    'quantity' => number_format($want['quantity'], 3, '.', ''),
                    'reason' => $want['reason'],
                    'restock' => $want['restock'] ? 'true' : 'false',
                    'note' => $want['note'],
                ]);

                $done = Allocation::returnGoods([
                    'product_id' => (int) $item['product_id'],
                    'quantity' => $want['quantity'],
                    'location_id' => $locationId,
                    'sales_return_id' => $returnId,
                    'restock' => $want['restock'],
                    'source' => $source,
                    'user_id' => $userId,
                    'note' => 'Reso '.$number,
                ]);

                $done['restocked'] ? $restocked++ : $kept++;
            }

            self::log($returnId, '', 'received', $source, $userId);

            return ['return_id' => $returnId, 'number' => $number, 'restocked' => $restocked, 'kept' => $kept];
        });
    }

    /** Chiude un reso ricevuto: il commerciante ha finito con quella pratica. */
    public static function complete(int $returnId, array $options = []): void
    {
        Transaction::run(static function () use ($returnId, $options): void {
            $return = self::locked($returnId);

            if ((string) $return['status'] !== 'received') {
                throw UserError::make('return.cannot_complete');
            }

            SalesReturn::update(['status' => 'completed', 'completed_at' => date('Y-m-d H:i:s')], $returnId);
            self::log($returnId, 'received', 'completed', (string) ($options['source'] ?? 'user'), (int) ($options['user_id'] ?? 0));
        });
    }

    /**
     * Annulla un reso ricevuto, purché la merce non sia rientrata a magazzino:
     * un movimento `return` già a scaffale si corregge con una rettifica.
     */
    public static function cancel(int $returnId, array $options = []): void
    {
        Transaction::run(static function () use ($returnId, $options): void {
            $return = self::locked($returnId);

            if ((string) $return['status'] !== 'received') {
                throw UserError::make('return.cannot_cancel');
            }

            foreach (self::rows(SalesReturnItem::findForUpdate(['sales_return_id' => $returnId, 'deleted' => 'false'])) as $item) {
                if ((string) $item['restock'] === 'true') {
                    throw UserError::make('return.already_restocked');
                }
            }

            SalesReturn::update(['status' => 'cancelled'], $returnId);
            self::log($returnId, 'received', 'cancelled', (string) ($options['source'] ?? 'user'), (int) ($options['user_id'] ?? 0));
        });
    }

    /**
     * Le righe chieste, controllate: una sola voce per riga d'ordine, motivo
     * valido, quantità entro il massimo.
     *
     * @param list<array<string, mixed>> $lines
     * @param array<int, array<string, mixed>> $items le righe prodotto dell'ordine, per id
     * @return list<array{order_item_id: int, quantity: float, reason: string, restock: bool, note: string}>
     */
    private static function wanted(array $lines, array $items): array
    {
        $wanted = [];

        foreach ($lines as $line) {
            $itemId = (int) ($line['order_item_id'] ?? 0);
            $text = trim((string) ($line['quantity'] ?? ''));

            if ($text === '' || (is_numeric(str_replace(',', '.', $text)) && (float) str_replace(',', '.', $text) == 0.0)) {
                continue;
            }

            if (!isset($items[$itemId])) {
                throw UserError::make('return.line_not_returnable');
            }

            $name = (string) $items[$itemId]['name'];
            $quantity = ReturnRules::quantityFrom($text);

            if ($quantity === null) {
                throw UserError::make('return.invalid_quantity', ['product' => $name]);
            }

            $reason = (string) ($line['reason'] ?? '');

            if (!in_array($reason, SalesReturnItem::REASONS, true)) {
                throw UserError::make('return.unknown_reason');
            }

            $restock = ($line['restock'] ?? null) === null ? ReturnRules::defaultRestock($reason) : (bool) $line['restock'];

            if (isset($wanted[$itemId])) {
                $wanted[$itemId]['quantity'] = round($wanted[$itemId]['quantity'] + $quantity, 3);
                $wanted[$itemId]['restock'] = $wanted[$itemId]['restock'] && $restock;

                continue;
            }

            $wanted[$itemId] = [
                'order_item_id' => $itemId, 'quantity' => $quantity, 'reason' => $reason,
                'restock' => $restock, 'note' => trim((string) ($line['note'] ?? '')),
            ];
        }

        foreach ($wanted as $itemId => $want) {
            $max = ReturnRules::returnable((float) $items[$itemId]['quantity'], self::returned($itemId));

            if ($want['quantity'] > $max + 0.0005) {
                throw UserError::make('return.over_quantity', [
                    'product' => (string) $items[$itemId]['name'],
                    'max' => rtrim(rtrim(number_format($max, 3, ',', ''), '0'), ','),
                ]);
            }
        }

        return array_values($wanted);
    }

    /** @return list<array<string, mixed>> */
    private static function productItems(int $orderId): array
    {
        return array_values(array_filter(
            self::rows(OrderItem::find(['order_id' => $orderId, 'deleted' => 'false'])),
            static fn (array $item): bool => (string) $item['type'] === 'product' && (int) $item['product_id'] > 0
        ));
    }

    /** @return array<string, mixed> */
    private static function locked(int $returnId): array
    {
        $return = SalesReturn::findForUpdate(['id' => $returnId], 1);

        if (!is_array($return) || $return === [] || (string) ($return['deleted'] ?? 'false') === 'true') {
            throw UserError::make('return.not_found');
        }

        return $return;
    }

    private static function log(int $returnId, string $from, string $to, string $source, int $userId): void
    {
        StatusLogger::record(SalesReturnStatusLog::class, $returnId, 'status', $from, $to, $source, $userId ?: null);
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
