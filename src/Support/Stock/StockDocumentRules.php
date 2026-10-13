<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Wonder\Plugin\Gestionale\Support\Errors\UserError;

/**
 * Le regole dei documenti di magazzino, senza database (G2b-bis 4.1).
 *
 * Il servizio `StockDocuments` legge e scrive; qui si decide: quali righe
 * sono buone, quando un documento si può confermare, quale movimento nasce da
 * ogni riga e quale storno lo annulla, quando l'atteso di un inventario si
 * rilegge.
 */
final class StockDocumentRules
{
    public const MAX_LINES = 500;

    /** La quantità più alta che `quantity` DECIMAL(10,3) tiene. */
    public const MAX_QUANTITY = 9999999.999;

    /** Il costo più alto che `unit_cost` DECIMAL(10,4) tiene. */
    public const MAX_COST = 999999.9999;

    /** I tipi che si creano oggi: `transfer` arriva con G3. */
    public const TYPES = ['receipt', 'issue', 'stocktake'];

    /** @return array<string, string> */
    public static function labels(): array
    {
        return ['receipt' => 'Carico', 'issue' => 'Scarico', 'stocktake' => 'Inventario'];
    }

    public static function exists(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    /** L'etichetta, o stringa vuota per un tipo che non c'è. */
    public static function label(string $type): string
    {
        return self::labels()[$type] ?? '';
    }

    /** Come si legge nei Movimenti lo storno di un documento annullato. */
    public static function cancelLabel(string $type): string
    {
        return [
            'receipt' => 'Annullamento del carico',
            'issue' => 'Annullamento dello scarico',
            'stocktake' => "Annullamento dell'inventario",
        ][$type] ?? '';
    }

    /** La sequenza di `DocumentSequences`: una per tipo. */
    public static function sequence(string $type): string
    {
        return 'stock_'.$type;
    }

    /**
     * La causale da salvare sul documento. L'inventario ha sempre la sua, il
     * carico nessuna; lo scarico quella scelta, che può mancare finché è bozza.
     */
    public static function reason(string $type, string $reason): string
    {
        $allowed = Reasons::forDocument($type);

        if ($type !== 'issue') {
            return (string) array_key_first($allowed);
        }

        $reason = trim($reason);

        if ($reason !== '' && !array_key_exists($reason, $allowed)) {
            throw UserError::make('stock_document.unknown_reason');
        }

        return $reason;
    }

    /**
     * Le righe del form, controllate. Una riga senza versione si ignora (la
     * pagina può lasciarne una vuota); la casella vuota resta `null`, che non
     * vuol dire zero. `{{row}}` è la riga come la conta chi legge, da 1.
     *
     * Il limite si controlla prima di leggere: 10.000 righe non si leggono
     * per poi rifiutarle. Un `product_id` che non è un intero positivo è un
     * rifiuto, non uno zero: «12abc» non diventa 12.
     *
     * G3, lotti: le versioni di `$batchProducts` stanno su più righe purché
     * con lotti diversi (G3.10); le altre una volta sola. Le chiavi del lotto
     * (`batch_number`, `batch_expiry`) e la scelta a mano dello scarico
     * (`batch_id`, `supplier_id`) escono solo se la riga le porta.
     *
     * @param list<mixed> $input
     * @param list<int> $batchProducts le versioni che gestiscono i lotti
     * @return list<array{product_id: int, quantity: ?float}>
     */
    public static function lines(array $input, array $batchProducts = []): array
    {
        $batchProducts = array_flip(array_map('intval', $batchProducts));

        if (count($input) > self::MAX_LINES) {
            throw UserError::make('stock_document.too_many_rows');
        }

        $lines = [];
        $seen = [];
        $row = 0;

        foreach ($input as $line) {
            $row++;

            if (!is_array($line)) {
                throw UserError::make('stock_document.invalid_lines');
            }

            $productId = self::productId($line['product_id'] ?? null);

            if ($productId === 0) {
                continue;
            }

            $batchNumber = array_key_exists('batch_number', $line) ? self::batchNumber($line['batch_number']) : null;
            $key = isset($batchProducts[$productId]) ? $productId.'|'.mb_strtolower((string) $batchNumber) : (string) $productId;

            // Due righe ancora senza lotto si tengono: il lotto lo chiede la conferma.
            if (isset($seen[$key]) && !(isset($batchProducts[$productId]) && (string) $batchNumber === '')) {
                throw UserError::make(isset($batchProducts[$productId]) ? 'stock_document.duplicate_batch' : 'stock_document.duplicate_product', ['row' => $row]);
            }

            $seen[$key] = true;
            $wanted = ['product_id' => $productId, 'quantity' => self::quantity($line['quantity'] ?? null, $row)];

            // G3, lotti: lotto e scadenza del carico, lotto e fornitore scelti nello scarico.
            if ($batchNumber !== null) {
                $wanted['batch_number'] = $batchNumber;
            }

            if (array_key_exists('batch_expiry', $line)) {
                $wanted['batch_expiry'] = self::expiry($line['batch_expiry'], $row);
            }

            foreach (['batch_id', 'supplier_id'] as $choice) {
                if (array_key_exists($choice, $line)) {
                    $wanted[$choice] = self::choice($line[$choice]);
                }
            }

            // G3, Acquisti: il costo del carico, solo se la riga lo porta.
            if (array_key_exists('cost', $line)) {
                $wanted['unit_cost'] = self::cost($line['cost'], $row);
            }

            $lines[] = $wanted;
        }

        return $lines;
    }

    /**
     * Quello che la conferma chiede. Righe numerate nell'ordine del documento.
     *
     * Nel carico una versione con i lotti vuole il lotto su ogni riga (G3.9).
     *
     * @param list<array{quantity: ?float}> $lines
     * @param list<int> $batchProducts le versioni che gestiscono i lotti
     */
    public static function assertConfirmable(string $type, string $reason, array $lines, array $batchProducts = []): void
    {
        if ($type === 'receipt' && $batchProducts !== []) {
            $tracking = array_flip(array_map('intval', $batchProducts));

            foreach (array_values($lines) as $i => $line) {
                if (isset($tracking[(int) ($line['product_id'] ?? 0)]) && trim((string) ($line['batch_number'] ?? '')) === '') {
                    throw UserError::make('stock_document.batch_required', ['row' => $i + 1]);
                }
            }
        }

        if ($type === 'issue' && trim($reason) === '') {
            throw UserError::make('stock_document.reason_required');
        }

        if ($lines === []) {
            throw UserError::make('stock_document.no_rows');
        }

        if ($type === 'stocktake') {
            foreach ($lines as $line) {
                if ($line['quantity'] !== null) {
                    return;
                }
            }

            throw UserError::make('stock_document.nothing_counted');
        }

        foreach (array_values($lines) as $i => $line) {
            if ($line['quantity'] === null || $line['quantity'] <= 0) {
                throw UserError::make('stock_document.quantity_required', ['row' => $i + 1]);
            }
        }
    }

    /**
     * Il movimento che nasce da una riga, o null se la riga non muove niente.
     * Per l'inventario `expected_quantity` c'è sempre quando c'è il contato:
     * lo garantisce il servizio.
     *
     * @param array{quantity: ?float, expected_quantity?: ?float} $line
     * @return array{type: string, reason: string, quantity: float}|null
     */
    public static function movement(string $type, string $reason, array $line): ?array
    {
        $quantity = $line['quantity'];

        if ($quantity === null) {
            return null;
        }

        $quantity = round($quantity, 3);

        return match ($type) {
            'receipt' => $quantity > 0 ? ['type' => 'purchase', 'reason' => '', 'quantity' => $quantity] : null,
            'issue' => $quantity > 0 ? ['type' => 'adjustment', 'reason' => $reason, 'quantity' => -$quantity] : null,
            'stocktake' => self::difference($quantity, (float) ($line['expected_quantity'] ?? 0)),
            default => null,
        };
    }

    /**
     * Lo storno di un movimento: stesso tipo, stessa causale, segno opposto.
     *
     * @param array<string, mixed> $movement una riga di `gst_stock_movements`
     * @return array{type: string, reason: string, quantity: float}
     */
    public static function reversal(array $movement): array
    {
        return [
            'type' => (string) $movement['type'],
            'reason' => (string) ($movement['reason'] ?? ''),
            'quantity' => -round((float) $movement['quantity'], 3),
        ];
    }

    /**
     * L'atteso di una riga d'inventario. Si legge la giacenza di adesso quando
     * il numero contato è nuovo o cambia, o quando cambia la sede; con lo
     * stesso numero resta quello di prima, così una vendita fatta fra il
     * salvataggio e la conferma resta.
     *
     * @param array{quantity: ?float, expected_quantity: ?float}|null $old la riga salvata prima
     */
    public static function expected(?array $old, ?float $counted, bool $locationChanged, float $current): ?float
    {
        if ($counted === null) {
            return null;
        }

        if ($old === null || $locationChanged || $old['quantity'] === null || $old['expected_quantity'] === null
            || round((float) $old['quantity'], 3) !== round($counted, 3)) {
            return round($current, 3);
        }

        return round((float) $old['expected_quantity'], 3);
    }

    /** @return array{type: string, reason: string, quantity: float}|null */
    private static function difference(float $counted, float $expected): ?array
    {
        $difference = round($counted - $expected, 3);

        return $difference === 0.0 || $difference === -0.0
            ? null
            : ['type' => 'adjustment', 'reason' => Reasons::DEFAULT, 'quantity' => $difference];
    }

    /** La versione di una riga: 0 se la riga è vuota, un rifiuto se non è un intero positivo. */
    private static function productId(mixed $raw): int
    {
        if ($raw === null || $raw === '' || $raw === 0 || $raw === '0') {
            return 0;
        }

        if (is_int($raw) && $raw > 0) {
            return $raw;
        }

        if (is_string($raw) && preg_match('/^[1-9]\d{0,9}$/D', trim($raw)) === 1) {
            return (int) trim($raw);
        }

        throw UserError::make('stock_document.invalid_lines');
    }

    /**
     * Fornitore e documento del fornitore di un carico (G3.7), letti dalla
     * testata: fornitore 0 = nessuno, numero tagliato a 50 caratteri, data
     * `Y-m-d` valida o null. Che il fornitore esista lo controlla il servizio.
     *
     * @param array<string, mixed> $header
     * @return array{supplier_id: int, supplier_document_number: string, supplier_document_date: ?string}
     */
    public static function supplierHeader(array $header): array
    {
        $supplierId = $header['supplier_id'] ?? 0;
        $supplierId = is_int($supplierId) || (is_string($supplierId) && preg_match('/^\d{1,10}$/D', trim($supplierId)) === 1)
            ? max(0, (int) $supplierId)
            : 0;
        $date = trim((string) ($header['supplier_document_date'] ?? ''));

        if ($date !== '') {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

            if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                throw UserError::make('stock_document.invalid_date');
            }
        }

        return [
            'supplier_id' => $supplierId,
            'supplier_document_number' => mb_substr(trim((string) ($header['supplier_document_number'] ?? '')), 0, 50),
            'supplier_document_date' => $date === '' ? null : $date,
        ];
    }

    /** Il numero del lotto come si salva: senza spazi ai lati, al massimo 50 caratteri. */
    public static function batchNumber(mixed $value): string
    {
        return is_scalar($value) && !is_bool($value) ? mb_substr(trim((string) $value), 0, 50) : '';
    }

    /** La scadenza di una riga: `Y-m-d` valida o null; il resto è un rifiuto con la riga. */
    public static function expiry(mixed $value, int $row): ?string
    {
        $date = is_scalar($value) && !is_bool($value) ? trim((string) $value) : '';

        if ($date === '') {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw UserError::make('stock_document.invalid_expiry', ['row' => $row]);
        }

        return $date;
    }

    /** Un lotto o un fornitore scelti a mano: un intero positivo, o 0 = automatico. */
    private static function choice(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }

        return is_string($value) && preg_match('/^\d{1,10}$/D', trim($value)) === 1 ? (int) trim($value) : 0;
    }

    /**
     * Il costo di una riga di carico, IVA esclusa: vuoto = non lo so (null,
     * non zero); «2,50» si legge; negativo o storto è un rifiuto.
     */
    public static function cost(mixed $value, int $row): ?float
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        $cost = is_int($value) || is_float($value) ? (float) $value : Stocktake::quantity($value);

        if ($cost === null || !is_finite($cost) || $cost < 0 || $cost > self::MAX_COST) {
            throw UserError::make('stock_document.invalid_cost', ['row' => $row]);
        }

        return round($cost, 4);
    }

    private static function quantity(mixed $value, int $row): ?float
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        $quantity = Stocktake::quantity($value);

        if ($quantity === null || !is_finite($quantity) || $quantity < 0 || $quantity > self::MAX_QUANTITY) {
            throw UserError::make('stock_document.invalid_quantity', ['row' => $row]);
        }

        return $quantity;
    }
}
