<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

/**
 * Disponibile = giacenza − prenotazioni attive.
 *
 * È la differenza che evita l'overselling: due clienti che mettono nel
 * carrello l'ultimo pezzo non possono comprarlo tutti e due. In G2b nessuno
 * scrive prenotazioni — lo farà il checkout in G4 — ma la regola vive qui da
 * subito, così backend e vetrina non la scriveranno ognuno a modo suo.
 *
 * Una prenotazione conta finché non è stata rilasciata e non è scaduta.
 * Pura: prende array, non tocca il database.
 */
final class Availability
{
    /**
     * @param list<array<string, mixed>> $reservations
     */
    public static function of(float $quantity, array $reservations, ?string $now = null): float
    {
        return round($quantity - self::reserved($reservations, $now), 3);
    }

    /**
     * @param list<array<string, mixed>> $reservations
     */
    public static function reserved(array $reservations, ?string $now = null): float
    {
        $now ??= date('Y-m-d H:i:s');
        $total = 0.0;

        foreach ($reservations as $reservation) {
            if (is_array($reservation) && self::isActive($reservation, $now)) {
                $total += (float) ($reservation['quantity'] ?? 0);
            }
        }

        return round($total, 3);
    }

    /** @param array<string, mixed> $reservation */
    public static function isActive(array $reservation, string $now): bool
    {
        if (!self::isEmptyDate($reservation['released_at'] ?? null)) {
            return false;
        }

        $expires = $reservation['expires_at'] ?? null;

        return self::isEmptyDate($expires) || (string) $expires > $now;
    }

    /**
     * Le date "mai" arrivano in tre forme: `null` da una colonna vuota, la
     * stringa vuota da chi scrive dai form, gli zeri da MySQL.
     */
    private static function isEmptyDate(mixed $value): bool
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' || str_starts_with($text, '0000-00-00');
    }
}
