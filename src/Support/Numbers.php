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
     *
     * Di solito arriva il numero grezzo, perché AutoNumeric toglie il formato
     * prima di inviare. Quando non l'ha fatto (lib vecchia, JavaScript
     * spento) arriva quello che si vede: «1.234,50 €», «12 pz», «2,5 kg». Il
     * simbolo o l'unità in coda si tolgono; tutto il resto deve essere un
     * numero.
     */
    public static function fromForm(mixed $value): ?string
    {
        if (is_array($value)) {
            return null;
        }

        $text = str_replace([' ', "\u{a0}", "\u{202f}"], '', trim((string) $value));

        // Solo in coda, e solo lettere o valute: «--3» resta un errore.
        $text = (string) preg_replace('/[\p{L}\p{Sc}]+$/u', '', $text);

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
