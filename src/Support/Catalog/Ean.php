<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

use Throwable;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;

/**
 * L'EAN di un prodotto: 8 o 13 cifre, facoltativo, unico se compilato.
 *
 * **Non si controlla la cifra di controllo.** Molti negozi usano codici interni
 * stampati su etichette già attaccate alla merce: rifiutare un codice che il
 * fornitore usa davvero sarebbe peggio che accettarne uno con la cifra storta.
 * Si controlla la forma, perché quella è un errore di battitura.
 */
final class Ean
{
    public static function isValid(string $ean): bool
    {
        $ean = trim($ean);

        if ($ean === '') {
            return true;
        }

        return preg_match('/^\d{8}$|^\d{13}$/', $ean) === 1;
    }

    /** Vero se quell'EAN non è già di un altro prodotto. */
    public static function isFree(string $ean, ?int $exceptId = null): bool
    {
        $ean = trim($ean);

        if ($ean === '') {
            return true;
        }

        try {
            $rows = Product::find(['ean' => $ean, 'deleted' => 'false']);
        } catch (Throwable) {
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
