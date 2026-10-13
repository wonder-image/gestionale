<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use RuntimeException;
use Throwable;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Stock\Batch;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

/**
 * I lotti (G3, «Lotti e scadenze»): quali versioni li gestiscono e come un
 * carico trova o crea il suo.
 *
 * Il numero è unico per versione (G3.10): lo stesso numero in un altro carico
 * è lo stesso lotto, e la scadenza deve coincidere. Una scadenza vuota non
 * contraddice niente; scritta su un lotto che non l'ha, la riempie.
 */
final class Batches
{
    /**
     * Le versioni, fra quelle date, il cui articolo ha «Gestisci lotti e
     * scadenze» acceso. Vuota con la funzionalità spenta.
     *
     * @param list<int> $productIds
     * @return list<int>
     */
    public static function trackingProducts(array $productIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn (int $id): bool => $id > 0)));

        if ($ids === [] || !Gestionale::feature('batch_tracking')) {
            return [];
        }

        try {
            $rows = Product::find(
                '`id` IN ('.implode(', ', $ids).') AND `product_model_id` IN (SELECT m.`id` FROM `'.ProductModel::$table
                ."` m WHERE m.`track_batches` = 'true' AND m.`type` <> 'bundle')",
                null, null, null, 'id'
            );
        } catch (Throwable) {
            return [];
        }

        $rows = !is_array($rows) || $rows === [] ? [] : (isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array')));

        return array_values(array_map(static fn (array $row): int => (int) $row['id'], $rows));
    }

    /**
     * Il lotto `(versione, numero)`: trovato, o creato con la scadenza e il
     * fornitore di questo carico. Va chiamata dentro la transazione della
     * conferma.
     *
     * Due conferme sullo stesso lotto nuovo: la seconda `create()` trova
     * l'indice unico occupato e rilegge quello che la prima ha scritto.
     */
    public static function resolve(int $productId, string $number, ?string $expiry, int $supplierId, int $row): int
    {
        $number = StockDocumentRules::batchNumber($number);

        if ($productId <= 0 || $number === '') {
            throw UserError::make('stock_document.batch_required', ['row' => $row]);
        }

        $found = self::find($productId, $number, true);

        if ($found === []) {
            try {
                $created = Batch::create([
                    'code' => Code::make(Batch::class, Codes::BATCH),
                    'product_id' => $productId,
                    'number' => $number,
                    'expiry_date' => $expiry ?? '',
                    'supplier_id' => max(0, $supplierId),
                    'note' => '',
                ]);
                $id = ($created->success ?? false) === true ? (int) ($created->insert_id ?? 0) : 0;
            } catch (Throwable) {
                $id = 0;
            }

            if ($id > 0) {
                return $id;
            }

            // L'ha appena creato un'altra conferma: si rilegge, con il lock.
            $found = self::find($productId, $number, true);

            if ($found === []) {
                throw new RuntimeException('Lotto non creato: '.$number);
            }
        }

        $stored = self::expiryOf($found);

        if ($expiry !== null && $stored !== null && $stored !== $expiry) {
            throw UserError::make('batch.expiry_mismatch', ['row' => $row, 'expiry' => date('d/m/Y', (int) strtotime($stored))]);
        }

        if ($expiry !== null && $stored === null) {
            Batch::update(['expiry_date' => $expiry], (int) $found['id']);
        }

        return (int) $found['id'];
    }

    /** Il lotto è di quella versione? Per la scelta a mano nello scarico. */
    public static function belongsTo(int $batchId, int $productId): bool
    {
        if ($batchId <= 0) {
            return true;
        }

        $row = Batch::find(['id' => $batchId, 'product_id' => $productId, 'deleted' => 'false'], 1);

        return is_array($row) && isset($row['id']);
    }

    /** La scadenza salvata, o null per «senza scadenza» (vuota o gli zeri di MySQL). */
    public static function expiryOf(array $batch): ?string
    {
        $expiry = substr(trim((string) ($batch['expiry_date'] ?? '')), 0, 10);

        return $expiry === '' || str_starts_with($expiry, '0000') ? null : $expiry;
    }

    /** @return array<string, mixed> */
    private static function find(int $productId, string $number, bool $lock): array
    {
        $where = ['product_id' => $productId, 'number' => $number, 'deleted' => 'false'];
        $row = $lock ? Batch::findForUpdate($where, 1) : Batch::find($where, 1);

        return is_array($row) && isset($row['id']) ? $row : [];
    }
}
