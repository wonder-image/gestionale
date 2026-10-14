<?php

namespace Wonder\Plugin\Gestionale\Resources\Contacts;

use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\Elements\Components\Accordion;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\RichText;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductSupplier;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocument;
use Wonder\Plugin\Gestionale\Resources\Stock\StockDocumentResource;
use Wonder\Plugin\Gestionale\Support\Contacts\CustomerSheet;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;
use Wonder\Plugin\Gestionale\Support\Stock\StockValue;

/**
 * "Fornitori": lo stesso elenco visto dall'altra parte.
 *
 * Cambia il titolo, l'indirizzo, il filtro e la funzionalità che lo governa.
 * Chi è cliente e fornitore compare in tutti e due gli elenchi e si modifica
 * una volta sola. La scheda in lettura è come quella del cliente, con quello
 * che conta per un fornitore: i suoi dati, i carichi e gli articoli che vende.
 */
final class SupplierResource extends CustomerResource
{
    public static string $feature = 'purchasing';

    /** I carichi più recenti mostrati nella scheda. */
    public const RECENT_RECEIPTS = 20;

    public static function hasSheet(): bool
    {
        return true;
    }

    protected static function sheetView(): string
    {
        return 'pages/supplier-show.php';
    }

    public static function roleColumn(): string
    {
        return 'is_supplier';
    }

    public static function path(): string
    {
        return 'app/gestionale/fornitori';
    }

    public static function icon(): string
    {
        return 'bi-truck';
    }

    public static function titleLabel(): string
    {
        return 'Fornitori';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'fornitore',
            'plural_label' => 'fornitori',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'i',
            'this' => 'questo',
        ];
    }

    public static function navigationSchema(): NavigationSchema
    {
        return parent::navigationSchema()->order(20);
    }

    /** La scheda del fornitore: i suoi dati e la fatturazione, poi carichi e articoli forniti. */
    public static function showLayoutSchema(array $contact): Container
    {
        $id = (int) ($contact['id'] ?? 0);
        $accordion = static fn (string $titolo, string $html, bool $aperto = false): Accordion => $aperto
            ? Accordion::make($titolo)->expanded()->components([RichText::make($html)->tag('div')->columnSpan(12)])->columnSpan(12)
            : Accordion::make($titolo)->components([RichText::make($html)->tag('div')->columnSpan(12)])->columnSpan(12);

        $sinistra = [
            (new Card)->components([
                SectionTitle::make('Tutti i suoi dati')->columnSpan(12),
                ...CustomerSheet::data($contact, static::authMethod(static::withAccount($contact))),
            ])->columns(12)->columnSpan(12),
            $accordion('Carichi', static::receiptsTable(static::receipts($id)), true),
            $accordion('Articoli forniti', static::productsTable(static::supplied($id))),
        ];

        $destra = [
            (new Card)->components([
                SectionTitle::make('Dati di fatturazione')->columnSpan(12),
                ...CustomerSheet::billing($contact),
            ])->columns(12)->columnSpan(12),
        ];

        return (new Container)->components([
            (new Container)->columnSpan(['default' => 12, 'lg' => 8])->columns(12)->components($sinistra),
            (new Container)->columnSpan(['default' => 12, 'lg' => 4])->columns(12)->components($destra),
        ])->columns(12);
    }

    /**
     * I carichi del fornitore, dal più recente (gli ultimi `RECENT_RECEIPTS`).
     *
     * @return list<array<string, mixed>>
     */
    public static function receipts(int $supplierId): array
    {
        if ($supplierId <= 0) {
            return [];
        }

        $documenti = static::rowsOf(StockDocument::class, ['type' => 'receipt', 'supplier_id' => $supplierId, 'deleted' => 'false'], 'id', 'DESC');

        return array_slice($documenti, 0, self::RECENT_RECEIPTS);
    }

    /** @param list<array<string, mixed>> $receipts */
    public static function receiptsTable(array $receipts): string
    {
        if ($receipts === []) {
            return '<p class="text-muted mb-0">Nessun carico da questo fornitore.</p>';
        }

        $stati = ['draft' => ['Bozza', 'secondary'], 'completed' => ['Confermato', 'success'], 'cancelled' => ['Annullato', 'danger']];
        $body = '';

        foreach ($receipts as $doc) {
            $numero = trim((string) ($doc['number'] ?? '')) ?: '—';
            [$parola, $colore] = $stati[(string) ($doc['status'] ?? '')] ?? [(string) ($doc['status'] ?? ''), 'light'];
            $body .= '<tr><td class="fw-semibold"><a href="'.OrderSheet::esc(StockDocumentResource::pageUrl($doc)).'">'.OrderSheet::esc($numero).'</a></td>'
                .'<td>'.OrderSheet::esc(substr(OrderSheet::date((string) ($doc['date'] ?? '')), 0, 10)).'</td>'
                .'<td>'.OrderSheet::esc(trim((string) ($doc['supplier_document_number'] ?? '')) ?: '—').'</td>'
                .'<td><span class="badge text-bg-'.$colore.'">'.OrderSheet::esc($parola).'</span></td></tr>';
        }

        return '<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr>'
            .'<th>Numero</th><th>Data</th><th>Documento del fornitore</th><th>Stato</th>'
            .'</tr></thead><tbody>'.$body.'</tbody></table></div>';
    }

    /**
     * Gli articoli che il fornitore vende, con il suo codice e il costo.
     *
     * @return list<array{name: string, sku: string, supplier_sku: string, cost: ?float}>
     */
    public static function supplied(int $supplierId): array
    {
        if ($supplierId <= 0) {
            return [];
        }

        $out = [];

        foreach (static::rowsOf(ProductSupplier::class, ['supplier_id' => $supplierId]) as $link) {
            $product = Product::findById((int) $link['product_id']);

            if (!is_array($product) || (string) ($product['deleted'] ?? 'false') !== 'false') {
                continue;
            }

            $model = ProductModel::findById((int) ($product['product_model_id'] ?? 0));
            $article = is_array($model) ? trim((string) ($model['name'] ?? '')) : '';
            $option = trim((string) ($product['name'] ?? ''));

            $out[] = [
                'name' => match (true) {
                    $article === '' => $option !== '' ? $option : (string) ($product['sku'] ?? ''),
                    $option === '' || $option === $article => $article,
                    default => $article.' — '.$option,
                },
                'sku' => (string) ($product['sku'] ?? ''),
                'supplier_sku' => (string) ($link['supplier_sku'] ?? ''),
                'cost' => ($link['cost'] ?? null) === null || $link['cost'] === '' ? null : (float) $link['cost'],
            ];
        }

        usort($out, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $out;
    }

    /** @param list<array{name: string, sku: string, supplier_sku: string, cost: ?float}> $products */
    public static function productsTable(array $products): string
    {
        if ($products === []) {
            return '<p class="text-muted mb-0">Nessun articolo collegato a questo fornitore.</p>';
        }

        $body = '';

        foreach ($products as $row) {
            $body .= '<tr><td>'.OrderSheet::esc($row['name']).'</td>'
                .'<td>'.OrderSheet::esc($row['sku'] ?: '—').'</td>'
                .'<td>'.OrderSheet::esc($row['supplier_sku'] ?: '—').'</td>'
                .'<td class="text-end">'.($row['cost'] === null ? '—' : OrderSheet::esc(StockValue::money($row['cost']))).'</td></tr>';
        }

        return '<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr>'
            .'<th>Articolo</th><th>SKU</th><th>Codice del fornitore</th><th class="text-end">Costo</th>'
            .'</tr></thead><tbody>'.$body.'</tbody></table></div>';
    }
}
