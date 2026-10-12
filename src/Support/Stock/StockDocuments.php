<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use DateTimeImmutable;
use RuntimeException;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Locations\Location;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocument;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocumentItem;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocumentStatusLog;
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
     * @param array{user_id?: int} $options
     */
    public static function create(string $type, array $header, array $lines, array $options = []): int
    {
        if (!StockDocumentRules::exists($type)) {
            throw UserError::make('stock_document.unknown_type');
        }

        return Transaction::run(static function () use ($type, $header, $lines, $options): int {
            $locationId = self::location((int) ($header['location_id'] ?? 0));
            $reason = StockDocumentRules::reason($type, (string) ($header['reason'] ?? ''));
            $wanted = StockDocumentRules::lines($lines);

            $created = StockDocument::create([
                'code' => Code::make(StockDocument::class, Codes::STOCK_DOCUMENT),
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
            $wanted = StockDocumentRules::lines($lines);
            $old = [];

            foreach (self::items($id) as $item) {
                $old[$item['product_id']] = $item;
            }

            StockDocument::update(['location_id' => $locationId, 'reason' => $reason, 'note' => $note], $id);
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

            StockDocumentRules::assertConfirmable($type, $reason, $items);

            $userId = (int) ($options['user_id'] ?? 0);
            $source = StatusLogger::normalizeSource((string) ($options['source'] ?? 'user'));
            $now = new DateTimeImmutable();
            $number = DocumentSequences::next(StockDocumentRules::sequence($type), $now);

            foreach ($items as $item) {
                $movement = StockDocumentRules::movement($type, $reason, $item);

                if ($movement === null) {
                    continue;
                }

                Stock::apply($movement + [
                    'product_id' => $item['product_id'],
                    'location_id' => (int) $document['location_id'],
                    'reference_type' => 'stock_document',
                    'reference_id' => $id,
                    'user_id' => $userId,
                ]);
            }

            StockDocument::update(['status' => 'completed', 'number' => $number, 'date' => $now->format('Y-m-d')], $id);
            self::log($id, 'draft', 'completed', $source, $userId);

            return $number;
        });
    }

    /**
     * Le righe del documento, nell'ordine.
     *
     * @return list<array{id: int, product_id: int, quantity: ?float, expected_quantity: ?float, position: int}>
     */
    public static function items(int $id): array
    {
        $rows = self::rows(StockDocumentItem::find(['stock_document_id' => $id, 'deleted' => 'false'], null, 'position', 'ASC'));

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'product_id' => (int) $row['product_id'],
            'quantity' => self::nullable($row['quantity'] ?? null),
            'expected_quantity' => self::nullable($row['expected_quantity'] ?? null),
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

        foreach ($lines as $i => $line) {
            $expected = $type === 'stocktake'
                ? StockDocumentRules::expected(
                    $old[$line['product_id']] ?? null,
                    $line['quantity'],
                    $locationChanged,
                    (float) ($levels[$line['product_id']][$locationId]['quantity'] ?? 0)
                )
                : null;

            StockDocumentItem::create([
                'stock_document_id' => $id,
                'product_id' => $line['product_id'],
                'quantity' => self::decimal($line['quantity']),
                'expected_quantity' => self::decimal($expected),
                'position' => $i + 1,
            ]);
        }
    }

    /** @param list<int> $productIds */
    private static function assertProducts(array $productIds): void
    {
        if ($productIds === []) {
            return;
        }

        $found = self::rows(Product::find(
            '`id` IN ('.implode(', ', array_map('intval', $productIds)).") AND `deleted` = 'false'",
            null, null, null, 'id'
        ));
        $known = array_map('intval', array_column($found, 'id'));

        foreach ($productIds as $productId) {
            if (!in_array($productId, $known, true)) {
                throw UserError::make('stock.product_missing');
            }
        }
    }

    /** La sede del documento: vuota = la principale; deve tenere merce. */
    private static function location(int $locationId): int
    {
        $locationId = $locationId ?: Locations::mainId();

        if (!self::isStockLocation($locationId)) {
            throw UserError::make('stock_document.location_unknown');
        }

        return $locationId;
    }

    private static function isStockLocation(int $locationId): bool
    {
        return $locationId > 0
            && self::rows(Location::find(['id' => $locationId, 'has_stock' => 'true', 'deleted' => 'false'], 1)) !== [];
    }

    /** Il documento, bloccato fino alla fine della transazione. */
    private static function locked(int $id): array
    {
        $document = StockDocument::findForUpdate(['id' => $id], 1);

        if (!is_array($document) || $document === [] || (string) ($document['deleted'] ?? 'false') === 'true') {
            throw UserError::make('stock_document.not_found');
        }

        return $document;
    }

    /** Il documento bloccato, purché sia ancora una bozza. */
    private static function draft(int $id): array
    {
        $document = self::locked($id);

        if ((string) $document['status'] !== 'draft') {
            throw UserError::make('stock_document.not_draft');
        }

        return $document;
    }

    private static function log(int $id, string $from, string $to, string $source, int $userId): void
    {
        StatusLogger::record(StockDocumentStatusLog::class, $id, 'status', $from, $to, $source, $userId ?: null);
    }

    private static function decimal(?float $value): ?string
    {
        return $value === null ? null : number_format($value, 3, '.', '');
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
