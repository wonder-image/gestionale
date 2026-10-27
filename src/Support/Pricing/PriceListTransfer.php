<?php

namespace Wonder\Plugin\Gestionale\Support\Pricing;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Pricing\PriceListItem;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Export\Delimited;
use Wonder\Plugin\Gestionale\Support\Numbers;
use Wonder\Plugin\Gestionale\Support\Stock\ProductNames;

/**
 * Le righe di un listino in un foglio: SKU, Prodotto, Quantità minima,
 * Prezzo. Lo stesso foglio scaricato si ricarica: il prodotto lo dice lo SKU,
 * la colonna «Prodotto» è solo per chi legge.
 *
 * `fromTable()` è pura e si prova senza database.
 */
final class PriceListTransfer
{
    public const HEADERS = ['SKU', 'Prodotto', 'Quantità minima', 'Prezzo'];

    /** Tetto di righe di un file: oltre, è un file sbagliato. */
    public const MAX_ROWS = 20000;

    /**
     * Le righe del listino da scaricare, per prodotto e scaglione.
     *
     * @return list<array{0: string, 1: string, 2: float, 3: float}>
     */
    public static function rows(int $priceListId): array
    {
        $found = PriceListItem::find(['price_list_id' => $priceListId]);
        $items = is_array($found) ? (array_key_exists('id', $found) ? [$found] : array_filter($found, 'is_array')) : [];
        $ids = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['product_id'], $items)));
        $products = [];

        if ($ids !== []) {
            $found = Product::find('`id` IN ('.implode(', ', $ids).')');
            $list = is_array($found) ? (array_key_exists('id', $found) ? [$found] : array_filter($found, 'is_array')) : [];

            foreach ($list as $product) {
                $products[(int) $product['id']] = $product;
            }
        }

        $names = ProductNames::models(array_values($products));
        $rows = [];

        foreach ($items as $item) {
            $product = $products[(int) $item['product_id']] ?? null;

            if ($product === null) {
                continue;
            }

            $rows[] = [
                html_entity_decode((string) ($product['sku'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                html_entity_decode(ProductNames::full($product, $names), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                (float) ($item['min_quantity'] ?? 1),
                (float) ($item['price'] ?? 0),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [strnatcasecmp($a[1], $b[1]), $a[2]] <=> [0, $b[2]]);

        return $rows;
    }

    /** @param list<array{0: string, 1: string, 2: float, 3: float}> $rows */
    public static function csv(array $rows): string
    {
        return Delimited::write(self::HEADERS, $rows, Delimited::CSV, true);
    }

    /** @param list<array{0: string, 1: string, 2: float, 3: float}> $rows */
    public static function xlsx(array $rows): string
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Listino');

        foreach (self::HEADERS as $index => $header) {
            $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($index + 1).'1', $header, DataType::TYPE_STRING);
        }

        $sheet->getStyle('1:1')->getFont()->setBold(true);
        $sheet->freezePane('A2');

        foreach ($rows as $line => $row) {
            $r = $line + 2;
            $sheet->setCellValueExplicit('A'.$r, $row[0], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('B'.$r, $row[1], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('C'.$r, $row[2], DataType::TYPE_NUMERIC);
            $sheet->setCellValueExplicit('D'.$r, $row[3], DataType::TYPE_NUMERIC);
            $sheet->getStyle('C'.$r)->getNumberFormat()->setFormatCode('#,##0.###');
            $sheet->getStyle('D'.$r)->getNumberFormat()->setFormatCode('#,##0.00');
        }

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        (new Xlsx($book))->save($handle);
        rewind($handle);
        $file = (string) stream_get_contents($handle);
        fclose($handle);
        $book->disconnectWorksheets();

        return $file;
    }

    /**
     * Le celle di un file caricato (csv o xlsx), riga per riga.
     *
     * @return list<list<mixed>>
     */
    public static function readFile(string $path, string $originalName): array
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($extension, ['csv', 'xlsx'], true)) {
            throw UserError::refusal('price_list.import_format');
        }

        try {
            $reader = IOFactory::createReader($extension === 'csv' ? 'Csv' : 'Xlsx');
            $reader->setReadDataOnly(true);
            $book = $reader->load($path);
            $table = $book->getActiveSheet()->toArray(null, true, false, false);
            $book->disconnectWorksheets();
        } catch (Throwable) {
            throw UserError::refusal('price_list.import_unreadable');
        }

        return array_values(array_map(static fn ($row): array => array_values((array) $row), $table));
    }

    /**
     * Le righe del listino lette da un foglio. La prima riga sono le
     * intestazioni; le colonne si trovano per nome, altrimenti valgono le
     * posizioni del file scaricato. Le righe vuote si saltano; ogni altro
     * errore ferma tutto, con il numero di riga del foglio.
     *
     * @param list<list<mixed>> $table
     * @param array<string, int> $skuIds lo SKU (in minuscolo) e il suo prodotto
     * @return list<array{product_id: int, min_quantity: float, price: float}>
     */
    public static function fromTable(array $table, array $skuIds): array
    {
        if (count($table) > self::MAX_ROWS + 1) {
            throw UserError::refusal('price_list.import_too_many', ['max' => self::MAX_ROWS]);
        }

        $columns = self::columns((array) ($table[0] ?? []));
        $rows = [];
        $seen = [];

        foreach (array_slice($table, 1) as $index => $cells) {
            $line = $index + 2;
            $sku = trim((string) ($cells[$columns['sku']] ?? ''));
            $minCell = trim((string) ($cells[$columns['min_quantity']] ?? ''));
            $priceCell = trim((string) ($cells[$columns['price']] ?? ''));

            if ($sku === '' && $minCell === '' && $priceCell === '') {
                continue;
            }

            if ($sku === '') {
                throw UserError::refusal('price_list.import_no_sku', ['row' => $line]);
            }

            $productId = $skuIds[mb_strtolower($sku)] ?? 0;

            if ($productId <= 0) {
                throw UserError::refusal('price_list.import_unknown_sku', ['row' => $line, 'sku' => $sku]);
            }

            $min = $minCell === '' ? '1' : Numbers::fromForm($minCell);
            $price = Numbers::fromForm($priceCell);

            if ($min === null || (float) $min <= 0 || $price === null || (float) $price < 0) {
                throw UserError::refusal('price_list.import_invalid_number', ['row' => $line]);
            }

            $key = $productId.'|'.round((float) $min, 3);

            if (isset($seen[$key])) {
                throw UserError::refusal('price_list.import_duplicate', ['row' => $line, 'sku' => $sku]);
            }

            $seen[$key] = true;
            $rows[] = ['product_id' => $productId, 'min_quantity' => round((float) $min, 3), 'price' => round((float) $price, 2)];
        }

        if ($rows === []) {
            throw UserError::refusal('price_list.import_empty');
        }

        return $rows;
    }

    /**
     * Sostituisce tutte le righe del listino con quelle lette dal file.
     *
     * @param list<array{product_id: int, min_quantity: float, price: float}> $rows
     */
    public static function replace(int $priceListId, array $rows): void
    {
        PriceListItem::query()->Delete(PriceListItem::$table, 'WHERE `price_list_id` = '.$priceListId);

        foreach ($rows as $position => $row) {
            PriceListItem::create($row + ['price_list_id' => $priceListId, 'position' => $position + 1]);
        }
    }

    /**
     * Gli SKU del catalogo, in minuscolo, con il loro prodotto.
     *
     * @return array<string, int>
     */
    public static function skuIds(): array
    {
        $found = Product::find([]);
        $list = is_array($found) ? (array_key_exists('id', $found) ? [$found] : array_filter($found, 'is_array')) : [];
        $ids = [];

        foreach ($list as $product) {
            $sku = mb_strtolower(trim(html_entity_decode((string) ($product['sku'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

            if ($sku !== '' && !isset($ids[$sku])) {
                $ids[$sku] = (int) $product['id'];
            }
        }

        return $ids;
    }

    /**
     * @param list<mixed> $header
     * @return array{sku: int, min_quantity: int, price: int}
     */
    private static function columns(array $header): array
    {
        $columns = ['sku' => 0, 'min_quantity' => 2, 'price' => 3];
        $names = [
            'sku' => ['sku', 'codice'],
            'min_quantity' => ['quantità minima', 'quantita minima', 'quantità', 'quantita', 'min_quantity'],
            'price' => ['prezzo', 'price'],
        ];

        foreach ($header as $index => $cell) {
            $text = mb_strtolower(trim((string) $cell));

            foreach ($names as $key => $aliases) {
                if (in_array($text, $aliases, true)) {
                    $columns[$key] = (int) $index;
                }
            }
        }

        return $columns;
    }
}
