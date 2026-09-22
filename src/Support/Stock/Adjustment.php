<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

/**
 * Dalla quantità scritta da una persona al movimento da registrare.
 *
 * Chi rettifica ragiona in due modi: "sullo scaffale ce ne sono dieci"
 * (`fromTarget`) oppure "ne ho buttati due" (`fromDelta`). Il magazzino
 * registra sempre la differenza, con la giacenza prima e dopo.
 *
 * Tutto arrotondato al terzo decimale, quanti ne ha la colonna: senza, una
 * somma in virgola mobile lascia briciole (`0.1 + 0.2` fa
 * `0.30000000000000004`) che finirebbero nel database.
 */
final class Adjustment
{
    /** @return array{delta: float, before: float, after: float} */
    public static function fromTarget(float $current, float $target): array
    {
        $before = round($current, 3);
        $after = round($target, 3);

        return [
            'delta' => round($after - $before, 3),
            'before' => $before,
            'after' => $after,
        ];
    }

    /** @return array{delta: float, before: float, after: float} */
    public static function fromDelta(float $current, float $delta): array
    {
        $before = round($current, 3);

        return [
            'delta' => round($delta, 3),
            'before' => $before,
            'after' => round($before + $delta, 3),
        ];
    }
}
