<?php

namespace Wonder\Plugin\Gestionale\Support;

/**
 * Numeri scritti da una persona, portati nella forma che capisce il database.
 *
 * In Italia i decimali si scrivono con la virgola, e il campo del backend li
 * manda così come sono stati battuti. `Model::update()` non li converte: la
 * query arriva a MySQL con `19,90` e il database risponde "Incorrect decimal
 * value". Chi scrive un numero arrivato da un form passa di qui.
 */
final class Numbers
{
    /**
     * Il valore da scrivere in una colonna DECIMAL: `null` quando la casella è
     * vuota, così la colonna resta vuota invece di diventare zero.
     */
    public static function fromForm(mixed $value): ?string
    {
        if (is_array($value)) {
            return null;
        }

        $text = str_replace([' ', "\u{a0}"], '', trim((string) $value));

        if ($text === '') {
            return null;
        }

        // Migliaia con il punto e decimali con la virgola: "1.234,50".
        if (str_contains($text, ',')) {
            $text = str_replace('.', '', $text);
        }

        $text = str_replace(',', '.', $text);

        return is_numeric($text) ? $text : null;
    }
}
