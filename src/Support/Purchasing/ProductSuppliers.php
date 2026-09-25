<?php

namespace Wonder\Plugin\Gestionale\Support\Purchasing;

use RuntimeException;
use Throwable;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductSupplier;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Numbers;
use Wonder\Sql\Transaction;

/**
 * Da chi si compra ogni opzione: come si leggono, si controllano, si
 * salvano e si tolgono i legami di `gst_product_suppliers`.
 *
 * Le righe arrivano da due parti, il repeater della scheda dell'opzione e il
 * JSON della finestra «Costo» dell'articolo, e passano tutte e due da
 * `normalize()` e `assertValid()`: le regole sono le stesse, e stanno qui.
 *
 * Il legame è il costo **di oggi**, non storia: se ne va davvero, con
 * l'opzione o con la riga. Un legame rimasto su un'opzione tolta terrebbe
 * ferma la chiave esterna del fornitore, e nessuno lo vedrebbe più.
 *
 * Un'opzione è **in vendita** quando né lei né il suo articolo sono stati
 * eliminati: l'elenco degli articoli li mette in `deleted = 'true'` senza
 * toccare le opzioni, e un articolo nel cestino non deve tenere fermo un
 * fornitore.
 */
final class ProductSuppliers
{
    /** Il costo più alto che `cost` DECIMAL(12,4) tiene. */
    public const MAX_COST = 99999999.9999;

    /** La lunghezza di `supplier_sku`, VARCHAR(100). */
    public const SKU_MAX_LENGTH = 100;

    /**
     * Le righe nella forma che si salva, senza quelle vuote e con un
     * preferito solo.
     *
     * Il costo si legge come lo scrive una persona («12,50», «1.234,50 €»),
     * e vuoto resta `null`: vuol dire «non lo so», non zero.
     *
     * @param array<int|string, mixed> $rows
     * @return list<array{supplier_id: int, supplier_sku: string, cost: ?float, is_preferred: string}>
     */
    public static function normalize(array $rows): array
    {
        $normalized = [];

        foreach ($rows as $row) {
            if (!is_array($row) || self::isEmptyRow($row)) {
                continue;
            }

            $cost = Numbers::fromForm(is_scalar($row['cost'] ?? null) ? $row['cost'] : null);

            $normalized[] = [
                'supplier_id' => self::id($row['supplier_id'] ?? null),
                'supplier_sku' => self::text($row['supplier_sku'] ?? null),
                'cost' => $cost === null ? null : (float) $cost,
                'is_preferred' => self::flagged($row) ? 'true' : 'false',
            ];
        }

        return self::preferOne($normalized);
    }

    /**
     * Vero se la riga non dice niente: niente fornitore, codice o costo.
     *
     * Il preferito non conta: la tendina posta sempre il suo «No», e da solo
     * non tiene in piedi una riga.
     *
     * @param array<string, mixed> $row
     */
    public static function isEmptyRow(array $row): bool
    {
        return self::id($row['supplier_id'] ?? null) <= 0
            && self::text($row['supplier_sku'] ?? null) === ''
            && self::text($row['cost'] ?? null) === '';
    }

    /**
     * Un preferito solo: il primo segnato, o la prima riga se non ce n'è.
     *
     * Le altre colonne restano come sono: la scheda dell'opzione ci lascia
     * l'`id` del repeater.
     *
     * @param array<int|string, array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function preferOne(array $rows): array
    {
        $rows = array_values(array_filter($rows, 'is_array'));
        $winner = 0;

        foreach ($rows as $index => $row) {
            if (self::flagged($row)) {
                $winner = $index;
                break;
            }
        }

        foreach ($rows as $index => $row) {
            $rows[$index]['is_preferred'] = $index === $winner ? 'true' : 'false';
        }

        return $rows;
    }

    /**
     * La riga preferita di un elenco, `null` se l'elenco è vuoto.
     *
     * @param array<int|string, array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    public static function preferred(array $rows): ?array
    {
        $rows = self::preferOne($rows);

        foreach ($rows as $row) {
            if ($row['is_preferred'] === 'true') {
                return $row;
            }
        }

        return null;
    }

    /**
     * Rifiuta le righe che non si possono salvare, con una frase.
     *
     * Lavora sulle righe **grezze** del form: un codice o un costo senza
     * fornitore si devono vedere, non sparire. `$allowedSupplierIds` sono i
     * fornitori che la pagina propone; `$labels` (id => nome) serve a dire
     * quale fornitore compare due volte.
     *
     * Il costo è un numero fra zero e `MAX_COST`, il codice non supera
     * `SKU_MAX_LENGTH` caratteri: sono i limiti delle colonne, e oltre MySQL
     * rifiuterebbe la riga quando la scheda è già stata scritta.
     *
     * @param array<int|string, mixed> $rows
     * @param list<int|string> $allowedSupplierIds
     * @param array<int, string> $labels
     * @throws UserError
     */
    public static function assertValid(array $rows, array $allowedSupplierIds, array $labels = []): void
    {
        $allowed = array_flip(array_map('intval', $allowedSupplierIds));
        $seen = [];

        foreach ($rows as $row) {
            if (!is_array($row) || self::isEmptyRow($row)) {
                continue;
            }

            $supplierId = self::id($row['supplier_id'] ?? null);

            if ($supplierId <= 0) {
                throw UserError::make('product.supplier_missing');
            }

            if (!isset($allowed[$supplierId])) {
                throw UserError::make('product.supplier_invalid');
            }

            if (mb_strlen(self::text($row['supplier_sku'] ?? null)) > self::SKU_MAX_LENGTH) {
                throw UserError::make('product.supplier_sku_too_long', ['max' => (string) self::SKU_MAX_LENGTH]);
            }

            $raw = $row['cost'] ?? null;
            $cost = Numbers::fromForm(is_scalar($raw) ? $raw : null);

            // Scritto ma illeggibile: non diventa «non lo so» in silenzio.
            if ($cost === null && $raw !== null && (!is_scalar($raw) || self::text($raw) !== '')) {
                throw UserError::make('product.supplier_cost_invalid');
            }

            if ($cost !== null && (float) $cost < 0) {
                throw UserError::make('product.supplier_cost_negative');
            }

            if ($cost !== null && round((float) $cost, 4) > self::MAX_COST) {
                throw UserError::make('product.supplier_cost_too_high');
            }

            if (isset($seen[$supplierId])) {
                $label = trim((string) ($labels[$supplierId] ?? ''));

                throw UserError::make('product.supplier_duplicate', [
                    'supplier' => $label !== '' ? $label : (string) $supplierId,
                ]);
            }

            $seen[$supplierId] = true;
        }
    }

    /**
     * I legami di certe opzioni, per opzione e in ordine di posizione.
     *
     * @param list<int> $productIds
     * @return array<int, list<array{supplier_id: int, supplier_sku: string, cost: ?float, is_preferred: string}>>
     */
    public static function linksFor(array $productIds): array
    {
        $condition = self::productCondition($productIds);

        if ($condition === null) {
            return [];
        }

        try {
            $rows = ProductSupplier::find($condition, null, 'position ASC, id', 'ASC');
        } catch (Throwable) {
            // Tabella non ancora creata, o nessun database: nessun legame.
            return [];
        }

        $byProduct = [];

        foreach (self::listOf($rows) as $row) {
            $byProduct[(int) ($row['product_id'] ?? 0)][] = $row;
        }

        return array_map(self::normalize(...), $byProduct);
    }

    /**
     * Scrive i legami di un'opzione com'erano nella pagina.
     *
     * Il confronto è per fornitore: chi c'era si aggiorna, chi manca nasce,
     * chi non c'è più se ne va davvero. La posizione è l'ordine delle righe.
     * Due righe dello stesso fornitore qui non arrivano (le ferma
     * `assertValid()`); se arrivano, vale la prima.
     *
     * @param list<array<string, mixed>> $rows righe di `normalize()`
     * @return int quanti legami ha l'opzione dopo il salvataggio
     */
    public static function sync(int $productId, array $rows): int
    {
        if ($productId <= 0) {
            return 0;
        }

        $wanted = [];

        foreach (self::normalize($rows) as $row) {
            if ($row['supplier_id'] > 0 && !isset($wanted[$row['supplier_id']])) {
                $wanted[$row['supplier_id']] = $row;
            }
        }

        $wanted = self::preferOne(array_values($wanted));

        return Transaction::run(static function () use ($productId, $wanted): int {
            $existing = [];
            $stale = [];

            // Anche le righe in `deleted = 'true'`: nessuno ce le mette, ma se
            // ci sono se ne vanno con le altre.
            $rows = ProductSupplier::find(['product_id' => $productId, 'deleted' => ['true', 'false']], null, 'id', 'ASC');

            foreach (self::listOf($rows) as $row) {
                $supplierId = (int) ($row['supplier_id'] ?? 0);

                if (($row['deleted'] ?? 'false') === 'false' && !isset($existing[$supplierId])) {
                    $existing[$supplierId] = (int) $row['id'];
                } else {
                    $stale[] = (int) $row['id'];
                }
            }

            foreach ($wanted as $position => $row) {
                $values = [
                    'product_id' => $productId,
                    'supplier_id' => $row['supplier_id'],
                    'supplier_sku' => $row['supplier_sku'],
                    // Vuoto diventa NULL: il costo resta «non lo so».
                    'cost' => $row['cost'] === null ? '' : number_format($row['cost'], 4, '.', ''),
                    'is_preferred' => $row['is_preferred'],
                    'position' => $position,
                ];

                $saved = isset($existing[$row['supplier_id']])
                    ? ProductSupplier::update($values, $existing[$row['supplier_id']])
                    : ProductSupplier::create($values);

                if (($saved->success ?? false) !== true) {
                    // Un guasto, non una risposta: la transazione riporta
                    // indietro tutto.
                    throw new RuntimeException('Fornitore dell\'opzione non salvato.');
                }

                unset($existing[$row['supplier_id']]);
            }

            $gone = array_merge(array_values($existing), $stale);

            if ($gone !== []) {
                ProductSupplier::query()->Delete(ProductSupplier::$table, 'WHERE id IN ('.implode(',', $gone).')');
            }

            return count($wanted);
        });
    }

    /**
     * Toglie i legami di opzioni che stanno per sparire.
     *
     * La chiave esterna fermerebbe l'eliminazione dell'opzione con un errore
     * del database: chi cancella un'opzione passa prima di qui.
     *
     * @param list<int> $productIds
     * @return int quanti legami se ne sono andati
     */
    public static function dropFor(array $productIds): int
    {
        $condition = self::productCondition($productIds);

        return $condition === null ? 0 : self::deleteWhere($condition);
    }

    /**
     * Toglie i legami delle opzioni di un articolo tolte dalla griglia.
     *
     * La griglia le mette in `deleted = 'true'`, e di un'opzione tolta non
     * serve sapere quanto costava.
     *
     * @return int quanti legami se ne sono andati
     */
    public static function dropRemovedOptions(int $modelId): int
    {
        if ($modelId <= 0) {
            return 0;
        }

        return self::deleteWhere(
            'product_id IN (SELECT id FROM '.Product::$table
            .' WHERE product_model_id = '.$modelId." AND deleted = 'true')"
        );
    }

    /**
     * Toglie i legami di un fornitore con opzioni che non sono più in
     * vendita: chi lo elimina passa prima di qui, perché la chiave esterna
     * non guarda il cestino.
     *
     * @return int quanti legami se ne sono andati
     */
    public static function dropForRemovedProducts(int $contactId): int
    {
        if ($contactId <= 0) {
            return 0;
        }

        return self::deleteWhere('supplier_id = '.$contactId.' AND product_id NOT IN ('.self::liveProducts().')');
    }

    /** Su quante opzioni in vendita un fornitore ha un costo. */
    public static function countForSupplier(int $contactId): int
    {
        if ($contactId <= 0) {
            return 0;
        }

        try {
            return (int) ProductSupplier::query()->Count(
                ProductSupplier::$table,
                'WHERE supplier_id = '.$contactId." AND deleted = 'false'"
                .' AND product_id IN ('.self::liveProducts().')'
            );
        } catch (Throwable) {
            // Tabella non ancora creata, o nessun database: nessun legame.
            return 0;
        }
    }

    /**
     * Il testo accanto al bottone «Costo»: il preferito e il suo costo.
     *
     * @param array<string, mixed>|null $preferredRow
     * @param array<int, string> $choices id => nome dei fornitori
     */
    public static function summary(?array $preferredRow, array $choices): string
    {
        $supplierId = self::id($preferredRow['supplier_id'] ?? null);

        if ($preferredRow === null || $supplierId <= 0) {
            return 'Nessun fornitore';
        }

        $name = trim((string) ($choices[$supplierId] ?? ''));
        $name = $name !== '' ? $name : 'Fornitore n. '.$supplierId;
        $cost = $preferredRow['cost'] ?? null;

        return is_numeric($cost)
            ? $name.' · '.number_format((float) $cost, 2, ',', '.').' €'
            : $name;
    }

    /**
     * Le opzioni in vendita, come sottoquery: né l'opzione né il suo
     * articolo sono stati eliminati.
     */
    private static function liveProducts(): string
    {
        return 'SELECT p.id FROM '.Product::$table.' p'
            .' JOIN '.ProductModel::$table.' m ON m.id = p.product_model_id'
            ." WHERE p.deleted = 'false' AND m.deleted = 'false'";
    }

    /**
     * Conta i legami che rispondono alla condizione, poi li cancella.
     *
     * Il `WHERE` si scrive qui: il core prende così com'è una condizione che
     * contiene già un `WHERE`, e quello di una sottoquery lo ingannerebbe.
     *
     * @return int quanti legami se ne sono andati
     */
    private static function deleteWhere(string $condition): int
    {
        try {
            $count = (int) ProductSupplier::query()->Count(ProductSupplier::$table, 'WHERE '.$condition);

            if ($count > 0) {
                ProductSupplier::query()->Delete(ProductSupplier::$table, 'WHERE '.$condition);
            }

            return $count;
        } catch (Throwable) {
            // Tabella non ancora creata, o nessun database: niente da togliere.
            return 0;
        }
    }

    /** @param list<int> $productIds */
    private static function productCondition(array $productIds): ?string
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $productIds),
            static fn (int $id): bool => $id > 0
        )));

        return $ids === [] ? null : 'product_id IN ('.implode(',', $ids).')';
    }

    /** @return list<array<string, mixed>> */
    private static function listOf(mixed $rows): array
    {
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }

    /** @param array<string, mixed> $row */
    private static function flagged(array $row): bool
    {
        $flag = $row['is_preferred'] ?? null;

        return $flag === true || $flag === 'true';
    }

    private static function id(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
