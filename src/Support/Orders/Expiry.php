<?php

namespace Wonder\Plugin\Gestionale\Support\Orders;

use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderStatusLog;
use Wonder\Plugin\Gestionale\Models\Stock\StockReservation;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Status\StatusLogger;
use Wonder\Plugin\Gestionale\Support\Stock\Allocation;
use Throwable;

/**
 * Il giro che tiene pulito il magazzino: chi non paga libera la merce.
 *
 * Tre cose, in quest'ordine. Le prenotazioni scadute si liberano — la carta
 * abbandonata dopo mezz'ora, il bonifico che non arriva. A metà dell'attesa
 * parte un promemoria, una volta sola. Alla fine dell'attesa l'ordine si
 * annulla, e per annullarlo si passa da `Lifecycle::cancel()` come tutti gli
 * altri: un annullamento scritto qui a mano lascerebbe la storia dell'ordine
 * diversa da quella di un annullamento fatto dal backend.
 *
 * Il lock non lo mette questa classe: il `Worker` dello scheduler avvolge ogni
 * attività in `NamedLock`, e due giri non si sovrappongono.
 */
final class Expiry
{
    /** Il campo con cui si annota nella storia che il promemoria è partito. */
    public const REMINDER_FIELD = 'payment_reminder';

    /** Gli stati in cui un ordine aspetta ancora il denaro. */
    private const WAITING = ['pending'];

    /**
     * @return array{released: int, reminded: int, cancelled: int, orders: list<int>}
     */
    public static function run(?string $now = null): array
    {
        $now ??= date('Y-m-d H:i:s');
        $settings = Setting::current();
        $days = (int) ($settings['order_payment_wait_days'] ?? 7);
        $result = ['released' => 0, 'reminded' => 0, 'cancelled' => 0, 'orders' => []];

        foreach (self::expiredOrders($now) as $orderId) {
            try {
                $order = Order::findById($orderId);

                if (!is_array($order) || !in_array((string) $order['status'], self::WAITING, true)) {
                    continue;
                }

                $result['released'] += Allocation::expire($orderId);
            } catch (Throwable $error) {
                Errors::internal($error, 'expiry.release', ['order_id' => $orderId]);
            }
        }

        foreach (self::waitingOrders() as $order) {
            $orderId = (int) $order['id'];

            // Un ordine che non si riesce a chiudere non deve tenere fermi
            // gli altri: resta dov'è, finisce nel log, e il giro dopo riprova.
            try {
                self::deadline($order, $days, $now, $result);
            } catch (Throwable $error) {
                Errors::internal($error, 'expiry.order', ['order_id' => $orderId]);
            }
        }

        return $result;
    }

    /**
     * Un ordine in attesa: il promemoria a metà, l'annullamento alla fine.
     *
     * @param array<string, mixed> $order
     * @param array{released: int, reminded: int, cancelled: int, orders: list<int>} $result
     */
    private static function deadline(array $order, int $days, string $now, array &$result): void
    {
        $orderId = (int) $order['id'];
        $ordered = strtotime((string) $order['ordered_at']);

        if ($ordered === false || $days <= 0) {
            return;
        }

        $deadline = $ordered + $days * 86400;
        $moment = strtotime($now);

        if ($moment >= $deadline) {
            Lifecycle::cancel($orderId, [
                'reason' => 'Pagamento non ricevuto entro il termine',
                'source' => 'cron',
                'merchant_notice' => true,
            ]);
            ++$result['cancelled'];
            $result['orders'][] = $orderId;

            return;
        }

        if ($moment >= $ordered + (int) floor($days * 86400 / 2) && !self::reminded($orderId)) {
            OrderNotifier::send('reminder', $orderId, [
                'deadline' => date('Y-m-d H:i:s', $deadline),
            ]);
            StatusLogger::record(
                OrderStatusLog::class,
                $orderId,
                self::REMINDER_FIELD,
                '',
                'sent',
                'cron'
            );
            ++$result['reminded'];
        }
    }

    /**
     * Gli ordini con almeno una prenotazione scaduta e mai rilasciata.
     *
     * @return list<int>
     */
    private static function expiredOrders(string $now): array
    {
        $rows = StockReservation::find(
            "released_at IS NULL AND expires_at IS NOT NULL AND expires_at <= '".addslashes($now)."'"
            ." AND deleted = 'false'"
        );

        $orders = [];

        foreach (self::rows($rows) as $row) {
            $orderId = (int) ($row['order_id'] ?? 0);

            if ($orderId > 0) {
                $orders[$orderId] = true;
            }
        }

        return array_map('intval', array_keys($orders));
    }

    /**
     * Gli ordini che stanno ancora aspettando il denaro.
     *
     * @return list<array<string, mixed>>
     */
    private static function waitingOrders(): array
    {
        return self::rows(Order::find([
            'stage' => 'order',
            'status' => 'pending',
            'deleted' => 'false',
        ]));
    }

    /** Il promemoria di questo ordine è già partito? */
    private static function reminded(int $orderId): bool
    {
        $row = OrderStatusLog::find([
            'order_id' => $orderId,
            'field' => self::REMINDER_FIELD,
            'deleted' => 'false',
        ], 1);

        return is_array($row) && $row !== [];
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
