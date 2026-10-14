<?php

namespace Wonder\Plugin\Gestionale\Support\Payments;

use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Numbers;

/**
 * Le rate di una condizione di pagamento, controllate prima di salvarle. Pura.
 *
 * Almeno una rata; giorni interi da 0 a 365; percentuale maggiore di zero e al
 * massimo 100, con due decimali; tutte insieme fanno 100. Le righe vuote (né
 * giorni né percentuale) del repeater non contano. `{{row}}` è la rata come la
 * conta chi legge, da 1.
 */
final class PaymentTermRules
{
    public const MAX_DAYS = 365;

    /**
     * @param list<array<string, mixed>> $rows come arrivano dal repeater
     * @return list<array{days: int, end_of_month: string, percentage: float}>
     */
    public static function installments(array $rows): array
    {
        $out = [];
        $row = 0;

        foreach ($rows as $input) {
            if (!is_array($input)) {
                continue;
            }

            $days = trim((string) ($input['days'] ?? ''));
            $percentage = Numbers::fromForm($input['percentage'] ?? null);

            if ($days === '' && $percentage === null) {
                continue;
            }

            $row++;

            if (preg_match('/^\d{1,3}$/D', $days) !== 1 || (int) $days > self::MAX_DAYS) {
                throw UserError::make('payment_term.invalid_days', ['row' => $row, 'max' => self::MAX_DAYS]);
            }

            $value = $percentage === null ? 0.0 : round((float) $percentage, 2);

            if ($value <= 0 || $value > 100) {
                throw UserError::make('payment_term.invalid_percentage', ['row' => $row]);
            }

            $out[] = [
                'days' => (int) $days,
                'end_of_month' => self::flag($input['end_of_month'] ?? 'false'),
                'percentage' => $value,
            ];
        }

        if ($out === []) {
            throw UserError::make('payment_term.no_installments');
        }

        $sum = round(array_sum(array_column($out, 'percentage')), 2);

        if (abs($sum - 100.0) > 0.001) {
            throw UserError::make('payment_term.percentages_sum', ['sum' => str_replace('.', ',', (string) ($sum + 0))]);
        }

        return $out;
    }

    private static function flag(mixed $value): string
    {
        return in_array($value, [true, 1, '1', 'true', 'on'], true) ? 'true' : 'false';
    }
}
