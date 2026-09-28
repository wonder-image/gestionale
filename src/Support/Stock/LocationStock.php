<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Wonder\Plugin\Gestionale\Support\Errors\UserError;

/**
 * Salva quello che la scheda scrive per sede: soglie e giacenze.
 *
 * Le righe arrivano già pulite da {@see LocationRows::normalize()}. Le
 * soglie si scrivono **prima** di muovere un pezzo — ogni movimento rinfresca
 * l'avviso con la soglia che trova nel database, e con quella vecchia
 * giacenza e soglia cambiate insieme aprirebbero avvisi finti. Le giacenze
 * diventano un movimento per sede cambiata, con la differenza fra quanto
 * c'è e quanto è stato scritto: la scheda non sovrascrive, rettifica.
 *
 * Lo usano la scheda dell'articolo e quella dell'opzione in vendita, così
 * scrivono le stesse cose nello stesso modo.
 */
final class LocationStock
{
    /**
     * @param list<array{location_id: int, stock: ?float, min_stock: float}> $rows
     * @param bool $isNew il prodotto è appena nato: la giacenza è quella
     *                    iniziale, non una rettifica
     */
    public static function apply(int $productId, array $rows, bool $isNew): void
    {
        if ($productId <= 0) {
            return;
        }

        Thresholds::save($productId, LocationRows::thresholds($rows));

        $wanted = LocationRows::quantities($rows);

        if ($wanted === []) {
            return;
        }

        foreach (self::movements(self::current($productId), $wanted, $isNew) as $movement) {
            Stock::apply(['product_id' => $productId] + $movement);
        }
    }

    /**
     * Ferma una giacenza per sede scritta sotto zero (P59).
     *
     * Va chiamato prima che il salvataggio cominci: `apply()` gira dopo
     * l'insert, fuori da qualunque try, e lì un rifiuto sarebbe una pagina
     * di guasto su una scheda scritta a metà — o, con la vendita senza
     * giacenza, una rettifica sotto zero. La giacenza di adesso si legge
     * solo se c'è un numero negativo da confrontare.
     *
     * @param list<array{location_id: int, stock: ?float, min_stock: float}> $rows
     */
    public static function assertNotNegative(int $productId, array $rows): void
    {
        if (!self::hasNegative($rows)) {
            return;
        }

        $current = $productId > 0 ? self::current($productId) : [];

        if (self::negativeWritten($current, LocationRows::quantities($rows))) {
            throw UserError::make('product.stock_negative');
        }
    }

    /**
     * Fra le righe c'è una giacenza sotto zero.
     *
     * @param list<array{location_id: int, stock: ?float, min_stock: float}> $rows
     */
    public static function hasNegative(array $rows): bool
    {
        $wanted = LocationRows::quantities($rows);

        return $wanted !== [] && min($wanted) < 0;
    }

    /**
     * Un numero sotto zero diverso da quello che la sede ha già.
     *
     * Una sede già sotto zero per le vendite in arretrato, riscritta
     * com'era, passa: non l'ha scritta nessuno.
     *
     * @param array<int, float> $current giacenza di adesso, `[locationId => q]`
     * @param array<int, float> $wanted giacenza scritta, `[locationId => q]`
     */
    public static function negativeWritten(array $current, array $wanted): bool
    {
        foreach ($wanted as $locationId => $quantity) {
            if ($quantity < 0 && abs($quantity - (float) ($current[$locationId] ?? 0)) > 0.0005) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, float> la giacenza di adesso, `[locationId => q]` */
    private static function current(int $productId): array
    {
        $current = [];

        foreach (Levels::byLocation($productId) as $locationId => $level) {
            $current[(int) $locationId] = (float) ($level['quantity'] ?? 0);
        }

        return $current;
    }

    /**
     * I movimenti da registrare, uno per sede cambiata, nell'ordine scritto.
     *
     * Per un articolo nuovo si carica la giacenza iniziale, e solo dove è
     * positiva: uno zero o un numero sotto zero su una scheda appena nata
     * non hanno niente da rettificare.
     *
     * @param array<int, float> $current giacenza di adesso, `[locationId => q]`
     * @param array<int, float> $wanted giacenza scritta, `[locationId => q]`
     * @return list<array{location_id: int, quantity: float, reason: string, note: string}>
     */
    public static function movements(array $current, array $wanted, bool $isNew): array
    {
        $movements = [];

        foreach (Stocktake::changes($current, $wanted) as $locationId => $change) {
            if ($isNew && $change['delta'] <= 0) {
                continue;
            }

            $movements[] = [
                'location_id' => (int) $locationId,
                'quantity' => $change['delta'],
                'reason' => $isNew ? 'initial_stock' : Reasons::DEFAULT,
                'note' => $isNew
                    ? 'Giacenza iniziale, dalla scheda dell\'articolo'
                    : 'Rettifica dalla scheda dell\'articolo',
            ];
        }

        return $movements;
    }
}
