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
 * I fornitori sono del prodotto: l'articolo senza varianti li ha sul suo
 * unico prodotto, quello con le varianti su ogni opzione. Non c'è niente da
 * sommare: quello che vale per un'opzione è quello che ha scritto.
 *
 * Le righe arrivano dalle due schede, dalla finestra «Fornitori»
 * (`fromJson()`) o dai due campi del fornitore unico (`fromFields()`), e
 * passano tutte da `normalize()` e `assertValid()`: le regole sono le
 * stesse, e stanno qui.
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
     * Le righe dei due campi del fornitore unico, grezze: nessuna se sono
     * vuoti tutti e due, una se ne basta uno compilato.
     *
     * Grezze perché passano da `assertValid()`: un costo scritto male si
     * deve vedere, non sparire.
     *
     * @return list<array{supplier_id: int, supplier_sku: mixed, cost: mixed}>
     */
    public static function fromFields(int $supplierId, mixed $sku, mixed $cost): array
    {
        if ($supplierId <= 0 || (self::text($sku) === '' && self::text($cost) === '' && !is_array($cost))) {
            return [];
        }

        return [['supplier_id' => $supplierId, 'supplier_sku' => $sku ?? '', 'cost' => $cost ?? '']];
    }

    /**
     * I legami di un'opzione con quello di un fornitore solo riscritto: al
     * suo posto se c'era, in coda se non c'era, via se `$rows` è vuoto.
     *
     * È quello che fanno i due campi del fornitore unico: gli altri legami
     * dell'opzione non li vedono, e non li toccano.
     *
     * @param array<int|string, mixed> $links i legami dell'opzione
     * @param array<int|string, mixed> $rows le righe di `fromFields()`
     * @return list<array{supplier_id: int, supplier_sku: string, cost: ?float}>
     */
    public static function replaceOne(array $links, int $supplierId, array $rows): array
    {
        $row = null;

        foreach (self::normalize($rows) as $candidate) {
            if ($candidate['supplier_id'] === $supplierId) {
                $row = $candidate;
                break;
            }
        }

        $replaced = [];
        $found = false;

        foreach (self::normalize($links) as $link) {
            if ($link['supplier_id'] !== $supplierId) {
                $replaced[] = $link;
                continue;
            }

            if (!$found && $row !== null) {
                $replaced[] = $row;
            }

            $found = true;
        }

        if (!$found && $row !== null) {
            $replaced[] = $row;
        }

        return $replaced;
    }

    /**
     * Le righe del campo nascosto della finestra «Fornitori»; `null` se è
     * vuoto o non è un elenco — la finestra non l'ha toccato.
     *
     * @return list<array<string, mixed>>|null
     */
    public static function fromJson(mixed $json): ?array
    {
        if (!is_string($json) || trim($json) === '') {
            return null;
        }

        try {
            $rows = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($rows) && array_is_list($rows)
            ? array_values(array_filter($rows, 'is_array'))
            : null;
    }

    /**
     * Il costo si scrive con due decimali e si tiene con quattro: se la
     * casella dice lo stesso numero arrotondato, resta quello salvato.
     *
     * @param list<array<string, mixed>> $posted le righe della pagina
     * @param list<array<string, mixed>> $stored i legami dell'opzione, da `linksFor()`
     * @return list<array<string, mixed>>
     */
    public static function keepStoredCosts(array $posted, array $stored): array
    {
        $costs = [];

        foreach ($stored as $row) {
            if (is_array($row) && is_numeric($row['cost'] ?? null)) {
                $costs[self::id($row['supplier_id'] ?? null)] = (float) $row['cost'];
            }
        }

        foreach ($posted as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $supplierId = self::id($row['supplier_id'] ?? null);
            $written = Numbers::fromForm(is_scalar($row['cost'] ?? null) ? $row['cost'] : null);

            if (isset($costs[$supplierId]) && $written !== null
                && round((float) $written, 2) === round($costs[$supplierId], 2)) {
                $posted[$index]['cost'] = number_format($costs[$supplierId], 4, '.', '');
            }
        }

        return $posted;
    }

    /**
     * Il riassunto accanto al bottone «Fornitori»: i nomi in ordine, con il
     * costo solo dove c'è — «Filati Nord 12,00 € · Lana Sud».
     *
     * @param array<int|string, mixed> $rows le righe dell'opzione, anche grezze
     * @param array<int, string> $names id => nome del fornitore
     */
    public static function summary(array $rows, array $names): string
    {
        $parts = [];

        foreach (self::normalize($rows) as $row) {
            $supplierId = $row['supplier_id'];

            if ($supplierId <= 0 || isset($parts[$supplierId])) {
                continue;
            }

            $name = trim((string) ($names[$supplierId] ?? ''));
            $name = $name !== '' ? $name : 'Fornitore n. '.$supplierId;

            $parts[$supplierId] = $row['cost'] === null
                ? $name
                : $name.' '.number_format($row['cost'], 2, ',', '.').' €';
        }

        return $parts === [] ? 'Nessun fornitore' : implode(' · ', $parts);
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
     * @return array<int, list<array{supplier_id: int, supplier_sku: string, cost: ?float}>>
     */
    public static function linksFor(array $productIds): array
    {
        $condition = self::inCondition('product_id', $productIds);

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
     * Scrive i fornitori di un'opzione com'erano nella pagina, in una
     * transazione.
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

        $wanted = array_values($wanted);

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
                    'position' => $position,
                ];

                $saved = isset($existing[$row['supplier_id']])
                    ? ProductSupplier::update($values, $existing[$row['supplier_id']])
                    : ProductSupplier::create($values);

                if (($saved->success ?? false) !== true) {
                    // Un guasto, non una risposta: la transazione riporta
                    // indietro tutto.
                    throw new RuntimeException('Fornitore non salvato.');
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
     * Toglie i fornitori di opzioni che stanno per sparire.
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

        return $condition === null ? 0 : self::deleteWhere($condition);
    }

    /**
     * Toglie i fornitori delle opzioni di un articolo tolte dalla griglia.
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

        return self::deleteWhere(
            'supplier_id = '.$contactId.' AND product_id NOT IN ('.self::liveProducts().')'
        );
    }

    /** Su quante opzioni in vendita un fornitore ha un legame. */
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
