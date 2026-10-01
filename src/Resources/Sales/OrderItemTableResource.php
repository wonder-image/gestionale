<?php

namespace Wonder\Plugin\Gestionale\Resources\Sales;

use Wonder\App\ResourceSchema\TableColumn;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductPhotos;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;

/** Le righe dell'ordine, con la foto del prodotto. */
final class OrderItemTableResource extends OrderSectionResource
{
    public static string $model = OrderItem::class;
    public static string $orderColumn = 'position';

    public static function path(): string
    {
        return 'app/gestionale/ordine-righe';
    }

    public static function icon(): string
    {
        return 'bi-list-ul';
    }

    public static function titleLabel(): string
    {
        return 'Righe dell\'ordine';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'riga',
            'plural_label' => 'righe',
            'last' => 'ultime',
            'all' => 'tutte',
            'article' => 'le',
            'this' => 'questa',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'photo' => 'Foto',
            'name' => 'Articolo',
            'quantity' => 'Quantità',
            'unit_price' => 'Prezzo',
            'discount_value' => 'Sconto',
            'tax_rate' => 'IVA',
            'line_total' => 'Totale',
        ];
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('photo')
                ->image()
                ->size('little')
                ->formatter(static fn (array $row): string => static::photoOf($row)),
            TableColumn::key('name')
                ->text()
                ->formatter(static fn (array $row): string => static::nameCell($row)),
            TableColumn::key('quantity')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::onlyIfPriced($row, static::escape(OrderSheet::number($row['quantity'] ?? 0)))),
            TableColumn::key('unit_price')
                ->price()
                ->size('little'),
            TableColumn::key('discount_value')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::onlyIfPriced($row, static::escape(OrderSheet::discount($row)))),
            TableColumn::key('tax_rate')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::onlyIfPriced($row, static::escape(OrderSheet::number($row['tax_rate'] ?? 0)).'%')),
            TableColumn::key('line_total')
                ->price()
                ->size('little'),
        ];
    }

    /**
     * La foto che l'articolo aveva quando è stato ordinato; per le righe nate
     * prima che la foto si copiasse, quella di oggi.
     */
    public static function photoOf(array $row): string
    {
        if (($row['type'] ?? 'product') !== 'product') {
            return '';
        }

        $copia = trim((string) ($row['image'] ?? ''));

        return $copia !== '' ? $copia : ProductPhotos::forProduct((int) ($row['product_id'] ?? 0));
    }

    protected static function emptyText(): string
    {
        return 'Nessuna riga: l\'ordine non ha ancora articoli.';
    }

    /**
     * Il nome con lo SKU sotto; un tipo diverso dal prodotto porta la sua
     * etichetta, e una riga di sola nota si legge in corsivo.
     */
    private static function nameCell(array $row): string
    {
        $tipo = (string) ($row['type'] ?? 'product');
        $nome = static::escapeStored((string) ($row['name'] ?? ''));

        if ($tipo === 'text') {
            return '<span class="fst-italic">'.$nome.'</span>';
        }

        $sku = trim((string) ($row['sku'] ?? ''));
        $html = $nome;

        if ($tipo !== 'product') {
            $html .= ' <span class="badge text-bg-light">'.static::escape(OrderSheet::itemType($tipo)).'</span>';
        }

        if ($sku !== '') {
            $html .= '<div class="text-muted small">'.static::escapeStored($sku).'</div>';
        }

        return $html;
    }

    /** Una riga di sola nota non ha quantità, sconto né aliquota. */
    private static function onlyIfPriced(array $row, string $html): string
    {
        return (string) ($row['type'] ?? 'product') === 'text' ? '' : $html;
    }
}
