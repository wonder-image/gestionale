<?php

namespace Wonder\Plugin\Gestionale\Support\Payments;

/**
 * Quanto è pagato un ordine, letto dalle sue righe di denaro.
 *
 * Il `payment_status` non si scrive mai a mano: lo si ricalcola da qui dopo
 * ogni riga nuova, ed è `Ledger` a salvarlo. Così un rimborso registrato a
 * mano e uno arrivato dal gateway lasciano l'ordine nello stesso stato.
 *
 * Contano solo le righe `paid`: una in attesa dice soltanto che qualcuno ha
 * cominciato a pagare, una fallita non dice niente. Il verso lo decide il
 * tipo, non il segno dell'importo: certi gateway mandano i rimborsi col meno
 * davanti e non devono ribaltare il conto.
 *
 * Pura: prende array, non tocca il database.
 */
final class PaymentStatus
{
    /**
     * Incassato, rimborsato e in attesa, in euro.
     *
     * @param list<array<string, mixed>> $payments righe di `gst_payments`
     * @return array{paid: float, refunded: float, pending: float}
     */
    public static function sums(array $payments): array
    {
        $sums = ['paid' => 0.0, 'refunded' => 0.0, 'pending' => 0.0];

        foreach ($payments as $payment) {
            if (!is_array($payment)) {
                continue;
            }

            $amount = abs(round((float) ($payment['amount'] ?? 0), 2));
            $isRefund = (string) ($payment['type'] ?? 'payment') === 'refund';
            $status = (string) ($payment['status'] ?? 'pending');

            if ($status === 'paid') {
                $key = $isRefund ? 'refunded' : 'paid';
                $sums[$key] = round($sums[$key] + $amount, 2);
            } elseif ($status === 'pending' && !$isRefund) {
                $sums['pending'] = round($sums['pending'] + $amount, 2);
            }
        }

        return $sums;
    }

    /**
     * Uno dei valori di `Order::PAYMENT_STATUSES`.
     *
     * @param list<array<string, mixed>> $payments righe di `gst_payments`
     */
    public static function of(float $total, array $payments): string
    {
        $sums = self::sums($payments);
        $total = round($total, 2);

        // Un rimborso ha la precedenza su tutto: dice com'è finita.
        if ($sums['refunded'] > 0) {
            return $sums['refunded'] >= $sums['paid'] ? 'refunded' : 'partially_refunded';
        }

        if ($sums['paid'] <= 0) {
            // Un ordine che non costa niente è pagato appena nasce, altrimenti
            // resterebbe da incassare per sempre.
            if ($total <= 0) {
                return 'paid';
            }

            return $sums['pending'] > 0 ? 'pending' : 'unpaid';
        }

        return $sums['paid'] >= $total ? 'paid' : 'partially_paid';
    }
}
