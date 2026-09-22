<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

/**
 * L'inventario: le quantità scritte a mano, riga per riga, e la differenza
 * con quelle che c'erano.
 *
 * Due regole che vengono da come si lavora davvero:
 *
 * - **una casella vuota non è uno zero.** Chi conta lo scaffale scrive solo le
 *   righe che ha contato; le altre non le ha viste, e non vanno toccate.
 * - **uno zero scritto è uno zero vero.** "Non ce n'è più" è un'informazione,
 *   e deve diventare un movimento.
 *
 * Pura: prende array, torna array, non sa cosa sia un database.
 */
final class Stocktake
{
    /**
     * Le righe davvero cambiate, con la differenza da registrare.
     *
     * @param array<int, float> $current quantità di adesso, `[productId => q]`
     * @param array<int, float> $posted quantità scritte, `[productId => q]`
     * @return array<int, array{delta: float, before: float, after: float}>
     */
    public static function changes(array $current, array $posted): array
    {
        $changes = [];

        foreach ($posted as $productId => $target) {
            $id = (int) $productId;
            $change = Adjustment::fromTarget((float) ($current[$id] ?? 0), (float) $target);

            if ($change['delta'] !== 0.0) {
                $changes[$id] = $change;
            }
        }

        return $changes;
    }

    /**
     * Il numero scritto in una casella, o `null` se non c'è.
     *
     * In Italia i decimali si scrivono con la virgola e le migliaia con il
     * punto: `1.234,5` è milleduecentotrentaquattro e mezzo.
     */
    public static function quantity(mixed $value): ?float
    {
        if (is_array($value)) {
            return null;
        }

        $text = str_replace([' ', "\u{a0}"], '', trim((string) ($value ?? '')));

        if ($text === '') {
            return null;
        }

        if (str_contains($text, ',')) {
            $text = str_replace('.', '', $text);
        }

        $text = str_replace(',', '.', $text);

        return is_numeric($text) ? round((float) $text, 3) : null;
    }
}
