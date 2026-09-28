<?php

namespace Wonder\Plugin\Gestionale\Support\Purchasing;

use RuntimeException;
use Throwable;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelSupplier;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductSupplier;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Numbers;
use Wonder\Sql\Transaction;

/**
 * Da chi si compra ogni articolo e ogni opzione: come si leggono, si
 * controllano, si salvano e si tolgono i legami di
 * `gst_product_model_suppliers` (l'articolo) e `gst_product_suppliers`
 * (l'eccezione dell'opzione).
 *
 * I fornitori si scrivono sull'articolo e valgono per tutte le opzioni.
 * Un'opzione può avere righe sue: per lo stesso fornitore vince la sua, e un
 * fornitore solo suo si accoda a quelli dell'articolo (`effective()`).
 *
 * Le righe arrivano dai repeater delle due schede e passano tutte da
 * `normalize()` e `assertValid()`: le regole sono le stesse, e stanno qui.
 *
 * Il legame è il costo **di oggi**, non storia: se ne va davvero, con
 * l'articolo, con l'opzione o con la riga. Un legame rimasto su un'opzione
 * tolta terrebbe ferma la chiave esterna del fornitore, e nessuno lo
 * vedrebbe più.
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
     * Le righe nella forma che si salva, senza quelle vuote.
     *
     * Il costo si legge come lo scrive una persona («12,50», «1.234,50 €»),
     * e vuoto resta `null`: vuol dire «non lo so», non zero.
     *
     * @param array<int|string, mixed> $rows
     * @return list<array{supplier_id: int, supplier_sku: string, cost: ?float}>
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
            ];
        }

        return $normalized;
    }

    /**
     * Vero se la riga non dice niente: niente fornitore, codice o costo.
     *
     * L'`id` del repeater non conta: da solo non tiene in piedi una riga.
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
     * I fornitori che valgono per un'opzione: quelli dell'articolo, con la
     * riga dell'opzione al posto di quella dell'articolo per lo stesso
     * fornitore, e i fornitori solo dell'opzione in coda.
     *
     * Puro: le righe passano da `normalize()`, così vanno bene sia quelle
     * lette dal database sia quelle grezze di un form.
     *
     * @param array<int|string, mixed> $modelRows righe dell'articolo
     * @param array<int|string, mixed> $optionRows righe dell'opzione
     * @return list<array{supplier_id: int, supplier_sku: string, cost: ?float}>
     */
    public static function effective(array $modelRows, array $optionRows): array
    {
        $overrides = [];

        foreach (self::normalize($optionRows) as $row) {
            if ($row['supplier_id'] > 0 && !isset($overrides[$row['supplier_id']])) {
                $overrides[$row['supplier_id']] = $row;
            }
        }

        $rows = [];

        foreach (self::normalize($modelRows) as $row) {
            if ($row['supplier_id'] <= 0 || isset($rows[$row['supplier_id']])) {
                continue;
            }

            $rows[$row['supplier_id']] = $overrides[$row['supplier_id']] ?? $row;
            unset($overrides[$row['supplier_id']]);
        }

        return array_values($rows + $overrides);
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
     * I legami di certi articoli, per articolo e in ordine di posizione.
     *
     * @param list<int> $modelIds
     * @return array<int, list<array{supplier_id: int, supplier_sku: string, cost: ?float}>>
     */
    public static function modelLinksFor(array $modelIds): array
    {
        return self::linksOf(ProductModelSupplier::class, 'product_model_id', $modelIds);
    }

    /**
     * Le eccezioni di certe opzioni, per opzione e in ordine di posizione.
     *
     * @param list<int> $productIds
     * @return array<int, list<array{supplier_id: int, supplier_sku: string, cost: ?float}>>
     */
    public static function linksFor(array $productIds): array
    {
        return self::linksOf(ProductSupplier::class, 'product_id', $productIds);
    }

    /**
     * Scrive i fornitori di un articolo com'erano nella pagina.
     *
     * @param list<array<string, mixed>> $rows righe di `normalize()`
     * @return int quanti fornitori ha l'articolo dopo il salvataggio
     */
    public static function syncModel(int $modelId, array $rows): int
    {
        return self::write(ProductModelSupplier::class, 'product_model_id', $modelId, $rows);
    }

    /**
     * Scrive le eccezioni di un'opzione com'erano nella pagina.
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
        return self::write(ProductSupplier::class, 'product_id', $productId, $rows);
    }

    /**
     * Toglie i fornitori di articoli che stanno per sparire.
     *
     * @param list<int> $modelIds
     * @return int quanti legami se ne sono andati
     */
    public static function dropForModels(array $modelIds): int
    {
        $condition = self::inCondition('product_model_id', $modelIds);

        return $condition === null ? 0 : self::deleteWhere(ProductModelSupplier::class, $condition);
    }

    /**
     * Toglie le eccezioni di opzioni che stanno per sparire.
     *
     * La chiave esterna fermerebbe l'eliminazione dell'opzione con un errore
     * del database: chi cancella un'opzione passa prima di qui.
     *
     * @param list<int> $productIds
     * @return int quanti legami se ne sono andati
     */
    public static function dropFor(array $productIds): int
    {
        $condition = self::inCondition('product_id', $productIds);

        return $condition === null ? 0 : self::deleteWhere(ProductSupplier::class, $condition);
    }

    /**
     * Toglie le eccezioni delle opzioni di un articolo tolte dalla griglia.
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
            ProductSupplier::class,
            'product_id IN (SELECT id FROM '.Product::$table
            .' WHERE product_model_id = '.$modelId." AND deleted = 'true')"
        );
    }

    /**
     * Toglie le eccezioni di un fornitore su opzioni che non sono più in
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

        return self::deleteWhere(
            ProductSupplier::class,
            'supplier_id = '.$contactId.' AND product_id NOT IN ('.self::liveProducts().')'
        );
    }

    /**
     * Toglie i legami di un fornitore con articoli nel cestino: il gemello
     * di `dropForRemovedProducts()` per la tabella dell'articolo.
     *
     * @return int quanti legami se ne sono andati
     */
    public static function dropForRemovedModels(int $contactId): int
    {
        if ($contactId <= 0) {
            return 0;
        }

        return self::deleteWhere(
            ProductModelSupplier::class,
            'supplier_id = '.$contactId.' AND product_model_id NOT IN ('.self::liveModels().')'
        );
    }

    /** Su quanti articoli e opzioni in vendita un fornitore ha un costo. */
    public static function countForSupplier(int $contactId): int
    {
        if ($contactId <= 0) {
            return 0;
        }

        try {
            return (int) ProductModelSupplier::query()->Count(
                ProductModelSupplier::$table,
                'WHERE supplier_id = '.$contactId." AND deleted = 'false'"
                .' AND product_model_id IN ('.self::liveModels().')'
            ) + (int) ProductSupplier::query()->Count(
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
     * I legami di una tabella per padre, in ordine di posizione.
     *
     * @param class-string<ProductModelSupplier|ProductSupplier> $modelClass
     * @param list<int> $parentIds
     * @return array<int, list<array{supplier_id: int, supplier_sku: string, cost: ?float}>>
     */
    private static function linksOf(string $modelClass, string $parentKey, array $parentIds): array
    {
        $condition = self::inCondition($parentKey, $parentIds);

        if ($condition === null) {
            return [];
        }

        try {
            $rows = $modelClass::find($condition, null, 'position ASC, id', 'ASC');
        } catch (Throwable) {
            // Tabella non ancora creata, o nessun database: nessun legame.
            return [];
        }

        $byParent = [];

        foreach (self::listOf($rows) as $row) {
            $byParent[(int) ($row[$parentKey] ?? 0)][] = $row;
        }

        return array_map(self::normalize(...), $byParent);
    }

    /**
     * Scrive i legami di un padre com'erano nella pagina, in una
     * transazione.
     *
     * @param class-string<ProductModelSupplier|ProductSupplier> $modelClass
     * @param list<array<string, mixed>> $rows
     * @return int quanti legami ha il padre dopo il salvataggio
     */
    private static function write(string $modelClass, string $parentKey, int $parentId, array $rows): int
    {
        if ($parentId <= 0) {
            return 0;
        }

        $wanted = [];

        foreach (self::normalize($rows) as $row) {
            if ($row['supplier_id'] > 0 && !isset($wanted[$row['supplier_id']])) {
                $wanted[$row['supplier_id']] = $row;
            }
        }

        $wanted = array_values($wanted);

        return Transaction::run(static function () use ($modelClass, $parentKey, $parentId, $wanted): int {
            $existing = [];
            $stale = [];

            // Anche le righe in `deleted = 'true'`: nessuno ce le mette, ma se
            // ci sono se ne vanno con le altre.
            $rows = $modelClass::find([$parentKey => $parentId, 'deleted' => ['true', 'false']], null, 'id', 'ASC');

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
                    $parentKey => $parentId,
                    'supplier_id' => $row['supplier_id'],
                    'supplier_sku' => $row['supplier_sku'],
                    // Vuoto diventa NULL: il costo resta «non lo so».
                    'cost' => $row['cost'] === null ? '' : number_format($row['cost'], 4, '.', ''),
                    'position' => $position,
                ];

                $saved = isset($existing[$row['supplier_id']])
                    ? $modelClass::update($values, $existing[$row['supplier_id']])
                    : $modelClass::create($values);

                if (($saved->success ?? false) !== true) {
                    // Un guasto, non una risposta: la transazione riporta
                    // indietro tutto.
                    throw new RuntimeException('Fornitore non salvato.');
                }

                unset($existing[$row['supplier_id']]);
            }

            $gone = array_merge(array_values($existing), $stale);

            if ($gone !== []) {
                $modelClass::query()->Delete($modelClass::$table, 'WHERE id IN ('.implode(',', $gone).')');
            }

            return count($wanted);
        });
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

    /** Gli articoli in vendita, come sottoquery. */
    private static function liveModels(): string
    {
        return 'SELECT m.id FROM '.ProductModel::$table." m WHERE m.deleted = 'false'";
    }

    /**
     * Conta i legami che rispondono alla condizione, poi li cancella.
     *
     * Il `WHERE` si scrive qui: il core prende così com'è una condizione che
     * contiene già un `WHERE`, e quello di una sottoquery lo ingannerebbe.
     *
     * @param class-string<ProductModelSupplier|ProductSupplier> $modelClass
     * @return int quanti legami se ne sono andati
     */
    private static function deleteWhere(string $modelClass, string $condition): int
    {
        try {
            $count = (int) $modelClass::query()->Count($modelClass::$table, 'WHERE '.$condition);

            if ($count > 0) {
                $modelClass::query()->Delete($modelClass::$table, 'WHERE '.$condition);
            }

            return $count;
        } catch (Throwable) {
            // Tabella non ancora creata, o nessun database: niente da togliere.
            return 0;
        }
    }

    /** @param list<int> $ids */
    private static function inCondition(string $column, array $ids): ?string
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0
        )));

        return $ids === [] ? null : $column.' IN ('.implode(',', $ids).')';
    }

    /** @return list<array<string, mixed>> */
    private static function listOf(mixed $rows): array
    {
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
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
