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
    /**
     * Le tre azioni della scheda, con il nome che legge chi rettifica.
     *
     * La prima è il predefinito: la rettifica più frequente è la merce che
     * arriva.
     */
    public const ACTIONS = [
        'add' => 'Aggiungi',
        'subtract' => 'Sottrai',
        'set' => 'Imposta',
    ];

    /**
     * Il movimento dell'azione scelta nella scheda.
     *
     * La quantità si legge **in valore assoluto**: il segno lo mette l'azione,
     * non chi scrive. Chi mette un meno davanti a "Sottrai" voleva togliere i
     * pezzi, e un `-(-5)` glieli aggiungerebbe.
     *
     * @return array{delta: float, before: float, after: float}
     */
    public static function of(string $action, float $current, float $quantity): array
    {
        $quantity = abs($quantity);

        return match ($action) {
            'subtract' => static::fromDelta($current, -$quantity),
            'set' => static::fromTarget($current, $quantity),
            // Un'azione che non conosciamo aggiunge, come il predefinito: una
            // tendina manomessa non deve azzerare un magazzino.
            default => static::fromDelta($current, $quantity),
        };
    }

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
