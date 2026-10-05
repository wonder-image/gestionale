<?php

namespace Wonder\Plugin\Gestionale\Support\Shipping;

/**
 * Il peso tassabile di una spedizione: per ogni riga il maggiore tra il peso
 * reale e il peso volumetrico (lunghezza × larghezza × altezza ÷ divisore),
 * per la quantità, sommato. Pesi in kg, misure in cm.
 *
 * Classe pura: niente database, niente eccezioni. Con divisore vuoto, zero o
 * negativo, o con una misura mancante, conta il peso reale.
 */
final class ShippingWeight
{
    /**
     * @param list<array{weight: float|int|string, length: float|int|string, width: float|int|string, height: float|int|string, quantity: float|int|string}> $lines
     */
    public static function of(array $lines, ?float $divisor): float
    {
        $total = 0.0;

        foreach ($lines as $line) {
            $weight = (float) ($line['weight'] ?? 0);
            $length = (float) ($line['length'] ?? 0);
            $width = (float) ($line['width'] ?? 0);
            $height = (float) ($line['height'] ?? 0);
            $quantity = (float) ($line['quantity'] ?? 0);

            if ($divisor !== null && $divisor > 0.0 && $length > 0.0 && $width > 0.0 && $height > 0.0) {
                $weight = max($weight, $length * $width * $height / $divisor);
            }

            $total += $weight * $quantity;
        }

        return round($total, 3);
    }
}
