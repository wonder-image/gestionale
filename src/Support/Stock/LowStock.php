<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

/**
 * Quando aprire e quando chiudere un avviso di scorta minima.
 *
 * Tre regole sole, tutte pensate per **non** ripetere l'avviso: chi riceve
 * dieci email per lo stesso prodotto smette di leggerle. L'avviso resta aperto
 * finché il prodotto non risale sopra soglia; solo allora, se ricade, ne nasce
 * uno nuovo.
 *
 * Una soglia a zero vuol dire "non avvisarmi": se c'era un avviso aperto si
 * chiude, perché il commerciante ha appena detto che non gli interessa più.
 */
final class LowStock
{
    public const OPEN = 'open';
    public const CLOSE = 'close';
    public const NONE = 'none';

    /** @return self::OPEN|self::CLOSE|self::NONE */
    public static function decide(float $threshold, float $available, bool $alertOpen): string
    {
        if (round($threshold, 3) <= 0.0) {
            return $alertOpen ? self::CLOSE : self::NONE;
        }

        if (round($available, 3) <= round($threshold, 3)) {
            return $alertOpen ? self::NONE : self::OPEN;
        }

        return $alertOpen ? self::CLOSE : self::NONE;
    }
}
