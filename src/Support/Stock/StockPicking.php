<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

/**
 * Da quali secchi esce la merce (G3, spec §1.3). Classe pura.
 *
 * Un secchio è una riga di `gst_stock`: prodotto, sede, lotto, fornitore.
 * Qui arrivano i secchi di un prodotto in una sede, con la scadenza del lotto
 * e il costo del fornitore, e torna la lista dei pezzi da togliere a ognuno.
 *
 * L'ordine di uscita: prima il lotto 0 (la merce di prima dei lotti, la più
 * vecchia), poi i lotti dalla scadenza più vicina, quelli senza scadenza in
 * fondo; dentro lo stesso lotto prima il fornitore 0, poi il costo più basso,
 * i costi sconosciuti in fondo; a parità gli id, così il risultato è sempre
 * lo stesso. Con i lotti spenti le scadenze non contano, con gli acquisti
 * spenti i costi: i secchi che ci sono si consumano comunque, nell'ordine
 * degli id.
 *
 * Quello che non basta torna in `missing`: decide chi chiama. **Con i soli
 * secchi 0 il risultato è sempre una parte sola, `(0, 0, pezzi)`**: è il
 * caso di un negozio che non ha mai acceso lotti né acquisti, e la vendita
 * scrive esattamente il movimento di prima di G3.
 */
final class StockPicking
{
    /**
     * @param list<array{batch_id: int, supplier_id: int, quantity: float, expiry_date?: ?string, cost?: ?float}> $buckets
     * @param array{today?: string, use_batches?: bool, use_suppliers?: bool, batch_id?: int, supplier_id?: int, include_expired?: bool} $options
     * @return array{parts: list<array{batch_id: int, supplier_id: int, quantity: float}>, missing: float}
     */
    public static function split(array $buckets, float $quantity, array $options = []): array
    {
        $left = round($quantity, 3);

        if ($left <= 0) {
            return ['parts' => [], 'missing' => 0.0];
        }

        $parts = [];

        foreach (self::order(self::usable($buckets, $options), $options) as $bucket) {
            if ($left <= 0) {
                break;
            }

            $take = round(min($left, $bucket['quantity']), 3);

            if ($take <= 0) {
                continue;
            }

            $parts[] = ['batch_id' => $bucket['batch_id'], 'supplier_id' => $bucket['supplier_id'], 'quantity' => $take];
            $left = round($left - $take, 3);
        }

        return ['parts' => $parts, 'missing' => max(0.0, $left) + 0.0];
    }

    /** Scaduto vuol dire: la scadenza è prima di oggi. Il giorno stesso si vende ancora. */
    public static function isExpired(?string $expiry, string $today): bool
    {
        $expiry = trim((string) $expiry);

        return $expiry !== '' && !str_starts_with($expiry, '0000-00-00') && substr($expiry, 0, 10) < $today;
    }

    /**
     * I secchi da cui si può prendere: sopra zero, del lotto e del fornitore
     * scelti a mano se ci sono, senza gli scaduti salvo chi li chiede o li
     * sceglie.
     *
     * @return list<array{batch_id: int, supplier_id: int, quantity: float, expiry: string, cost: ?float}>
     */
    private static function usable(array $buckets, array $options): array
    {
        $today = (string) ($options['today'] ?? date('Y-m-d'));
        $useBatches = ($options['use_batches'] ?? false) === true;
        $batchId = (int) ($options['batch_id'] ?? 0);
        $supplierId = (int) ($options['supplier_id'] ?? 0);
        $withExpired = ($options['include_expired'] ?? false) === true || $batchId > 0;
        $out = [];

        foreach ($buckets as $bucket) {
            $row = [
                'batch_id' => (int) ($bucket['batch_id'] ?? 0),
                'supplier_id' => (int) ($bucket['supplier_id'] ?? 0),
                'quantity' => round((float) ($bucket['quantity'] ?? 0), 3),
                'expiry' => trim((string) ($bucket['expiry_date'] ?? '')),
                'cost' => isset($bucket['cost']) ? (float) $bucket['cost'] : null,
            ];

            if ($row['quantity'] <= 0
                || ($batchId > 0 && $row['batch_id'] !== $batchId)
                || ($supplierId > 0 && $row['supplier_id'] !== $supplierId)
                || ($useBatches && !$withExpired && self::isExpired($row['expiry'], $today))) {
                continue;
            }

            $out[] = $row;
        }

        return $out;
    }

    /** @param list<array{batch_id: int, supplier_id: int, quantity: float, expiry: string, cost: ?float}> $buckets */
    private static function order(array $buckets, array $options): array
    {
        $useBatches = ($options['use_batches'] ?? false) === true;
        $useSuppliers = ($options['use_suppliers'] ?? false) === true;

        usort($buckets, static function (array $a, array $b) use ($useBatches, $useSuppliers): int {
            return self::key($a, $useBatches, $useSuppliers) <=> self::key($b, $useBatches, $useSuppliers);
        });

        return $buckets;
    }

    /** @return list<int|float|string> la chiave d'ordinamento, confrontata elemento per elemento */
    private static function key(array $bucket, bool $useBatches, bool $useSuppliers): array
    {
        $noExpiry = $bucket['expiry'] === '' || str_starts_with($bucket['expiry'], '0000-00-00');

        return [
            $bucket['batch_id'] === 0 ? 0 : 1,
            $useBatches && $bucket['batch_id'] > 0 ? ($noExpiry ? 1 : 0) : 0,
            $useBatches && $bucket['batch_id'] > 0 && !$noExpiry ? substr($bucket['expiry'], 0, 10) : '',
            $bucket['batch_id'],
            $bucket['supplier_id'] === 0 ? 0 : 1,
            $useSuppliers && $bucket['supplier_id'] > 0 ? ($bucket['cost'] === null ? 1 : 0) : 0,
            $useSuppliers && $bucket['supplier_id'] > 0 ? ($bucket['cost'] ?? 0.0) : 0.0,
            $bucket['supplier_id'],
        ];
    }
}
