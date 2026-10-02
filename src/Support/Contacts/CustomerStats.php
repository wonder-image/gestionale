<?php

namespace Wonder\Plugin\Gestionale\Support\Contacts;

use Wonder\Plugin\Gestionale\Support\Orders\OrderLines;

/**
 * Qualche numero semplice su un cliente, per la sua scheda.
 *
 * Non legge il database: la Resource porta gli ordini e le righe dei carrelli,
 * qui si contano. Un ordine annullato o rimborsato per intero non è speso: lo
 * si conta a parte, perché sapere quanti ne ha annullati dice qualcosa. Un
 * rimborso parziale lascia l'ordine fra gli spesi per il suo totale: il
 * dettaglio dei rimborsi sta nei pagamenti dell'ordine.
 */
final class CustomerStats
{
    /** Gli stati del pagamento in cui il denaro deve ancora arrivare. */
    private const TO_PAY = ['unpaid', 'pending', 'partially_paid'];

    /**
     * @param list<array<string, mixed>> $orders   righe di `gst_orders`, di ogni stage
     * @param list<array<string, mixed>> $cartRows righe di `gst_order_items` dei suoi carrelli
     * @return array{orders: int, cancelled: int, spent: float, average: float, first_order: string, last_order: string, to_pay: int, cart_items: int, cart_value: float}
     */
    public static function of(array $orders, array $cartRows): array
    {
        $stats = [
            'orders' => 0, 'cancelled' => 0, 'spent' => 0.0, 'average' => 0.0,
            'first_order' => '', 'last_order' => '', 'to_pay' => 0,
            'cart_items' => 0, 'cart_value' => 0.0,
        ];

        foreach ($orders as $order) {
            if (!is_array($order) || (string) ($order['stage'] ?? '') !== 'order') {
                continue;
            }

            $status = (string) ($order['status'] ?? '');
            $payment = (string) ($order['payment_status'] ?? '');

            if ($status === 'cancelled' || $payment === 'refunded') {
                $stats['cancelled']++;

                continue;
            }

            $stats['orders']++;
            $stats['spent'] = round($stats['spent'] + (float) ($order['total'] ?? 0), 2);

            if (in_array($payment, self::TO_PAY, true)) {
                $stats['to_pay']++;
            }

            $when = static::dateOf($order);

            if ($when !== '') {
                if ($stats['first_order'] === '' || $when < $stats['first_order']) {
                    $stats['first_order'] = $when;
                }

                if ($when > $stats['last_order']) {
                    $stats['last_order'] = $when;
                }
            }
        }

        if ($stats['orders'] > 0) {
            $stats['average'] = round($stats['spent'] / $stats['orders'], 2);
        }

        foreach (OrderLines::sold($cartRows) as $row) {
            if ((string) ($row['type'] ?? 'product') === 'text') {
                continue;
            }

            $stats['cart_items'] += (int) round((float) ($row['quantity'] ?? 0));
            $stats['cart_value'] = round($stats['cart_value'] + (float) ($row['line_total'] ?? 0), 2);
        }

        return $stats;
    }

    /** La data dell'ordine; per le righe senza `ordered_at` quella di creazione. */
    private static function dateOf(array $order): string
    {
        foreach (['ordered_at', 'creation'] as $key) {
            $value = trim((string) ($order[$key] ?? ''));

            if ($value !== '' && !str_starts_with($value, '0000')) {
                return $value;
            }
        }

        return '';
    }
}
