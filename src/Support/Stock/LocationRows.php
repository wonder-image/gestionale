<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Wonder\Plugin\Gestionale\Support\Catalog\SaleUnits;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

/**
 * Le righe «Giacenza per sede» della scheda, pure: da mostrare e da leggere.
 *
 * Con due o più sedi da mostrare la scheda non parla più del totale: ogni
 * riga dice la sua sede, i suoi pezzi e la sua scorta minima. Qui non c'è
 * nessuna lettura né scrittura; chi salva passa da `Stocktake`, `Stock` e
 * `Thresholds`.
 */
final class LocationRows
{
    /**
     * Le righe da mostrare: una per ogni sede che ha pezzi o una soglia,
     * nell'ordine delle sedi. Una sede fuori dall'elenco non si vede, anche
     * con pezzi: non è più una sede del magazzino.
     *
     * @param list<array{id: int, label: string}> $locations le sedi da mostrare (`Locations::shown()`)
     * @param array<int, array{quantity: float}> $levels per sede (`Levels::byLocation()`)
     * @param array<int, float> $thresholds per sede (`Thresholds::forProduct()`)
     * @return list<array{location_id: int, stock: float, min_stock: float}>
     */
    public static function compose(array $locations, array $levels, array $thresholds): array
    {
        $rows = [];

        foreach ($locations as $location) {
            $id = (int) ($location['id'] ?? 0);
            $stock = round((float) ($levels[$id]['quantity'] ?? 0), 3);
            $threshold = round((float) ($thresholds[$id] ?? 0), 3);

            if ($id > 0 && ($stock !== 0.0 || $threshold > 0)) {
                $rows[] = ['location_id' => $id, 'stock' => $stock, 'min_stock' => $threshold];
            }
        }

        return $rows;
    }

    /**
     * Le righe scritte nel campo nascosto della griglia (JSON), o già in
     * array dal repeater. `null` se non sono righe: chi salva lascia stare.
     *
     * @return list<array<string, mixed>>|null
     */
    public static function fromForm(mixed $value): ?array
    {
        if (is_string($value)) {
            $value = trim($value) === '' ? null : json_decode($value, true, 8);
        }

        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }

        foreach ($value as $row) {
            if (!is_array($row)) {
                return null;
            }
        }

        return $value;
    }

    /**
     * Le righe scritte, pulite: sede intera, numeri letti come li scrive una
     * persona, casella della giacenza vuota = `null` (non toccare).
     *
     * Una riga senza sede non si scrive. La stessa sede due volte e una sede
     * fuori dall'elenco sono errori: il salvataggio non parte.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<array{id: int, label: string}> $locations le sedi ammesse
     * @return list<array{location_id: int, stock: float|null, min_stock: float}>
     * @throws UserError
     */
    public static function normalize(array $rows, array $locations): array
    {
        $labels = [];

        foreach ($locations as $location) {
            $labels[(int) ($location['id'] ?? 0)] = (string) ($location['label'] ?? '');
        }

        $seen = [];
        $clean = [];

        foreach ($rows as $row) {
            $raw = $row['location_id'] ?? null;
            $id = is_array($raw) ? 0 : (int) $raw;

            if ($id <= 0 && trim((string) (is_array($raw) ? '' : $raw)) === '') {
                continue;
            }

            if (!isset($labels[$id])) {
                throw UserError::make('stock.location_unknown');
            }

            if (isset($seen[$id])) {
                throw UserError::make('stock.location_duplicate', ['location' => $labels[$id] !== '' ? $labels[$id] : '#'.$id]);
            }

            $seen[$id] = true;
            $clean[] = [
                'location_id' => $id,
                'stock' => is_array($row['stock'] ?? null) ? null : Stocktake::quantity($row['stock'] ?? null),
                'min_stock' => self::threshold($row['min_stock'] ?? null),
            ];
        }

        return $clean;
    }

    /**
     * @param list<array{location_id: int, min_stock: float}> $rows righe pulite
     * @return array<int, float> `[sede => soglia]`, pronta per `Thresholds::save()`
     */
    public static function thresholds(array $rows): array
    {
        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row['location_id']] = (float) $row['min_stock'];
        }

        return $map;
    }

    /**
     * @param list<array{location_id: int, stock: float|null}> $rows righe pulite
     * @return array<int, float> `[sede => quantità voluta]`, senza le caselle vuote
     */
    public static function quantities(array $rows): array
    {
        $map = [];

        foreach ($rows as $row) {
            if ($row['stock'] !== null) {
                $map[(int) $row['location_id']] = (float) $row['stock'];
            }
        }

        return $map;
    }

    /**
     * Il riassunto accanto al bottone della griglia: «Milano 12 · Roma 3».
     * Le sedi senza pezzi non si nominano.
     *
     * @param list<array{id: int, label: string}> $locations
     * @param array<int, array{quantity: float}> $levels per sede
     */
    public static function summary(array $locations, array $levels): string
    {
        $parts = [];

        foreach ($locations as $location) {
            $id = (int) ($location['id'] ?? 0);
            $stock = round((float) ($levels[$id]['quantity'] ?? 0), 3);

            if ($stock !== 0.0) {
                $parts[] = trim((string) ($location['label'] ?? '')).' '.LowStockEmail::quantity($stock);
            }
        }

        return implode(' · ', $parts);
    }

    /**
     * Lo stesso riassunto, dalle righe scritte nel form: quello che la
     * finestra ha messo nel JSON, nell'ordine delle righe. Serve quando la
     * scheda torna dopo un errore, e i pezzi scritti non sono ancora salvati.
     * Una casella vuota, uno zero e una sede fuori dall'elenco non si nominano.
     *
     * @param list<array{id: int, label: string}> $locations
     * @param list<array<string, mixed>> $rows come le dà `fromForm()`
     */
    public static function summaryOfRows(array $locations, array $rows): string
    {
        $labels = [];

        foreach ($locations as $location) {
            $labels[(int) ($location['id'] ?? 0)] = trim((string) ($location['label'] ?? ''));
        }

        $parts = [];

        foreach ($rows as $row) {
            $raw = $row['location_id'] ?? null;
            $id = is_array($raw) ? 0 : (int) $raw;
            $stock = is_array($row['stock'] ?? null) ? null : Stocktake::quantity($row['stock'] ?? null);

            if (isset($labels[$id]) && $stock !== null && $stock !== 0.0) {
                $parts[] = $labels[$id].' '.LowStockEmail::quantity($stock);
            }
        }

        return implode(' · ', $parts);
    }

    /**
     * Come si scrivono le caselle delle righe: i decimali dell'unità
     * dell'articolo e l'unità in coda, come la giacenza della scheda.
     *
     * Si guarda ogni sede, non la somma: 1,5 + 1,5 fa 3, e una casella
     * arrotondata salverebbe un movimento che nessuno ha chiesto.
     *
     * @param array<array-key, float> $quantities le giacenze, sede per sede
     * @param array<array-key, float> $thresholds le soglie, sede per sede
     * @return array{stock: int, min_stock: int, suffix: string}
     */
    public static function format(string $unit, array $quantities, array $thresholds): array
    {
        $numbers = static fn (array $values): array => array_values(array_map('floatval', $values));

        return [
            'stock' => SaleUnits::decimalsFor($unit, ...$numbers($quantities)),
            'min_stock' => SaleUnits::decimalsFor($unit, ...$numbers($thresholds)),
            'suffix' => SaleUnits::suffix($unit),
        ];
    }

    /** Vuota vale zero, cioè «non avvisarmi»; il resto deve essere un numero non negativo. */
    private static function threshold(mixed $raw): float
    {
        if (is_array($raw)) {
            throw UserError::make('product.min_stock_invalid');
        }

        if (trim((string) ($raw ?? '')) === '') {
            return 0.0;
        }

        $quantity = Stocktake::quantity($raw);

        if ($quantity === null || $quantity < 0) {
            throw UserError::make('product.min_stock_invalid');
        }

        return $quantity;
    }
}
