<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use DateTimeImmutable;
use RuntimeException;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductSupplier;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Stock\Stock as StockRow;
use Wonder\Plugin\Gestionale\Models\Locations\Location;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocument;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocumentItem;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocumentStatusLog;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Documents\DocumentSequences;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Status\StatusLogger;
use Wonder\Sql\Transaction;

/**
 * I documenti di magazzino: creare e salvare la bozza, confermare, annullare,
 * duplicare, eliminare (G2b-bis 4.1).
 *
 * Tutto dentro una transazione, con il documento bloccato: due conferme
 * contemporanee si mettono in fila e la seconda trova il documento già
 * confermato. Le giacenze si scrivono solo con `Stock::apply()`, una riga alla
 * volta: o entra tutto o niente. Le regole stanno in `StockDocumentRules`.
 */
final class StockDocuments
{
    /**
     * Crea una bozza.
     *
     * @param array{location_id?: int, reason?: string, note?: string} $header sede vuota = la principale
     * @param list<array{product_id: int, quantity: mixed}> $lines
     * @param array{user_id?: int, code?: string} $options `code` vuoto = un codice nuovo
     */
    public static function create(string $type, array $header, array $lines, array $options = []): int
    {
        if (!StockDocumentRules::exists($type)) {
            throw UserError::make('stock_document.unknown_type');
        }

        return Transaction::run(static function () use ($type, $header, $lines, $options): int {
            $locationId = self::location((int) ($header['location_id'] ?? 0));
            $reason = StockDocumentRules::reason($type, (string) ($header['reason'] ?? ''));
            $wanted = StockDocumentRules::lines($lines, self::batchProducts($type, $lines));
            $supplier = self::supplierFields($type, $header, null);

            $created = StockDocument::create($supplier + [
                'code' => trim((string) ($options['code'] ?? '')) ?: Code::make(StockDocument::class, Codes::STOCK_DOCUMENT),
                'type' => $type,
                'status' => 'draft',
                'location_id' => $locationId,
                'reason' => $reason,
                'note' => trim((string) ($header['note'] ?? '')),
                'user_id' => (int) ($options['user_id'] ?? 0),
            ]);
            $id = (int) ($created->insert_id ?? 0);

            if ($id <= 0) {
                throw new RuntimeException('Documento di magazzino non creato: '.json_encode($created));
            }

            self::writeItems($id, $type, $locationId, $wanted, [], false);

            return $id;
        });
    }

    /**
     * Salva la bozza. Una chiave che manca in `$header` tiene il valore di
     * prima; le righe invece si riscrivono tutte, nell'ordine dato.
     *
     * @param array{location_id?: int, reason?: string, note?: string} $header
     * @param list<array{product_id: int, quantity: mixed}> $lines
     */
    public static function saveDraft(int $id, array $header, array $lines): void
    {
        Transaction::run(static function () use ($id, $header, $lines): void {
            $document = self::draft($id);
            $type = (string) $document['type'];
            $before = (int) $document['location_id'];
            $locationId = array_key_exists('location_id', $header) ? self::location((int) $header['location_id']) : $before;
            $reason = array_key_exists('reason', $header)
                ? StockDocumentRules::reason($type, (string) $header['reason'])
                : (string) ($document['reason'] ?? '');
            $note = array_key_exists('note', $header) ? trim((string) $header['note']) : (string) ($document['note'] ?? '');
            $wanted = StockDocumentRules::lines($lines, self::batchProducts($type, $lines));
            $old = [];

            foreach (self::items($id) as $item) {
                $old[$item['product_id']] = $item;
            }

            StockDocument::update(
                self::supplierFields($type, $header, $document) + ['location_id' => $locationId, 'reason' => $reason, 'note' => $note],
                $id
            );
            self::writeItems($id, $type, $locationId, $wanted, $old, $locationId !== $before);
        });
    }

    /**
     * Conferma la bozza: numero, data, stato, log e un movimento per riga, in
     * una transazione sola. Uno scarico che porterebbe sotto zero si ferma con
     * `stock.insufficient` e la bozza resta com'era, senza numero.
     *
     * @param array{user_id?: int, source?: string} $options
     * @return string il numero del documento
     */
    public static function confirm(int $id, array $options = []): string
    {
        return Transaction::run(static function () use ($id, $options): string {
            $document = self::draft($id);
            $type = (string) $document['type'];
            $reason = (string) ($document['reason'] ?? '');
            $items = self::items($id);

            $tracking = self::batchProducts($type, $items);
            StockDocumentRules::assertConfirmable($type, $reason, $items, $tracking);

            $userId = (int) ($options['user_id'] ?? 0);
            $source = StatusLogger::normalizeSource((string) ($options['source'] ?? 'user'));
            $now = new DateTimeImmutable();
            $number = DocumentSequences::next(StockDocumentRules::sequence($type), $now);

            // In ordine di versione, non di riga: vedi `cancel()`.
            $ordered = $items;
            usort($ordered, static fn (array $x, array $y): int => $x['product_id'] <=> $y['product_id']);

            $purchasing = Gestionale::feature('purchasing');
            $supplierId = $type === 'receipt' && $purchasing ? (int) ($document['supplier_id'] ?? 0) : 0;

            foreach ($ordered as $item) {
                $movement = StockDocumentRules::movement($type, $reason, $item);

                if ($movement === null) {
                    continue;
                }

                $base = [
                    'product_id' => $item['product_id'],
                    'location_id' => (int) $document['location_id'],
                    'reference_type' => 'stock_document',
                    'reference_id' => $id,
                    'reference_item_id' => (int) $item['id'],
                    'user_id' => $userId,
                ];

                if ($movement['quantity'] < 0) {
                    // Scarico e inventario in meno: dai secchi, come la vendita (G3.13, G3.14);
                    // nello scarico lotto e fornitore scelti a mano restringono (G3.12).
                    self::takeOut($movement, $base + ($type === 'issue' ? self::chosen($item) : []), $type === 'stocktake');

                    continue;
                }

                if ($type !== 'receipt') {
                    // Inventario in più: nel secchio 0, lotto e fornitore sconosciuti (G3.14).
                    Stock::apply($movement + $base);

                    continue;
                }

                // Lo stesso lock di vendite e scarichi, prima di ogni secchio: chi
                // scrive sulla stessa versione si mette in fila invece di
                // bloccarsi a vicenda fra il secchio del fornitore e il secchio 0.
                StockBuckets::locked($item['product_id'], (int) $document['location_id']);
                $cost = $purchasing ? $item['unit_cost'] : null;
                // G3, lotti: il lotto si trova o nasce adesso, e la riga lo ricorda (G3.10).
                $batchId = in_array($item['product_id'], $tracking, true)
                    ? Batches::resolve($item['product_id'], (string) $item['batch_number'], $item['batch_expiry'], $supplierId, (int) $item['position'])
                    : 0;

                if ($batchId > 0) {
                    StockDocumentItem::update(['batch_id' => $batchId], (int) $item['id']);
                }

                Stock::apply($movement + $base + ['batch_id' => $batchId, 'supplier_id' => $supplierId, 'unit_cost' => $cost ?? 0.0]);
                self::compensate($base, $batchId, $supplierId, $movement['quantity'], $cost ?? 0.0);
                self::rememberCost($item['product_id'], $supplierId, $cost);
            }

            StockDocument::update(['status' => 'completed', 'number' => $number, 'date' => $now->format('Y-m-d')], $id);
            self::log($id, 'draft', 'completed', $source, $userId);

            return $number;
        });
    }

    /**
     * Annulla un documento confermato: per ogni suo movimento uno storno con lo
     * stesso tipo e la stessa causale, segno opposto. Se uno storno porterebbe
     * sotto zero (i pezzi caricati sono già venduti) si ferma con
     * `stock.insufficient` e il documento resta confermato.
     *
     * @param array{user_id?: int, source?: string} $options
     */
    public static function cancel(int $id, array $options = []): void
    {
        Transaction::run(static function () use ($id, $options): void {
            $document = self::locked($id);

            if ((string) $document['status'] !== 'completed') {
                throw UserError::make('stock_document.cannot_cancel');
            }

            $userId = (int) ($options['user_id'] ?? 0);
            $source = StatusLogger::normalizeSource((string) ($options['source'] ?? 'user'));
            $movements = self::rows(StockMovement::find(
                "`reference_type` = 'stock_document' AND `reference_id` = ".$id." AND `deleted` = 'false'",
                null, 'id', 'ASC'
            ));

            // In ordine di versione, come la conferma: due operazioni sugli
            // stessi articoli bloccano le righe di giacenza nello stesso
            // ordine e si mettono in fila invece di bloccarsi a vicenda.
            // Dentro la stessa versione dall'ultimo al primo: la compensazione
            // di un carico (G3.15) si storna prima del carico che l'ha data.
            usort($movements, static fn (array $x, array $y): int =>
                [(int) $x['product_id'], (int) $y['id']] <=> [(int) $y['product_id'], (int) $x['id']]);

            foreach ($movements as $movement) {
                Stock::apply(StockDocumentRules::reversal($movement) + [
                    'product_id' => (int) $movement['product_id'],
                    'location_id' => (int) $movement['location_id'],
                    'batch_id' => (int) ($movement['batch_id'] ?? 0),
                    'supplier_id' => (int) ($movement['supplier_id'] ?? 0),
                    'reference_type' => 'stock_document_cancel',
                    'reference_id' => $id,
                    'reference_item_id' => (int) ($movement['reference_item_id'] ?? 0),
                    'user_id' => $userId,
                    // Lo storno di una compensazione (G3.15) rimette il secchio 0
                    // sotto zero com'era prima del carico: non è merce che manca.
                    'allow_negative' => (string) ($movement['reason'] ?? '') === 'batch_assignment',
                ]);
            }

            StockDocument::update(['status' => 'cancelled'], $id);
            self::log($id, 'completed', 'cancelled', $source, $userId);
        });
    }

    /**
     * Una bozza nuova dello stesso tipo, da un documento in qualsiasi stato:
     * sede, causale, nota e righe. Le versioni sparite si saltano; la sede, se
     * non tiene più merce, diventa la principale. In un inventario gli attesi
     * si rileggono adesso.
     *
     * @param array{user_id?: int} $options
     */
    public static function duplicate(int $id, array $options = []): int
    {
        return Transaction::run(static function () use ($id, $options): int {
            // L'originale si legge e basta: bloccarlo fermerebbe per niente
            // una conferma o un annullamento dello stesso documento.
            $document = StockDocument::findById($id);

            if (!is_array($document) || !isset($document['id']) || (string) ($document['deleted'] ?? 'false') === 'true') {
                throw UserError::make('stock_document.not_found');
            }

            $type = (string) $document['type'];
            $items = self::items($id);
            $alive = self::rows(Product::find(
                '`id` IN ('.implode(', ', array_merge([0], array_column($items, 'product_id'))).") AND `deleted` = 'false'",
                null, null, null, 'id'
            ));
            $kept = array_map('intval', array_column($alive, 'id'));
            $lines = [];

            // Con «Acquisti» il carico copiato tiene fornitore e costi, non il
            // documento del fornitore: è un'altra consegna.
            $purchase = $type === 'receipt' && Gestionale::feature('purchasing');

            foreach ($items as $item) {
                if (in_array($item['product_id'], $kept, true)) {
                    $lines[] = ['product_id' => $item['product_id'], 'quantity' => $item['quantity']]
                        + ($purchase ? ['cost' => $item['unit_cost']] : []);
                }
            }

            $locationId = (int) $document['location_id'];
            $supplierId = (int) ($document['supplier_id'] ?? 0);
            $supplierAlive = $purchase && $supplierId > 0
                && self::rows(Contact::find(['id' => $supplierId, 'is_supplier' => 'true', 'deleted' => 'false'], 1)) !== [];

            return self::create($type, [
                'location_id' => self::isStockLocation($locationId) ? $locationId : 0,
                'reason' => (string) ($document['reason'] ?? ''),
                'note' => (string) ($document['note'] ?? ''),
            ] + ($purchase ? ['supplier_id' => $supplierAlive ? $supplierId : 0] : []), $lines, $options);
        });
    }

    /**
     * Il controllo dell'endpoint che cancella (piano 5): un `RuntimeException`,
     * che `api/backend/delete` trasforma nel messaggio.
     */
    public static function assertDeletable(int $id): void
    {
        $document = StockDocument::findById($id);

        if (!is_array($document) || !isset($document['id']) || (string) ($document['deleted'] ?? 'false') === 'true') {
            throw UserError::refusal('stock_document.not_found');
        }

        if ((string) $document['status'] !== 'draft') {
            throw UserError::refusal('stock_document.cannot_delete');
        }
    }

    /** Elimina una bozza con le sue righe. Una bozza non ha log: la creazione non si registra. */
    public static function delete(int $id): void
    {
        Transaction::run(static function () use ($id): void {
            $document = self::locked($id);

            if ((string) $document['status'] !== 'draft') {
                throw UserError::make('stock_document.cannot_delete');
            }

            sqlDelete(StockDocumentItem::$table, '`stock_document_id` = '.$id);
            StockDocument::delete($id);
        });
    }

    /**
     * Le righe del documento, nell'ordine.
     *
     * @return list<array{id: int, product_id: int, quantity: ?float, expected_quantity: ?float, unit_cost: ?float, position: int}>
     */
    public static function items(int $id): array
    {
        $rows = self::rows(StockDocumentItem::find(['stock_document_id' => $id, 'deleted' => 'false'], null, 'position', 'ASC'));

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'product_id' => (int) $row['product_id'],
            'quantity' => self::nullable($row['quantity'] ?? null),
            'expected_quantity' => self::nullable($row['expected_quantity'] ?? null),
            'unit_cost' => self::nullableCost($row['unit_cost'] ?? null),
            'batch_number' => (string) ($row['batch_number'] ?? ''),
            'batch_expiry' => self::date($row['batch_expiry'] ?? null),
            'batch_id' => (int) ($row['batch_id'] ?? 0),
            'supplier_id' => (int) ($row['supplier_id'] ?? 0),
            'position' => (int) $row['position'],
        ], $rows);
    }

    /**
     * Riscrive le righe. In un inventario l'atteso viene da `expected()`, con
     * la giacenza di adesso letta una volta sola per tutte le righe.
     *
     * @param list<array{product_id: int, quantity: ?float}> $lines già passate da `StockDocumentRules::lines()`
     * @param array<int, array{quantity: ?float, expected_quantity: ?float}> $old le righe di prima, per versione
     */
    private static function writeItems(int $id, string $type, int $locationId, array $lines, array $old, bool $locationChanged): void
    {
        $productIds = array_column($lines, 'product_id');
        self::assertProducts($productIds);
        $levels = $type === 'stocktake' ? Levels::byLocationForProducts($productIds) : [];

        sqlDelete(StockDocumentItem::$table, '`stock_document_id` = '.$id);
        $total = 0.0;

        foreach ($lines as $i => $line) {
            $expected = $type === 'stocktake'
                ? StockDocumentRules::expected(
                    $old[$line['product_id']] ?? null,
                    $line['quantity'],
                    $locationChanged,
                    (float) ($levels[$line['product_id']][$locationId]['quantity'] ?? 0)
                )
                : null;

            // Il costo del carico (G3.7): quello scritto, o quello di prima se la
            // pagina non l'ha mandato (Acquisti spenta tiene i dati, nascosti).
            $cost = $type === 'receipt'
                ? (array_key_exists('unit_cost', $line) ? $line['unit_cost'] : ($old[$line['product_id']]['unit_cost'] ?? null))
                : null;
            $lineTotal = $cost !== null && $line['quantity'] !== null ? round($cost * $line['quantity'], 2) : null;
            $total += $lineTotal ?? 0.0;

            // G3, lotti: quello che la pagina manda, o quello di prima se non lo
            // manda (con la funzionalità spenta i dati restano, nascosti).
            $before = $old[$line['product_id']] ?? [];
            $lot = [];

            foreach (['batch_number' => '', 'batch_expiry' => null, 'batch_id' => 0, 'supplier_id' => 0] as $key => $empty) {
                $lot[$key] = array_key_exists($key, $line) ? $line[$key] : ($before[$key] ?? $empty);
            }

            StockDocumentItem::create([
                'stock_document_id' => $id,
                'product_id' => $line['product_id'],
                'quantity' => self::decimal($line['quantity']),
                'expected_quantity' => self::decimal($expected),
                'batch_number' => $type === 'receipt' ? (string) $lot['batch_number'] : '',
                'batch_expiry' => $type === 'receipt' ? (string) ($lot['batch_expiry'] ?? '') : '',
                'batch_id' => $type === 'issue' ? (int) $lot['batch_id'] : 0,
                'supplier_id' => $type === 'issue' ? (int) $lot['supplier_id'] : 0,
                'unit_cost' => $cost === null ? null : number_format($cost, 4, '.', ''),
                'line_total' => $lineTotal === null ? null : number_format($lineTotal, 2, '.', ''),
                'position' => $i + 1,
            ]);
        }

        if ($type === 'receipt') {
            StockDocument::update(['total_cost' => number_format(round($total, 2), 2, '.', '')], $id);
        }
    }

    /**
     * Fornitore e documento del fornitore di un carico, da scrivere sul
     * documento (G3.7). Solo per il carico e solo con «Acquisti»; con
     * `$document` (la bozza che c'è) le chiavi che mancano tengono il valore
     * di prima. Un fornitore che non è un contatto fornitore è un rifiuto.
     *
     * @param array<string, mixed> $header
     * @param array<string, mixed>|null $document
     * @return array<string, mixed>
     */
    private static function supplierFields(string $type, array $header, ?array $document): array
    {
        $keys = ['supplier_id', 'supplier_document_number', 'supplier_document_date'];

        if ($type !== 'receipt' || !Gestionale::feature('purchasing')
            || ($document !== null && array_intersect($keys, array_keys($header)) === [])) {
            return [];
        }

        $stored = $document === null ? [] : array_intersect_key($document, array_flip($keys));
        $fields = StockDocumentRules::supplierHeader(array_intersect_key($header, array_flip($keys)) + $stored);

        if ($fields['supplier_id'] > 0
            && self::rows(Contact::find(['id' => $fields['supplier_id'], 'is_supplier' => 'true', 'deleted' => 'false'], 1)) === []) {
            throw UserError::make('stock_document.supplier_unknown');
        }

        $fields['supplier_document_date'] ??= '';

        return $fields;
    }

    /**
     * Le uscite di scarico e inventario (G3.13, G3.14), divise fra i secchi
     * da `StockOut`; l'inventario prende anche i lotti scaduti: la merce che
     * manca è quella che manca.
     *
     * @param array{type: string, reason: string, quantity: float} $movement quantità negativa
     * @param array<string, mixed> $base
     */
    private static function takeOut(array $movement, array $base, bool $withExpired): void
    {
        StockOut::take($movement + $base, $withExpired);
    }

    /**
     * La compensazione della vendita scoperta (G3.15): se il secchio 0 di
     * questa versione e sede è sotto zero, il secchio appena caricato gli
     * cede i pezzi, con due movimenti `batch_assignment`. Il secchio 0 non
     * compensa sé stesso.
     *
     * @param array<string, mixed> $base
     */
    private static function compensate(array $base, int $batchId, int $supplierId, float $loaded, float $cost): void
    {
        if ($batchId === 0 && $supplierId === 0) {
            return;
        }

        $zero = StockRow::findForUpdate([
            'product_id' => (int) $base['product_id'],
            'location_id' => (int) $base['location_id'],
            'batch_id' => 0,
            'supplier_id' => 0,
            'deleted' => 'false',
        ], 1);
        $hole = is_array($zero) && isset($zero['quantity']) ? -round((float) $zero['quantity'], 3) : 0.0;
        $moved = round(min($hole, $loaded), 3);

        if ($moved <= 0) {
            return;
        }

        $assignment = ['type' => 'adjustment', 'reason' => 'batch_assignment', 'unit_cost' => $cost] + $base;
        Stock::apply(['quantity' => -$moved, 'batch_id' => $batchId, 'supplier_id' => $supplierId] + $assignment);
        Stock::apply(['quantity' => $moved, 'batch_id' => 0, 'supplier_id' => 0] + $assignment);
    }

    /**
     * Il costo del carico diventa il costo di oggi del fornitore per
     * quell'opzione (G3.8): si aggiorna il legame, o si crea in fondo. Solo
     * costi maggiori di zero: un costo non scritto non è un'informazione, e
     * uno zero (merce omaggio) farebbe del fornitore il più conveniente per
     * sempre.
     */
    private static function rememberCost(int $productId, int $supplierId, ?float $cost): void
    {
        if ($supplierId <= 0 || $cost === null || $cost <= 0) {
            return;
        }

        $links = self::rows(ProductSupplier::find(['product_id' => $productId, 'deleted' => 'false']));
        $value = number_format($cost, 4, '.', '');

        foreach ($links as $link) {
            if ((int) $link['supplier_id'] === $supplierId) {
                ProductSupplier::update(['cost' => $value], (int) $link['id']);

                return;
            }
        }

        $position = 0;

        foreach ($links as $link) {
            $position = max($position, (int) ($link['position'] ?? 0) + 1);
        }

        ProductSupplier::create([
            'product_id' => $productId,
            'supplier_id' => $supplierId,
            'supplier_sku' => '',
            'cost' => $value,
            'position' => $position,
        ]);
    }

    /**
     * Le versioni del documento che gestiscono i lotti: contano solo nel
     * carico e nello scarico, con la funzionalità accesa.
     *
     * @param list<mixed> $lines righe del form o della tabella
     * @return list<int>
     */
    private static function batchProducts(string $type, array $lines): array
    {
        if (!in_array($type, ['receipt', 'issue'], true)) {
            return [];
        }

        $ids = [];

        foreach ($lines as $line) {
            $id = is_array($line) ? ($line['product_id'] ?? 0) : 0;
            $ids[] = is_int($id) || (is_string($id) && ctype_digit(trim($id))) ? (int) $id : 0;
        }

        return $type === 'receipt' ? Batches::trackingProducts($ids) : [];
    }

    /**
     * Lotto e fornitore scelti a mano su una riga di scarico (G3.12), solo
     * con la loro funzionalità; un lotto di un'altra versione è un rifiuto.
     *
     * @param array{product_id: int, batch_id?: int, supplier_id?: int, position?: int} $item
     * @return array{batch_id?: int, supplier_id?: int}
     */
    private static function chosen(array $item): array
    {
        $out = [];
        $batchId = (int) ($item['batch_id'] ?? 0);
        $supplierId = (int) ($item['supplier_id'] ?? 0);

        if ($batchId > 0 && Gestionale::feature('batch_tracking')) {
            if (!Batches::belongsTo($batchId, (int) $item['product_id'])) {
                throw UserError::make('batch.wrong_product', ['row' => (int) ($item['position'] ?? 0)]);
            }

            $out['batch_id'] = $batchId;
        }

        if ($supplierId > 0 && Gestionale::feature('purchasing')) {
            $out['supplier_id'] = $supplierId;
        }

        return $out;
    }

    private static function date(mixed $value): ?string
    {
        $date = substr(trim((string) $value), 0, 10);

        return $date === '' || str_starts_with($date, '0000') ? null : $date;
    }

    private static function nullableCost(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : round((float) $value, 4);
    }

    private static function nullable(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : round((float) $value, 3);
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $result): array
    {
        if (!is_array($result) || $result === []) {
            return [];
        }

        return isset($result['id']) ? [$result] : array_values(array_filter($result, 'is_array'));
    }
}
