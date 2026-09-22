<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Throwable;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Stock\StockAlert;

/**
 * Apre e chiude gli avvisi di scorta minima.
 *
 * La decisione è di `LowStock`, che è pura e si prova con gli array; qui c'è
 * solo la scrittura. Gira dentro la transazione di `Stock::apply()`: un avviso
 * aperto da un movimento annullato deve sparire con lui.
 *
 * L'email non parte da qui. La riga nasce con `notified_at` vuoto e la manda
 * l'attività dello scheduler, raggruppata: dieci rettifiche di fila non devono
 * fare dieci email, e il salvataggio non deve dipendere dal server di posta.
 */
final class Alerts
{
    /** @return LowStock::OPEN|LowStock::CLOSE|LowStock::NONE */
    public static function refresh(int $productId): string
    {
        if ($productId <= 0) {
            return LowStock::NONE;
        }

        try {
            $product = Product::findById($productId);
        } catch (Throwable) {
            return LowStock::NONE;
        }

        if (!is_array($product) || $product === []) {
            return LowStock::NONE;
        }

        $threshold = round((float) ($product['min_stock_quantity'] ?? 0), 3);
        $available = Levels::of($productId)['available'];
        $open = self::openRow($productId);
        $decision = LowStock::decide($threshold, $available, $open !== []);

        if ($decision === LowStock::OPEN) {
            StockAlert::create([
                'product_id' => $productId,
                'location_id' => 0,
                'threshold' => number_format($threshold, 3, '.', ''),
                'quantity_at_alert' => number_format($available, 3, '.', ''),
            ]);
        }

        if ($decision === LowStock::CLOSE && $open !== []) {
            StockAlert::update(
                ['resolved_at' => date('Y-m-d H:i:s')],
                (int) $open['id']
            );
        }

        return $decision;
    }

    /** L'avviso ancora aperto di un prodotto, `[]` se non ce n'è. @return array<string, mixed> */
    public static function openRow(int $productId): array
    {
        // "Non ancora risolto" è `NULL`: la colonna la scrive solo chi chiude
        // l'avviso. E deve restare `IS NULL` e basta — confrontare un DATETIME
        // con la stringa vuota fa fallire la query ("Incorrect DATETIME
        // value"), e il `catch` qui sotto lo nasconderebbe.
        $condition = 'product_id = '.$productId
            ." AND deleted = 'false'"
            .' AND resolved_at IS NULL';

        try {
            $row = StockAlert::find($condition, 1, 'id', 'DESC');
        } catch (Throwable) {
            return [];
        }

        return is_array($row) ? $row : [];
    }
}
