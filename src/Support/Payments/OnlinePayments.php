<?php

namespace Wonder\Plugin\Gestionale\Support\Payments;

use RuntimeException;
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Providers\Payments\PaymentStart;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Orders\Lifecycle;

/**
 * Il denaro che passa da un gateway: l'avvio dell'intento e quello che il
 * gateway racconta dopo. Webhook, pagina di ritorno e riallineamento passano
 * tutti da qui, così i controlli sono gli stessi da qualunque parte arrivi
 * la notizia.
 */
final class OnlinePayments
{
    /** L'ultima riga di pagamento online dell'ordine. @return array<string, mixed>|null */
    public static function payment(int $orderId): ?array
    {
        $found = Payment::find(['order_id' => $orderId, 'type' => 'payment', 'deleted' => 'false']);
        $rows = is_array($found) && isset($found['id']) ? [$found] : array_values(array_filter((array) $found, 'is_array'));
        $online = array_values(array_filter($rows, static fn (array $row): bool => (string) $row['provider'] !== 'manual'));

        return $online === [] ? null : end($online);
    }

    /**
     * Crea o riusa l'intento dell'ordine e lo lega alla riga aperta al
     * checkout. Il riuso lo decide il fornitore, che vede lo stato dell'intento.
     */
    public static function start(int $orderId): PaymentStart
    {
        $order = Order::findById($orderId);

        if (!is_array($order) || (string) ($order['status'] ?? '') !== 'pending') {
            throw new RuntimeException("Ordine {$orderId} non più da pagare.");
        }

        $payment = self::payment($orderId);

        if ($payment === null || !in_array((string) $payment['status'], ['pending', 'failed'], true)) {
            throw new RuntimeException("Ordine {$orderId} senza un pagamento online aperto.");
        }

        $provider = PaymentProviders::get((string) $payment['provider']);

        if ($provider === null || !$provider->connected()) {
            throw new RuntimeException('Pagamento online non collegato.');
        }

        $start = $provider->start($order, $payment);
        Ledger::attach((int) $payment['id'], $provider->code(), $start->reference, $start->environment);

        return $start;
    }

    /**
     * Il gateway ha incassato: si controlla che il denaro sia proprio
     * dell'ordine, poi si registra e si conferma.
     *
     * L'incasso si scrive prima della conferma e per conto suo: se la
     * conferma cade, il denaro resta registrato e il prossimo passaggio
     * (rinvio del webhook, riallineamento) trova la riga già pagata e
     * conferma soltanto.
     *
     * @return array<string, mixed> l'esito di `Lifecycle::confirm`
     */
    public static function succeeded(string $provider, string $reference, int $amount, string $currency, int $orderId, string $source): array
    {
        $row = Ledger::byReference($provider, $reference);

        if ($row === null) {
            throw new PaymentMismatch("Intento {$reference} senza un pagamento nel gestionale.");
        }

        $rowOrder = (int) $row['order_id'];
        $order = Order::findById($rowOrder);

        if ($orderId !== $rowOrder || !is_array($order)) {
            throw new PaymentMismatch("Intento {$reference}: ordine {$orderId} al posto di {$rowOrder}.");
        }

        if ($amount !== (int) round((float) $row['amount'] * 100)) {
            throw new PaymentMismatch("Intento {$reference}: {$amount} centesimi al posto di ".(int) round((float) $row['amount'] * 100).'.');
        }

        $expected = (string) ($order['currency'] ?? '') ?: 'EUR';

        if (strtolower($currency) !== strtolower($expected)) {
            throw new PaymentMismatch("Intento {$reference}: valuta {$currency} al posto di {$expected}.");
        }

        Ledger::register([
            'order_id' => $rowOrder,
            'amount' => $amount / 100,
            'currency' => $expected,
            'provider' => $provider,
            'provider_reference' => $reference,
            'source' => $source,
        ]);

        if ((string) $order['status'] === 'cancelled') {
            // Il cliente ha pagato un ordine già annullato: il denaro va
            // restituito dalla dashboard del gateway.
            Errors::report($provider, 'payment.cancelled_order', "Incasso {$reference} sull'ordine annullato {$rowOrder}: va rimborsato.", ['order_id' => $rowOrder]);

            return ['order_id' => $rowOrder, 'status' => 'cancelled', 'changed' => false];
        }

        return Lifecycle::confirm($rowOrder, [
            'provider' => $provider,
            'provider_reference' => $reference,
            'amount' => $amount / 100,
            'source' => $source,
            'merchant_notice' => true,
        ]);
    }

    /**
     * Il tentativo non è andato: la riga si segna fallita e l'ordine resta in
     * attesa. L'intento resta riusabile: il cliente riprova sullo stesso.
     */
    public static function fail(string $provider, string $reference, string $message): void
    {
        $row = Ledger::byReference($provider, $reference);

        if ($row === null || (string) $row['status'] === 'paid') {
            // Un fallimento in ritardo dopo l'incasso non toglie il denaro.
            return;
        }

        Ledger::fail((int) $row['id'], $message);
    }

    /**
     * I rimborsi fatti dalla dashboard del gateway. Il riferimento di ognuno
     * (`re_…`) rende la scrittura idempotente.
     *
     * @param list<array{id: string, amount: int, status: string}> $refunds
     */
    public static function refunded(string $provider, string $reference, array $refunds, string $currency, string $source): int
    {
        $row = Ledger::byReference($provider, $reference);

        if ($row === null) {
            throw new PaymentMismatch("Rimborso sull'intento {$reference}, che il gestionale non conosce.");
        }

        $written = 0;

        foreach ($refunds as $refund) {
            if (in_array((string) $refund['status'], ['failed', 'canceled'], true) || (int) $refund['amount'] <= 0) {
                continue;
            }

            Ledger::refund([
                'order_id' => (int) $row['order_id'],
                'amount' => (int) $refund['amount'] / 100,
                'currency' => strtoupper($currency) ?: 'EUR',
                'provider' => $provider,
                'provider_reference' => (string) $refund['id'],
                'source' => $source,
            ]);
            ++$written;
        }

        return $written;
    }
}
