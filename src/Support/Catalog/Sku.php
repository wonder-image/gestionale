<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

use Throwable;

/**
 * Lo SKU di un prodotto: proposta e unicità.
 *
 * Lo SKU resta scritto a mano — è il codice che il commerciante usa in
 * magazzino e al telefono con il fornitore — ma il pannello lo **propone** dal
 * codice del modello più i valori scelti: `TSH-1` + Blu + M = `TSH-1-BLU-M`.
 * Se il modello non ha uno SKU non si inventa niente: un codice a caso sarebbe
 * peggio di un campo vuoto.
 */
final class Sku
{
    /**
     * Lo SKU proposto per una combinazione.
     *
     * @param list<string> $parts etichette dei valori scelti, in ordine
     */
    public static function propose(string $modelSku, array $parts): string
    {
        $modelSku = trim($modelSku);

        if ($modelSku === '') {
            return '';
        }

        $pieces = [$modelSku];

        foreach ($parts as $part) {
            $piece = self::part((string) $part);

            if ($piece !== '') {
                $pieces[] = $piece;
            }
        }

        return implode('-', $pieces);
    }

    /** Un pezzo di SKU: maiuscolo, senza spazi, accenti o segni. */
    public static function part(string $label): string
    {
        $label = strtr(trim($label), [
            'à' => 'a', 'è' => 'e', 'é' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'À' => 'A', 'È' => 'E', 'É' => 'E', 'Ì' => 'I', 'Ò' => 'O', 'Ù' => 'U',
        ]);

        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $label));
    }

    /**
     * Vero se quello SKU non è già di un'altra riga della stessa tabella.
     *
     * Uno SKU vuoto è sempre libero: è facoltativo, e il framework scrive
     * stringhe vuote invece di NULL (per questo non c'è un indice UNIQUE).
     *
     * @param class-string<\Wonder\App\Model> $modelClass
     */
    public static function isFree(string $modelClass, string $sku, ?int $exceptId = null): bool
    {
        $sku = trim($sku);

        if ($sku === '') {
            return true;
        }

        try {
            $rows = $modelClass::find(['sku' => $sku, 'deleted' => 'false']);
        } catch (Throwable) {
            // Senza database (test degli schemi) non si può dire di no.
            return true;
        }

        if (!is_array($rows) || $rows === []) {
            return true;
        }

        $rows = isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));

        foreach ($rows as $row) {
            if ($exceptId === null || (int) ($row['id'] ?? 0) !== $exceptId) {
                return false;
            }
        }

        return true;
    }
}
