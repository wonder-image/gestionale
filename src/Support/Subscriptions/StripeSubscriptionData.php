<?php

namespace Wonder\Plugin\Gestionale\Support\Subscriptions;

/**
 * Quello che serve al gestionale, letto dagli oggetti di Stripe. Classe pura.
 *
 * La forma degli oggetti dipende dalla versione dell'API dell'endpoint del
 * webhook, non da quella dell'SDK: dalla famiglia «basil» il periodo sta sulla
 * voce dell'abbonamento (`items.data[0].current_period_*`) e il legame fattura
 * → abbonamento sotto `parent.subscription_details`; prima stavano in cima.
 * Qui si leggono tutte e due le forme.
 */
final class StripeSubscriptionData
{
    /** Stato di Stripe → stato del gestionale. */
    public const STATUSES = [
        'incomplete' => 'pending',
        'incomplete_expired' => 'cancelled',
        'trialing' => 'trialing',
        'active' => 'active',
        'past_due' => 'past_due',
        'unpaid' => 'unpaid',
        'canceled' => 'cancelled',
        // In pausa a fine prova senza carta: il cliente non ha più il servizio.
        'paused' => 'unpaid',
    ];

    /**
     * I campi del gestionale da un oggetto `subscription` di Stripe. Le date
     * sono `Y-m-d H:i:s` o `null` quando Stripe non le dà.
     *
     * `items` sono le voci dell'abbonamento, una per riga del gestionale
     * (`item_id` = la riga, dai metadati della voce; 0 se non c'è). Periodo e
     * prezzo in alto sono quelli della voce che rinnova prima.
     *
     * @param array<string, mixed> $object
     * @return array{reference: string, local_id: int, status: string, price: string, customer: string, current_period_start: ?string, current_period_end: ?string, trial_ends_at: ?string, started_at: ?string, cancelled_at: ?string, ended_at: ?string, auto_renew: bool, closed_for_payment: bool, items: list<array{id: string, price: string, quantity: int, item_id: int, period_start: ?string, period_end: ?string}>}
     */
    public static function subscription(array $object, bool $deleted = false): array
    {
        $raw = is_array($object['items']['data'] ?? null) ? array_values(array_filter($object['items']['data'], 'is_array')) : [];
        $item = $raw[0] ?? [];

        foreach ($raw as $candidate) {
            if ((int) ($candidate['current_period_end'] ?? 0) > 0
                && ((int) ($item['current_period_end'] ?? 0) <= 0 || (int) $candidate['current_period_end'] < (int) $item['current_period_end'])) {
                $item = $candidate;
            }
        }

        $status = self::STATUSES[(string) ($object['status'] ?? '')] ?? 'pending';
        $closedForPayment = (string) ($object['cancellation_details']['reason'] ?? '') === 'payment_failed';

        if ($deleted) {
            $status = $closedForPayment ? 'unpaid' : 'cancelled';
        }

        $cancelling = (bool) ($object['cancel_at_period_end'] ?? false) || (int) ($object['cancel_at'] ?? 0) > 0;

        return [
            'reference' => (string) ($object['id'] ?? ''),
            'local_id' => (int) ($object['metadata']['subscription_id'] ?? 0),
            'status' => $status,
            'price' => self::priceOf($item),
            'customer' => is_array($object['customer'] ?? null) ? (string) ($object['customer']['id'] ?? '') : (string) ($object['customer'] ?? ''),
            'current_period_start' => self::date($item['current_period_start'] ?? $object['current_period_start'] ?? null),
            'current_period_end' => self::date($item['current_period_end'] ?? $object['current_period_end'] ?? null),
            'trial_ends_at' => self::date($object['trial_end'] ?? null),
            'started_at' => self::date($object['start_date'] ?? null),
            'cancelled_at' => self::date($object['canceled_at'] ?? null),
            'ended_at' => self::date($object['ended_at'] ?? null),
            'auto_renew' => !$cancelling && !in_array($status, ['cancelled', 'unpaid'], true),
            'closed_for_payment' => $closedForPayment,
            'items' => array_map(static fn (array $one): array => [
                'id' => (string) ($one['id'] ?? ''),
                'price' => self::priceOf($one),
                'quantity' => max(1, (int) ($one['quantity'] ?? 1)),
                'item_id' => (int) ($one['metadata']['item_id'] ?? 0),
                'period_start' => self::date($one['current_period_start'] ?? null),
                'period_end' => self::date($one['current_period_end'] ?? null),
            ], $raw),
        ];
    }

    /**
     * Il prezzo di una voce, oggetto o id.
     *
     * @param array<string, mixed> $item
     */
    private static function priceOf(array $item): string
    {
        $price = $item['price'] ?? null;

        return is_array($price) ? (string) ($price['id'] ?? '') : (string) ($price ?? '');
    }

    /**
     * L'abbonamento Stripe di una fattura, nelle due forme.
     *
     * @param array<string, mixed> $invoice
     */
    public static function invoiceSubscription(array $invoice): string
    {
        $old = $invoice['subscription'] ?? null;

        if (is_string($old) && $old !== '') {
            return $old;
        }

        if (is_array($old) && isset($old['id'])) {
            return (string) $old['id'];
        }

        $new = $invoice['parent']['subscription_details']['subscription'] ?? null;

        return is_array($new) ? (string) ($new['id'] ?? '') : (string) ($new ?? '');
    }

    /**
     * L'id del nostro abbonamento scritto nei metadati della fattura (copiati
     * da quelli dell'abbonamento), nelle due forme; 0 se non c'è.
     *
     * @param array<string, mixed> $invoice
     */
    public static function invoiceLocalId(array $invoice): int
    {
        return (int) ($invoice['parent']['subscription_details']['metadata']['subscription_id']
            ?? $invoice['subscription_details']['metadata']['subscription_id']
            ?? 0);
    }

    /**
     * Importo, valuta e momento del pagamento di una fattura pagata.
     *
     * @param array<string, mixed> $invoice
     * @return array{amount: float, currency: string, paid_at: string}
     */
    public static function invoicePayment(array $invoice): array
    {
        $paidAt = $invoice['status_transitions']['paid_at'] ?? $invoice['created'] ?? null;

        return [
            'amount' => round((int) ($invoice['amount_paid'] ?? 0) / 100, 2),
            'currency' => strtoupper((string) ($invoice['currency'] ?? 'eur')),
            'paid_at' => self::date($paidAt) ?? date('Y-m-d H:i:s'),
        ];
    }

    /** Un timestamp di Stripe come data del database; `null` se non c'è. */
    public static function date(mixed $timestamp): ?string
    {
        $timestamp = (int) $timestamp;

        return $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : null;
    }
}
