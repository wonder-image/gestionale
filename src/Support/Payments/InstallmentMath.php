<?php

namespace Wonder\Plugin\Gestionale\Support\Payments;

use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;

/**
 * I conti sulle rate in attesa di un documento. Pura: dice cosa scrivere,
 * `Installments` lo scrive.
 *
 * Una rata è `{id, amount, due_date}`; le rate arrivano in ordine di scadenza.
 */
final class InstallmentMath
{
    /**
     * Un incasso su una rata (G9.3).
     *
     * - Uguale: la rata è pagata.
     * - Minore: la rata è pagata per quanto incassato e ne nasce una col resto,
     *   **stessa scadenza**.
     * - Maggiore: la rata è pagata per tutto l'incasso; l'eccedenza scala le
     *   rate dopo, in ordine di scadenza; una rata azzerata si annulla.
     * - Oltre il totale delle rate in attesa: rifiuto.
     *
     * @param list<array{id: int, amount: float, due_date: string}> $pending in ordine di scadenza
     * @return array{paid: array{id: int, amount: float}, create: list<array{amount: float, due_date: string}>, update: array<int, float>, cancel: list<int>}
     */
    public static function register(array $pending, int $paymentId, float $amount): array
    {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw UserError::make('payment.zero_amount');
        }

        $target = null;
        $after = [];

        foreach ($pending as $row) {
            if ($target !== null) {
                $after[] = $row;
            } elseif ((int) $row['id'] === $paymentId) {
                $target = $row;
            }
        }

        if ($target === null) {
            throw UserError::make('payment.installment_not_pending');
        }

        $due = round((float) $target['amount'] + array_sum(array_map(static fn (array $r): float => (float) $r['amount'], $after)), 2);

        if ($amount > $due + 0.001) {
            throw UserError::make('payment.over_balance', ['residuo' => OrderSheet::money($due)]);
        }

        $plan = ['paid' => ['id' => $paymentId, 'amount' => $amount], 'create' => [], 'update' => [], 'cancel' => []];
        $difference = round($amount - (float) $target['amount'], 2);

        if ($difference < 0) {
            $plan['create'][] = ['amount' => -$difference, 'due_date' => (string) $target['due_date']];

            return $plan;
        }

        foreach ($after as $row) {
            if ($difference <= 0) {
                break;
            }

            $left = round((float) $row['amount'] - $difference, 2);

            if ($left <= 0) {
                $plan['cancel'][] = (int) $row['id'];
                $difference = round(-$left, 2);
            } else {
                $plan['update'][(int) $row['id']] = $left;
                $difference = 0.0;
            }
        }

        return $plan;
    }

    /**
     * Il «da pagare» ridistribuito sulle rate in attesa, in proporzione ai loro
     * importi; l'ultima prende il resto. Da pagare zero o meno: tutte annullate.
     *
     * @param list<array{id: int, amount: float, due_date: string}> $pending
     * @return array{update: array<int, float>, cancel: list<int>}
     */
    public static function redistribute(array $pending, float $due): array
    {
        $due = round($due, 2);
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $pending);

        if ($pending === []) {
            return ['update' => [], 'cancel' => []];
        }

        if ($due <= 0) {
            return ['update' => [], 'cancel' => $ids];
        }

        $current = round(array_sum(array_map(static fn (array $r): float => (float) $r['amount'], $pending)), 2);
        $update = [];
        $assigned = 0.0;
        $last = count($pending) - 1;

        foreach (array_values($pending) as $i => $row) {
            if ($i === $last) {
                $amount = round($due - $assigned, 2);
            } elseif ($current > 0) {
                $amount = round($due * (float) $row['amount'] / $current, 2);
            } else {
                $amount = 0.0;
            }

            $assigned = round($assigned + $amount, 2);
            $update[(int) $row['id']] = $amount;
        }

        $cancel = array_keys(array_filter($update, static fn (float $a): bool => $a <= 0));

        return [
            'update' => array_filter($update, static fn (float $a): bool => $a > 0),
            'cancel' => array_values($cancel),
        ];
    }
}
