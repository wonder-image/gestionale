<?php

namespace Wonder\Plugin\Gestionale\Resources\Sales;

use Throwable;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Sales\SalesReturn;
use Wonder\Plugin\Gestionale\Models\Sales\SalesReturnItem;
use Wonder\Plugin\Gestionale\Resources\Stock\StockAdjustmentResource;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;

/** I resi dell'ordine: la tabella c'è solo con la funzionalità «returns». */
final class OrderReturnTableResource extends OrderSectionResource
{
    public static string $model = SalesReturn::class;
    public static string $feature = 'returns';

    public static function path(): string
    {
        return 'app/gestionale/ordine-resi';
    }

    public static function icon(): string
    {
        return 'bi-arrow-return-left';
    }

    public static function titleLabel(): string
    {
        return 'Resi dell\'ordine';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'reso',
            'plural_label' => 'resi',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'i',
            'this' => 'questo',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'number' => 'Numero',
            'status' => 'Stato',
            'requested_at' => 'Data',
            'lines' => 'Righe',
            'actions' => 'Azioni',
        ];
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('number')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape((string) (($row['number'] ?? '') !== '' ? $row['number'] : ($row['code'] ?? '')))),
            TableColumn::key('status')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(OrderSheet::returnStatus((string) ($row['status'] ?? '')))),
            TableColumn::key('requested_at')
                ->text()
                ->formatter(static fn (array $row): string => static::escape(OrderSheet::date(
                    (string) (($row['requested_at'] ?? '') !== '' ? $row['requested_at'] : ($row['creation'] ?? ''))
                ))),
            TableColumn::key('lines')
                ->text()
                ->formatter(static fn (array $row): string => static::linesOf((int) ($row['id'] ?? 0))),
            TableColumn::key('actions')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::actionsOf($row)),
        ];
    }

    /** «Crema × 2, Sapone × 1»: cosa è tornato in quel reso. */
    private static function linesOf(int $returnId): string
    {
        if ($returnId <= 0) {
            return '';
        }

        try {
            $items = SalesReturnItem::find(['sales_return_id' => $returnId, 'deleted' => 'false']);
            $items = !is_array($items) || $items === [] ? [] : (isset($items['id']) ? [$items] : array_values($items));
        } catch (Throwable) {
            return '';
        }

        $testi = [];

        foreach ($items as $item) {
            $riga = OrderItem::findById((int) ($item['order_item_id'] ?? 0));
            $nome = is_array($riga) ? (string) ($riga['name'] ?? '') : '';
            $pezzi = rtrim(rtrim(number_format((float) ($item['quantity'] ?? 0), 3, ',', ''), '0'), ',');
            $testi[] = static::escape($nome.' × '.$pezzi);
        }

        return implode(', ', $testi);
    }

    /**
     * «Chiudi» e «Annulla» di un reso ricevuto: due piccoli moduli che postano
     * a «Registra reso», che è l'unico a parlare con `Returns`. Per un reso già
     * chiuso o annullato non c'è niente da fare.
     *
     * @param array<string, mixed> $row
     */
    private static function actionsOf(array $row): string
    {
        if ((string) ($row['status'] ?? '') !== 'received') {
            return '';
        }

        $orderId = (int) ($row['order_id'] ?? 0);
        $torna = StockAdjustmentResource::backUrlFrom($_GET['torna'] ?? '');
        $back = OrderResource::detailUrl($orderId, $torna !== '' ? $torna : null);
        $form = static fn (string $action, string $label, string $class): string => '<form class="d-inline" method="post" action="'
            .static::escape(OrderReturnResource::submitUrl()).'">'
            .'<input type="hidden" name="action" value="'.$action.'">'
            .'<input type="hidden" name="return_id" value="'.(int) ($row['id'] ?? 0).'">'
            .'<input type="hidden" name="order_id" value="'.$orderId.'">'
            .'<input type="hidden" name="back" value="'.static::escape($back).'">'
            .'<button type="submit" class="btn btn-sm '.$class.'">'.$label.'</button></form>';

        return $form('complete', 'Chiudi', 'btn-outline-success').' '.$form('cancel', 'Annulla', 'btn-outline-danger');
    }

    protected static function emptyText(): string
    {
        return 'Nessun reso su questo ordine.';
    }
}
