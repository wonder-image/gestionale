<?php

namespace Wonder\Plugin\Gestionale\Support\Subscriptions;

/**
 * I limiti di più righe d'abbonamento messi insieme (G10b), puri.
 *
 * - un valore «illimitato» (o `unlimited`, senza badare alle maiuscole) vince;
 * - se sono tutti numeri, si sommano per la quantità della riga: due hosting
 *   da 5 progetti fanno 10;
 * - altrimenti (un testo come «Pro») vale quello della riga più recente.
 */
final class PlanFeatureMerge
{
    public const UNLIMITED = ['illimitato', 'illimitata', 'illimitati', 'unlimited'];

    /**
     * @param list<array{value: string, quantity: int, at: string}> $values
     */
    public static function merge(array $values): ?string
    {
        if ($values === []) {
            return null;
        }

        foreach ($values as $value) {
            if (in_array(mb_strtolower(trim((string) $value['value'])), self::UNLIMITED, true)) {
                return trim((string) $value['value']);
            }
        }

        $numeric = array_filter($values, static fn (array $value): bool => is_numeric(trim((string) $value['value'])));

        if (count($numeric) === count($values)) {
            $sum = 0.0;

            foreach ($values as $value) {
                $sum += (float) trim((string) $value['value']) * max(1, (int) ($value['quantity'] ?? 1));
            }

            return rtrim(rtrim(number_format($sum, 4, '.', ''), '0'), '.');
        }

        usort($values, static fn (array $a, array $b): int => strcmp((string) ($b['at'] ?? ''), (string) ($a['at'] ?? '')));

        return trim((string) $values[0]['value']);
    }
}
