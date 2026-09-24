<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Wonder\Plugin\Gestionale\Support\Numbers;

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
     * punto: `1.234,5` è milleduecentotrentaquattro e mezzo. L'unità in coda,
     * «12 pz» o «2,5 kg», si toglie: la lettura è quella di
     * {@see Numbers::fromForm()}.
     */
    public static function quantity(mixed $value): ?float
    {
        $text = Numbers::fromForm($value);

        return $text === null ? null : round((float) $text, 3);
    }
}
