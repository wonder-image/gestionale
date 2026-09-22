<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

/**
 * Le unità di misura di un attributo.
 *
 * Non sono quelle con cui si vende — pezzi, confezioni — ma quelle con cui si
 * misura un valore: un attributo «Peso» dice grammi, un «Diametro» dice
 * millimetri. Prima era una casella di testo libero, e due schede scrivevano
 * "g" e "grammi" per la stessa cosa.
 */
final class Units
{
    /** @return array<string, string> */
    public static function all(): array
    {
        return [
            '' => 'Nessuna',
            'mm' => 'Millimetri (mm)',
            'cm' => 'Centimetri (cm)',
            'm' => 'Metri (m)',
            'g' => 'Grammi (g)',
            'kg' => 'Chilogrammi (kg)',
            'ml' => 'Millilitri (ml)',
            'cl' => 'Centilitri (cl)',
            'l' => 'Litri (l)',
            'pz' => 'Pezzi (pz)',
            'w' => 'Watt (W)',
            'v' => 'Volt (V)',
            '%' => 'Percentuale (%)',
        ];
    }
}
