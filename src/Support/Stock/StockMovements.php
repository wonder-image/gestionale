<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Sql\Transaction;

/**
 * Da quali secchi è uscita la merce di una riga d'ordine, e dove rimetterla
 * (G3.5, spec §2.5).
 *
 * Ogni movimento di vendita porta la riga d'ordine in `reference_item_id`.
 * Il netto di una riga per secchio è quello che le vendite hanno tolto meno
 * quello che annulli e resi hanno già rimesso; un rientro riempie i secchi
 * dall'ultimo uscito al primo, e quello che avanza va nel secchio 0.
 *
 * `net()` e `refill()` sono pure; `netOut()` legge i movimenti della riga.
 */
final class StockMovements
{
    /** I tipi che contano per il netto di una riga d'ordine. */
    private const TYPES = ['sale', 'sale_cancel', 'return'];

    /**
     * @return list<array{batch_id: int, supplier_id: int, quantity: float}> nell'ordine di uscita
     */
    public static function netOut(int $orderItemId): array
    {
        if ($orderItemId <= 0) {
            return [];
        }

        // Con il lock dentro una transazione, come `Returns::returned()`: due
        // rientri della stessa riga non leggono lo stesso netto.
        $read = Transaction::active() ? StockMovement::findForUpdate(...) : StockMovement::find(...);
        $rows = $read(
            '`reference_item_id` = '.$orderItemId
            ." AND `reference_type` IN ('order', 'sales_return')"
            ." AND `type` IN ('sale', 'sale_cancel', 'return') AND `deleted` = 'false'",
            null,
            'id',
            'ASC'
        );

        return self::net(!is_array($rows) || $rows === [] ? [] : (isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'))));
    }

    /**
     * Il netto per secchio dei movimenti di una riga, letti in ordine di id.
     * Un secchio entra nell'ordine con la sua prima vendita; quelli a zero
     * (tutto già rientrato) restano fuori.
     *
     * @param list<array<string, mixed>> $movements
     * @return list<array{batch_id: int, supplier_id: int, quantity: float}>
     */
    public static function net(array $movements): array
    {
        $net = [];

        foreach ($movements as $movement) {
            $type = (string) ($movement['type'] ?? '');

            if (!in_array($type, self::TYPES, true)) {
                continue;
            }

            $key = (int) ($movement['batch_id'] ?? 0).'/'.(int) ($movement['supplier_id'] ?? 0);
            $net[$key] ??= ['batch_id' => (int) ($movement['batch_id'] ?? 0), 'supplier_id' => (int) ($movement['supplier_id'] ?? 0), 'quantity' => 0.0];
            // La vendita è negativa, annulli e resi positivi: il netto uscito è l'opposto della somma.
            $net[$key]['quantity'] = round($net[$key]['quantity'] - (float) ($movement['quantity'] ?? 0), 3);
        }

        return array_values(array_filter($net, static fn (array $bucket): bool => $bucket['quantity'] > 0));
    }

    /**
     * Dove rimettere `quantity` pezzi: dall'ultimo secchio uscito al primo,
     * mai più di quanto ognuno abbia dato; il resto (movimenti di prima di
     * G3, o una riga senza movimenti) nel secchio 0.
     *
     * @param list<array{batch_id: int, supplier_id: int, quantity: float}> $netOut
     * @return list<array{batch_id: int, supplier_id: int, quantity: float}>
     */
    public static function refill(array $netOut, float $quantity): array
    {
        $left = round($quantity, 3);
        $parts = [];

        foreach (array_reverse($netOut) as $bucket) {
            if ($left <= 0) {
                break;
            }

            $take = round(min($left, (float) $bucket['quantity']), 3);

            if ($take <= 0) {
                continue;
            }

            $parts[] = ['batch_id' => (int) $bucket['batch_id'], 'supplier_id' => (int) $bucket['supplier_id'], 'quantity' => $take];
            $left = round($left - $take, 3);
        }

        if ($left > 0) {
            $parts = self::addToZero($parts, $left);
        }

        return $parts;
    }

    /**
     * Aggiunge pezzi al secchio 0 della lista: alla sua parte se c'è già,
     * altrimenti in una parte nuova in fondo. Così con il solo secchio 0 c'è
     * sempre un movimento solo.
     *
     * @param list<array{batch_id: int, supplier_id: int, quantity: float}> $parts
     * @return list<array{batch_id: int, supplier_id: int, quantity: float}>
     */
    public static function addToZero(array $parts, float $quantity): array
    {
        foreach ($parts as $i => $part) {
            if ($part['batch_id'] === 0 && $part['supplier_id'] === 0) {
                $parts[$i]['quantity'] = round($part['quantity'] + $quantity, 3);

                return $parts;
            }
        }

        $parts[] = ['batch_id' => 0, 'supplier_id' => 0, 'quantity' => round($quantity, 3)];

        return $parts;
    }
}
