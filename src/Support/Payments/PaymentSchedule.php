<?php

namespace Wonder\Plugin\Gestionale\Support\Payments;

use DateTimeImmutable;

/**
 * Le scadenze di un importo secondo le rate di una condizione di pagamento.
 * Pura.
 *
 * Scadenza = data di partenza più i giorni della rata; «fine mese» porta
 * all'ultimo giorno di quel mese («30 gg fine mese» dal 10 ottobre è il 30
 * novembre). Importo = totale per la percentuale, al centesimo; **l'ultima rata
 * prende il resto**, così la somma torna sempre. Senza rate (nessuna
 * condizione) è una rata sola, subito, per tutto.
 *
 * Non sa niente di ordini: la usano gli ordini da ufficio (G9) e gli
 * abbonamenti da ufficio (G10), con la data d'inizio del periodo.
 */
final class PaymentSchedule
{
    /**
     * @param list<array{days: int|string, end_of_month: string|bool, percentage: float|string}> $installments in ordine
     * @return list<array{position: int, due_date: string, amount: float}>
     */
    public static function of(array $installments, float $total, DateTimeImmutable $from): array
    {
        $total = round($total, 2);

        if ($total <= 0) {
            return [];
        }

        if ($installments === []) {
            $installments = [['days' => 0, 'end_of_month' => 'false', 'percentage' => 100]];
        }

        $out = [];
        $assigned = 0.0;
        $last = count($installments) - 1;

        foreach (array_values($installments) as $i => $installment) {
            $amount = $i === $last
                ? round($total - $assigned, 2)
                : round($total * (float) $installment['percentage'] / 100, 2);
            $assigned = round($assigned + $amount, 2);

            $out[] = [
                'position' => $i + 1,
                'due_date' => self::dueDate($from, (int) $installment['days'], in_array($installment['end_of_month'], [true, 'true'], true)),
                'amount' => $amount,
            ];
        }

        return $out;
    }

    public static function dueDate(DateTimeImmutable $from, int $days, bool $endOfMonth): string
    {
        $date = $from->setTime(0, 0)->modify('+'.max(0, $days).' days');

        if ($endOfMonth) {
            $date = $date->modify('last day of this month');
        }

        return $date->format('Y-m-d');
    }
}
