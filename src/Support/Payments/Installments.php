<?php

namespace Wonder\Plugin\Gestionale\Support\Payments;

use DateTimeImmutable;
use RuntimeException;
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentStatusLog;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentTermInstallment;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Subscription;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Status\StatusLogger;
use Wonder\Sql\Transaction;

/**
 * Le rate di un ordine da ufficio (G9.3) o di un periodo di un abbonamento
 * da ufficio (G10 §3): una riga di `gst_payments` in attesa per rata, con la
 * sua scadenza.
 *
 * I conti li fa `InstallmentMath`, che è pura; qui si leggono le rate, si
 * scrive quello che dice e si lascia a `Ledger::sync()` lo stato di pagamento
 * dell'ordine. Una rata è una riga `payment` in attesa **con `due_date`**: le
 * righe aperte dal checkout non hanno scadenza, e qui non si toccano.
 *
 * Ogni scrittura blocca prima l'ordine: due incassi sulla stessa rata, dallo
 * scadenzario e dalla scheda, non si sovrappongono.
 */
final class Installments
{
    /**
     * Alla conferma: una riga in attesa per ogni rata della condizione
     * dell'ordine, per quanto resta da pagare. Senza condizione, una rata sola
     * a vista. La richiesta di pagamento aperta senza scadenza si annulla: la
     * sostituiscono le rate.
     *
     * Ripetuta non scrive niente: un ordine che ha già rate le tiene.
     *
     * @return list<int> gli id delle rate scritte
     */
    public static function create(int $orderId, ?DateTimeImmutable $from = null): array
    {
        $from ??= new DateTimeImmutable('today');

        return Transaction::run(static function () use ($orderId, $from): array {
            $order = self::lockOrder($orderId);

            if (self::pending($orderId) !== []) {
                return [];
            }

            // La richiesta di pagamento ancora aperta (quella del checkout, senza
            // scadenza) la prendono le rate: lasciata lì, il «da pagare» si
            // conterebbe due volte.
            foreach (self::rowsOf($orderId) as $row) {
                if ((string) ($row['type'] ?? '') === 'payment'
                    && (string) ($row['status'] ?? '') === 'pending'
                    && !self::isDate((string) ($row['due_date'] ?? ''))) {
                    self::move((int) $row['id'], 'pending', 'cancelled', 'Sostituita dalle rate');
                }
            }

            $due = self::due($order, self::rowsOf($orderId));
            $schedule = PaymentSchedule::of(self::termRows((int) ($order['payment_term_id'] ?? 0)), $due, $from);
            $ids = [];
            $count = count($schedule);

            foreach ($schedule as $i => $rata) {
                $ids[] = self::write($order, 'order_id', (float) $rata['amount'], (string) $rata['due_date'], 'Rata '.($i + 1).' di '.$count);
            }

            if ($ids !== []) {
                Ledger::sync($orderId);
            }

            return $ids;
        });
    }

    /**
     * *Registra pagamento* su una rata: uguale la chiude, minore lascia il
     * resto con la stessa scadenza, maggiore scala le rate dopo.
     *
     * @param array<string, mixed> $options paid_at, payment_method_id, payment_account_id, reference, source, user_id
     * @return array{payment_id: int, payment_status: string}
     */
    public static function register(int $paymentId, float $amount, array $options = []): array
    {
        $paidAt = Ledger::paidAt($options);

        return Transaction::run(static function () use ($paymentId, $amount, $options, $paidAt): array {
            $row = Payment::findById($paymentId);
            $orderId = is_array($row) ? (int) ($row['order_id'] ?? 0) : 0;
            $subscriptionId = is_array($row) ? (int) ($row['subscription_id'] ?? 0) : 0;

            if ($orderId > 0) {
                return self::apply(['column' => 'order_id', 'id' => $orderId, 'row' => self::lockOrder($orderId)], $paymentId, $amount, $options, $paidAt);
            }

            // Le rate di un abbonamento da ufficio (G10): stessi conti, nessun ordine da allineare.
            if ($subscriptionId > 0) {
                return self::apply(['column' => 'subscription_id', 'id' => $subscriptionId, 'row' => self::lockSubscription($subscriptionId)], $paymentId, $amount, $options, $paidAt);
            }

            throw UserError::make('payment.installment_not_pending');
        });
    }

    /**
     * Il pagamento libero su un ordine con rate: chiude prima la rata che
     * scade prima, e l'eccedenza scala le successive.
     *
     * @param array<string, mixed> $options come `register()`
     * @return array{payment_id: int, payment_status: string}
     */
    public static function registerOnOrder(int $orderId, float $amount, array $options = []): array
    {
        $paidAt = Ledger::paidAt($options);

        return Transaction::run(static function () use ($orderId, $amount, $options, $paidAt): array {
            $order = self::lockOrder($orderId);
            $pending = self::pending($orderId);

            if ($pending === []) {
                throw UserError::make('payment.installment_not_pending');
            }

            return self::apply(['column' => 'order_id', 'id' => $orderId, 'row' => $order], (int) $pending[0]['id'], $amount, $options, $paidAt);
        });
    }

    /**
     * Il totale è cambiato (righe annullate): il «da pagare» si ridistribuisce
     * sulle rate in attesa, in proporzione. Pagato più del nuovo totale: le
     * rate in attesa si annullano, e il rimborso si fa come sempre.
     *
     * @return string il nuovo `payment_status` dell'ordine
     */
    public static function recalculate(int $orderId): string
    {
        return Transaction::run(static function () use ($orderId): string {
            $order = self::lockOrder($orderId);
            $pending = self::pending($orderId);

            if ($pending === []) {
                return Ledger::sync($orderId);
            }

            $paid = PaymentStatus::sums(self::rowsOf($orderId));
            $due = round((float) $order['total'] - ($paid['paid'] - $paid['refunded']), 2);
            $plan = InstallmentMath::redistribute($pending, $due);

            foreach ($plan['update'] as $id => $value) {
                self::changeAmount($id, $value, self::amountOf($pending, $id));
            }

            foreach ($plan['cancel'] as $id) {
                self::move($id, 'pending', 'cancelled', 'Ricalcolo delle rate');
            }

            return Ledger::sync($orderId);
        });
    }

    /**
     * Le rate in attesa dell'ordine, in ordine di scadenza.
     *
     * @return list<array{id: int, amount: float, due_date: string, note: string}>
     */
    public static function pending(int $orderId): array
    {
        return self::pendingOf('order_id', $orderId);
    }

    /**
     * Le rate in attesa di un abbonamento da ufficio (G10), in ordine di scadenza.
     *
     * @return list<array{id: int, amount: float, due_date: string, note: string}>
     */
    public static function pendingForSubscription(int $subscriptionId): array
    {
        return self::pendingOf('subscription_id', $subscriptionId);
    }

    /**
     * Le rate di un periodo di un abbonamento da ufficio (G10 §3): una riga
     * in attesa per rata della condizione di pagamento, dalla data d'inizio
     * del periodo.
     *
     * @param array<string, mixed> $subscription riga di `gst_subscriptions`
     * @return list<int> gli id delle rate scritte
     */
    public static function createForSubscription(array $subscription, float $total, DateTimeImmutable $from, string $label): array
    {
        $schedule = PaymentSchedule::of(self::termRows((int) ($subscription['payment_term_id'] ?? 0)), $total, $from);
        $count = count($schedule);
        $ids = [];

        foreach ($schedule as $i => $rata) {
            $ids[] = self::write($subscription, 'subscription_id', (float) $rata['amount'], (string) $rata['due_date'], $label.($count > 1 ? ', rata '.($i + 1).' di '.$count : ''));
        }

        return $ids;
    }

    /** @return list<array{id: int, amount: float, due_date: string, note: string}> */
    private static function pendingOf(string $column, int $id): array
    {
        $rate = [];

        foreach (self::rowsOf($id, $column) as $row) {
            if ((string) ($row['type'] ?? '') !== 'payment'
                || (string) ($row['status'] ?? '') !== 'pending'
                || !self::isDate((string) ($row['due_date'] ?? ''))) {
                continue;
            }

            $rate[] = [
                'id' => (int) $row['id'],
                'amount' => round((float) $row['amount'], 2),
                'due_date' => substr((string) $row['due_date'], 0, 10),
                'note' => (string) ($row['note'] ?? ''),
            ];
        }

        usort($rate, static fn (array $a, array $b): int => [$a['due_date'], $a['id']] <=> [$b['due_date'], $b['id']]);

        return $rate;
    }

    /** Ha rate in attesa: il registro libero passa da qui, non da `Ledger`. */
    public static function hasPending(int $orderId): bool
    {
        return $orderId > 0 && self::pending($orderId) !== [];
    }

    /**
     * Il pagamento sulla rata, scritto: la rata, il resto, le successive.
     *
     * Il titolare delle rate è un ordine o un abbonamento: `column` dice
     * quale, `row` è la sua riga già bloccata. Lo stato di pagamento si
     * riallinea solo per l'ordine.
     *
     * @param array{column: string, id: int, row: array<string, mixed>} $owner
     * @param array<string, mixed> $options
     * @return array{payment_id: int, payment_status: string}
     */
    private static function apply(array $owner, int $paymentId, float $amount, array $options, string $paidAt): array
    {
        $pending = self::pendingOf($owner['column'], $owner['id']);
        $plan = InstallmentMath::register($pending, $paymentId, $amount);
        $target = null;

        foreach ($pending as $rata) {
            if ($rata['id'] === $paymentId) {
                $target = $rata;
            }
        }

        $changes = [
            'status' => 'paid',
            'paid_at' => $paidAt,
            'amount' => number_format($plan['paid']['amount'], 2, '.', ''),
        ];

        foreach (['payment_method_id', 'payment_account_id'] as $key) {
            if ((int) ($options[$key] ?? 0) > 0) {
                $changes[$key] = (int) $options[$key];
            }
        }

        $reference = trim((string) ($options['reference'] ?? ''));

        if ($reference !== '') {
            if (Ledger::byReference('manual', $reference) !== null) {
                throw UserError::make('payment.reference_used');
            }

            $changes['provider_reference'] = $reference;
        }

        Payment::update($changes, $paymentId);

        if (round((float) $target['amount'], 2) !== $plan['paid']['amount']) {
            self::log($paymentId, 'amount', self::money((float) $target['amount']), self::money($plan['paid']['amount']));
        }

        self::log($paymentId, 'status', 'pending', 'paid', $options);

        foreach ($plan['create'] as $resto) {
            self::write(
                $owner['row'],
                $owner['column'],
                (float) $resto['amount'],
                (string) $resto['due_date'],
                trim($target['note'].' (resto)')
            );
        }

        foreach ($plan['update'] as $id => $value) {
            self::changeAmount($id, $value, self::amountOf($pending, $id));
        }

        foreach ($plan['cancel'] as $id) {
            self::move($id, 'pending', 'cancelled', 'Pagata con la rata precedente');
        }

        return [
            'payment_id' => $paymentId,
            'payment_status' => $owner['column'] === 'order_id' ? Ledger::sync($owner['id']) : '',
        ];
    }

    /**
     * Una rata in attesa, firmata col proprio codice come gli incassi a mano.
     * `$column` dice di chi è: `order_id` o `subscription_id`.
     *
     * @param array<string, mixed> $order la riga dell'ordine o dell'abbonamento
     */
    private static function write(array $order, string $column, float $amount, string $dueDate, string $note): int
    {
        $code = Code::make(Payment::class, Codes::PAYMENT);
        $created = Payment::create([
            'code' => $code,
            'type' => 'payment',
            'order_id' => $column === 'order_id' ? (int) $order['id'] : 0,
            'subscription_id' => $column === 'subscription_id' ? (int) $order['id'] : 0,
            'customer_id' => (int) ($order['customer_id'] ?? 0),
            'payment_method_id' => (int) ($order['payment_method_id'] ?? 0),
            'payment_account_id' => (int) ($order['payment_account_id'] ?? 0),
            'amount' => self::money($amount),
            'currency' => (string) (($order['currency'] ?? '') ?: 'EUR'),
            'status' => 'pending',
            'provider' => 'manual',
            'provider_reference' => $code,
            'due_date' => $dueDate,
            'note' => $note,
        ]);

        if (($created->success ?? false) !== true) {
            throw new RuntimeException('Rata non scritta.');
        }

        $id = (int) ($created->insert_id ?? 0);
        self::log($id, 'status', '', 'pending');

        return $id;
    }

    private static function changeAmount(int $id, float $amount, float $before): void
    {
        Payment::update(['amount' => self::money($amount)], $id);
        self::log($id, 'amount', self::money($before), self::money($amount));
    }

    private static function move(int $id, string $from, string $to, string $message): void
    {
        Payment::update(['status' => $to], $id);
        self::log($id, 'status', $from, $to, [], $message);
    }

    /** @param array<string, mixed> $options */
    private static function log(int $id, string $field, string $from, string $to, array $options = [], string $message = ''): void
    {
        $source = (string) ($options['source'] ?? 'system');

        StatusLogger::record(
            PaymentStatusLog::class,
            $id,
            $field,
            $from,
            $to,
            in_array($source, StatusLogger::SOURCES, true) ? $source : 'system',
            (int) ($options['user_id'] ?? 0) ?: null,
            $message
        );
    }

    /** @return array<string, mixed> */
    private static function lockSubscription(int $subscriptionId): array
    {
        $row = Subscription::findForUpdate(['id' => $subscriptionId], 1);

        if (!is_array($row) || $row === [] || (string) ($row['deleted'] ?? 'false') === 'true') {
            throw new RuntimeException("Abbonamento {$subscriptionId} non trovato.");
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private static function lockOrder(int $orderId): array
    {
        $order = $orderId > 0 ? Order::findForUpdate(['id' => $orderId], 1) : null;

        if (!is_array($order) || $order === [] || (string) ($order['deleted'] ?? 'false') === 'true') {
            throw new RuntimeException("Ordine {$orderId} non trovato.");
        }

        return $order;
    }

    /**
     * Quanto resta da pagare: il totale meno il denaro arrivato, al netto dei
     * rimborsi.
     *
     * @param array<string, mixed> $order
     * @param list<array<string, mixed>> $rows
     */
    private static function due(array $order, array $rows): float
    {
        $sums = PaymentStatus::sums($rows);

        return max(0.0, round((float) $order['total'] - ($sums['paid'] - $sums['refunded']), 2));
    }

    /** @return list<array{days: int, end_of_month: bool, percentage: float}> */
    private static function termRows(int $termId): array
    {
        if ($termId <= 0) {
            return [];
        }

        $rows = PaymentTermInstallment::find(['payment_term_id' => $termId], null, 'position', 'ASC');
        $rows = is_array($rows) ? (isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'))) : [];

        return array_map(static fn (array $r): array => [
            'days' => (int) ($r['days'] ?? 0),
            'end_of_month' => (string) ($r['end_of_month'] ?? 'false') === 'true',
            'percentage' => (float) ($r['percentage'] ?? 0),
        ], $rows);
    }

    /** @return list<array<string, mixed>> */
    private static function rowsOf(int $orderId, string $column = 'order_id'): array
    {
        $rows = Payment::find([$column => $orderId, 'deleted' => 'false']);

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }

    /** @param list<array{id: int, amount: float}> $pending */
    private static function amountOf(array $pending, int $id): float
    {
        foreach ($pending as $rata) {
            if ($rata['id'] === $id) {
                return $rata['amount'];
            }
        }

        return 0.0;
    }

    private static function isDate(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1 && !str_starts_with($value, '0000');
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
