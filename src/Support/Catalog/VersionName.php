<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

/**
 * Il nome di una versione in vendita: "Blu / M".
 *
 * Non si scrive a mano: lo calcola il pannello dai valori d'attributo, e
 * `ProductModelResource::realignNames()` lo rimette in riga a ogni
 * salvataggio. Rinominare un valore ("Blu" → "Blu notte") nell'anagrafica
 * rinomina quindi anche le opzioni già nate, che è il motivo per cui quel
 * nome si cambia in un posto solo.
 *
 * Un articolo senza nessun attributo non passa di qui: il suo nome è quello
 * che gli ha dato chi l'ha creato.
 *
 * Classe pura: la usano il generatore, i dati di prova e i test.
 */
final class VersionName
{
    /** @param list<string> $labels */
    public static function from(array $labels, string $fallback = ''): string
    {
        $parts = [];

        foreach ($labels as $label) {
            $label = trim((string) $label);

            if ($label !== '') {
                $parts[] = $label;
            }
        }

        return $parts === [] ? trim($fallback) : implode(' / ', $parts);
    }
}
