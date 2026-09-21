<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

/**
 * Il nome di una versione in vendita: "Blu / M".
 *
 * Lo scrive il generatore quando crea la riga, e chi vende lo può correggere:
 * è una fotografia, non un calcolo. Rinominare un valore ("Blu" → "Blu notte")
 * non riscrive i nomi già generati, di proposito — quello che il cliente ha
 * letto ieri non deve cambiare da sé.
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
