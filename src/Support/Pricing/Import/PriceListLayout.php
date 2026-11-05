<?php

namespace Wonder\Plugin\Gestionale\Support\Pricing\Import;

use RuntimeException;
use Throwable;
use Wonder\App\Import\Change;
use Wonder\App\Import\Column;
use Wonder\App\Import\Context;
use Wonder\App\Import\Layout;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Pricing\PriceList;
use Wonder\Plugin\Gestionale\Models\Pricing\PriceListItem;
use Wonder\Plugin\Gestionale\Resources\Pricing\PriceListResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Import\CatalogCells;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Pricing\PriceListTransfer;

/**
 * Prezzi di listino in blocco (#162): una riga è un prezzo di un prodotto in
 * un listino, per quantità minima. Più listini nello stesso file.
 *
 * Una riga che c'è (listino, prodotto, quantità minima) cambia prezzo; una
 * nuova si aggiunge. Non si cancella niente: per togliere righe c'è il file
 * del singolo listino, che le sostituisce tutte.
 */
final class PriceListLayout extends Layout
{
    public const KEY = 'price_lists';

    // Le frasi del tracciato stanno in gestionale.errors.import.
    private const PREFIX = 'import.';

    public static function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Prezzi di listino';
    }

    public function permits(object $user): bool
    {
        return Gestionale::feature('customer_price_lists')
            && PriceListResource::allows((array) ($user->authority ?? []), 'update');
    }

    public function backUrl(): ?string
    {
        try {
            return PriceListResource::url();
        } catch (Throwable) {
            return null;
        }
    }

    public function columns(): array
    {
        return [
            Column::make('price_list', 'Listino', ['Nome listino', 'ID listino'])->required()->field('Nome o ID del listino'),
            Column::make('sku', 'SKU', ['Codice'])->required()->field('Codice della versione: dice quale prodotto'),
            Column::make('product', 'Prodotto')->field('Non si legge: è il nome del prodotto'),
            Column::make('min_quantity', 'Quantità minima', ['Quantità', 'Da quantità'])->field('Da quanti pezzi vale il prezzo (vuota = 1)'),
            Column::make('price', 'Prezzo', ['Prezzo di listino'])->required()->field('Prezzo del listino, IVA inclusa o esclusa come dice il listino'),
        ];
    }

    public function sampleRows(): array
    {
        return [
            ['price_list' => 'Rivenditori', 'sku' => 'ESEMPIO-001', 'product' => 'Tazza in ceramica', 'min_quantity' => '1', 'price' => '9,50'],
            ['price_list' => 'Rivenditori', 'sku' => 'ESEMPIO-001', 'product' => 'Tazza in ceramica', 'min_quantity' => '12', 'price' => '8,90'],
            ['price_list' => 'eBay', 'sku' => 'ESEMPIO-001', 'product' => 'Tazza in ceramica', 'min_quantity' => '1', 'price' => '13,90'],
        ];
    }

    public function validate(array $rows, Context $context): array
    {
        $lists = $this->lists($context);
        $skus = $context->remember('price_lists.skus', static fn (): array => PriceListTransfer::skuIds());
        $items = $this->items($context);
        $seen = [];
        $results = [];

        foreach ($rows as $row) {
            $data = $row['data'];
            $errors = [];
            $listCell = trim((string) ($data['price_list'] ?? ''));
            $sku = trim((string) ($data['sku'] ?? ''));
            $listId = $listCell === '' ? 0 : $this->listId($lists, $listCell);
            $productId = $sku === '' ? 0 : ($skus[mb_strtolower($sku)] ?? 0);

            if ($listCell === '') {
                $errors[] = self::text('list_missing');
            } elseif ($listId === 0) {
                $errors[] = self::text('list_unknown', ['list' => $listCell]);
            } elseif ($listId < 0) {
                $errors[] = self::text('list_ambiguous', ['list' => $listCell]);
            }

            if ($sku === '') {
                $errors[] = self::text('sku_missing');
            } elseif ($productId === 0) {
                $errors[] = self::text('sku_unknown', ['sku' => $sku]);
            }

            $minCell = trim((string) ($data['min_quantity'] ?? ''));
            $min = $minCell === '' ? 1.0 : CatalogCells::number($minCell);
            $price = CatalogCells::number(trim((string) ($data['price'] ?? '')));

            if ($min === null || $min <= 0) {
                $errors[] = self::text('min_quantity');
            }

            if ($price === null || $price < 0) {
                $errors[] = self::text('list_price');
            }

            $current = null;

            if ($errors === []) {
                $min = round($min, 3);
                $price = round($price, 2);
                $key = $listId.'|'.$productId.'|'.$min;

                if (isset($seen[$key])) {
                    $errors[] = self::text('list_row_duplicate', ['line' => $seen[$key]]);
                }

                $seen[$key] = $row['line'];
                $current = $items[$key] ?? null;
            }

            $results[] = [
                'action' => $current === null ? 'create' : (abs((float) $current['price'] - (float) $price) < 0.005 ? 'unchanged' : 'update'),
                'errors' => $errors,
                'warnings' => [],
                'data' => $data + [
                    '_price_list_id' => (string) max(0, $listId),
                    '_product_id' => (string) $productId,
                    '_min_quantity' => (string) $min,
                    '_price' => (string) $price,
                    '_item_id' => $current !== null ? (string) $current['id'] : '',
                ],
            ];
        }

        return $results;
    }

    public function apply(array $data, Context $context): array
    {
        $price = round((float) ($data['_price'] ?? 0), 2);
        $itemId = (int) ($data['_item_id'] ?? 0);

        if ($itemId > 0) {
            $item = PriceListItem::findById($itemId);

            if (!is_array($item) || !isset($item['id'])) {
                throw UserError::make('import.list_row_missing');
            }

            if (abs((float) $item['price'] - $price) < 0.005) {
                return ['action' => 'unchanged', 'changes' => []];
            }

            PriceListItem::update(['price' => $price], $itemId);

            return ['action' => 'update', 'changes' => [new Change('item', $itemId, 'updated', ['price' => (string) $item['price']])]];
        }

        $listId = (int) ($data['_price_list_id'] ?? 0);
        $created = PriceListItem::create([
            'price_list_id' => $listId,
            'product_id' => (int) ($data['_product_id'] ?? 0),
            'min_quantity' => (float) ($data['_min_quantity'] ?? 1),
            'price' => $price,
            'position' => $this->nextPosition($listId),
        ]);
        $id = (int) ($created->insert_id ?? 0);

        if (empty($created->success) || $id <= 0) {
            throw UserError::make('import.save_failed');
        }

        return ['action' => 'create', 'changes' => [new Change('item', $id, 'created')]];
    }

    public function undo(Change $change, Context $context): void
    {
        if ($change->target !== 'item') {
            return;
        }

        if ($change->action === 'created') {
            // Le righe di listino non hanno «deleted»: si tolgono davvero.
            PriceListItem::query()->Delete(PriceListItem::$table, 'WHERE `id` = '.(int) $change->recordId);
        } elseif ($change->action === 'updated' && $change->oldValues !== []) {
            PriceListItem::update($change->oldValues, $change->recordId);
        }
    }

    public function message(Throwable $exception): ?string
    {
        return $exception instanceof RuntimeException ? $exception->getMessage() : null;
    }

    /**
     * I listini per nome (in minuscolo) e per ID; -1 se il nome è di più listini.
     *
     * @return array{names: array<string, int>, ids: array<int, true>}
     */
    private function lists(Context $context): array
    {
        return $context->remember('price_lists.lists', static function (): array {
            $found = PriceList::find([]);
            $list = is_array($found) ? (array_key_exists('id', $found) ? [$found] : array_filter($found, 'is_array')) : [];
            $names = [];
            $ids = [];

            foreach ($list as $row) {
                $id = (int) $row['id'];
                $name = mb_strtolower(trim(html_entity_decode((string) ($row['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                $ids[$id] = true;

                if ($name !== '') {
                    $names[$name] = isset($names[$name]) ? -1 : $id;
                }
            }

            return ['names' => $names, 'ids' => $ids];
        });
    }

    /** @param array{names: array<string, int>, ids: array<int, true>} $lists */
    private function listId(array $lists, string $cell): int
    {
        $byName = $lists['names'][mb_strtolower($cell)] ?? 0;

        if ($byName !== 0) {
            return $byName;
        }

        return ctype_digit($cell) && isset($lists['ids'][(int) $cell]) ? (int) $cell : 0;
    }

    /** @return array<string, array<string, mixed>> per «listino|prodotto|quantità» */
    private function items(Context $context): array
    {
        return $context->remember('price_lists.items', static function (): array {
            $found = PriceListItem::find([]);
            $list = is_array($found) ? (array_key_exists('id', $found) ? [$found] : array_filter($found, 'is_array')) : [];
            $items = [];

            foreach ($list as $row) {
                $items[(int) $row['price_list_id'].'|'.(int) $row['product_id'].'|'.round((float) $row['min_quantity'], 3)] = $row;
            }

            return $items;
        });
    }

    private function nextPosition(int $listId): int
    {
        $found = PriceListItem::find(['price_list_id' => $listId]);
        $list = is_array($found) ? (array_key_exists('id', $found) ? [$found] : array_filter($found, 'is_array')) : [];

        return array_reduce($list, static fn (int $max, array $row): int => max($max, (int) ($row['position'] ?? 0)), 0) + 1;
    }

    /** @param array<string, string|int> $replacements */
    private static function text(string $key, array $replacements = []): string
    {
        return UserError::make(self::PREFIX.$key, $replacements)->getMessage();
    }
}
