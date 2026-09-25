<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use RuntimeException;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Stock\Stock as StockRow;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Sql\Transaction;

/**
 * **L'unica porta di scrittura del magazzino.**
 *
 * Nessun altro codice tocca `gst_stock` o scrive su `gst_stock_movements`: né
 * le pagine, né i comandi, né i sotto-progetti che verranno. Carichi e
 * trasferimenti (G3), vendite, annullamenti e resi (G4) passano tutti da qui,
 * cambiando `type` e `reference_*`.
 *
 * È l'unico modo perché "giacenza" e "somma dei movimenti" non divergano: la
 * riga si legge con `FOR UPDATE` dentro la transazione, così due ordini
 * contemporanei non prendono lo stesso ultimo pezzo, e il movimento e la nuova
 * quantità si scrivono insieme o non si scrivono affatto.
 *
 * `Transaction::run()` si annida: chi chiama `apply()` da dentro una
 * transazione sua — l'elenco delle giacenze che salva venti righe — ottiene un
 * savepoint, non una seconda transazione.
 */
final class Stock
{
    /**
     * @param array<string, mixed> $movement
     * @return array{movement_id: int, before: float, after: float, alert: string}
     */
    public static function apply(array $movement): array
    {
        $productId = (int) ($movement['product_id'] ?? 0);
        $quantity = round((float) ($movement['quantity'] ?? 0), 3);
        $reason = trim((string) ($movement['reason'] ?? ''));

        if ($quantity === 0.0) {
            throw UserError::make('stock.zero_quantity');
        }

        if ($reason !== '' && !Reasons::exists($reason)) {
            throw UserError::make('stock.unknown_reason');
        }

        $product = $productId > 0 ? Product::findById($productId) : null;

        if (!is_array($product) || $product === []) {
            throw UserError::make('stock.product_missing');
        }

        $keys = [
            'product_id' => $productId,
            'location_id' => (int) ($movement['location_id'] ?? 0) ?: Locations::mainId(),
            'batch_id' => (int) ($movement['batch_id'] ?? 0),
            'supplier_id' => (int) ($movement['supplier_id'] ?? 0),
        ];

        if ($keys['location_id'] <= 0) {
            throw UserError::make('stock.no_location');
        }

        return Transaction::run(static function () use ($keys, $quantity, $movement, $product): array {
            $row = StockRow::findForUpdate(array_merge($keys, ['deleted' => 'false']), 1);
            $row = is_array($row) ? $row : [];
            $before = round((float) ($row['quantity'] ?? 0), 3);
            $after = round($before + $quantity, 3);

            // Sotto zero si va solo se la funzionalità lo permette **e**
            // l'opzione lo vuole: la funzionalità dice che si può, l'articolo
            // dice se lo vende scoperto. Altrimenti la giacenza è un muro, ma
            // solo per chi toglie: la merce che arriva entra sempre, anche se
            // non basta a colmare un buco rimasto da quando si vendeva scoperto.
            $backorder = Gestionale::feature('backorders')
                && ($product['allow_backorder'] ?? 'false') === 'true';

            if ($after < 0 && $quantity < 0 && !$backorder) {
                throw UserError::make('stock.insufficient', [
                    'product' => trim((string) ($product['name'] ?? '')) !== ''
                        ? (string) $product['name']
                        : (string) ($product['sku'] ?? ''),
                ]);
            }

            $created = StockMovement::create(array_merge($keys, [
                'code' => Code::make(StockMovement::class, Codes::STOCK_MOVEMENT),
                'type' => trim((string) ($movement['type'] ?? '')) !== ''
                    ? (string) $movement['type']
                    : 'adjustment',
                'reason' => trim((string) ($movement['reason'] ?? '')),
                'quantity' => self::number($quantity),
                'quantity_before' => self::number($before),
                'quantity_after' => self::number($after),
                'unit_cost' => self::number((float) ($movement['unit_cost'] ?? 0), 4),
                'reference_type' => trim((string) ($movement['reference_type'] ?? '')),
                'reference_id' => (int) ($movement['reference_id'] ?? 0),
                'source' => trim((string) ($movement['source'] ?? '')) !== ''
                    ? (string) $movement['source']
                    : 'backend',
                'user_id' => (int) ($movement['user_id'] ?? 0),
                'note' => (string) ($movement['note'] ?? ''),
            ]));

            if (($created->success ?? false) !== true) {
                // Non è un errore da far leggere: è un guasto, e la transazione
                // riporta indietro tutto.
                throw new RuntimeException('Movimento di magazzino non scritto.');
            }

            if ((int) ($row['id'] ?? 0) > 0) {
                StockRow::update(['quantity' => self::number($after)], (int) $row['id']);
            } else {
                StockRow::create(array_merge($keys, ['quantity' => self::number($after)]));
            }

            return [
                'movement_id' => (int) ($created->insert_id ?? 0),
                'before' => $before,
                'after' => $after,
                'alert' => Alerts::refresh($keys['product_id']),
            ];
        });
    }

    /**
     * La forma che il database accetta sempre.
     *
     * I campi numerici del framework passano per `prepare()`, che con la
     * virgola combina guai: qui i numeri arrivano dal codice, e si scrivono
     * con il punto e i decimali della colonna.
     */
    private static function number(float $value, int $decimals = 3): string
    {
        return number_format($value, $decimals, '.', '');
    }
}
