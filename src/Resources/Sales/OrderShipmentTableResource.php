<?php

namespace Wonder\Plugin\Gestionale\Resources\Sales;

use Throwable;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Shipping\Shipment;
use Wonder\Plugin\Gestionale\Models\Shipping\ShipmentItem;
use Wonder\Plugin\Gestionale\Resources\Shipping\ShipmentResource;
use Wonder\Plugin\Gestionale\Resources\Stock\StockAdjustmentResource;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;
use Wonder\Plugin\Gestionale\Support\Orders\StatusLabels;

/** Le spedizioni e il ritiro dell'ordine: la tabella c'è solo con la funzionalità «shipping». */
final class OrderShipmentTableResource extends OrderSectionResource
{
    public static string $model = Shipment::class;
    public static string $feature = 'shipping';

    public static function path(): string
    {
        return 'app/gestionale/ordine-spedizioni';
    }

    public static function icon(): string
    {
        return 'bi-box-seam';
    }

    public static function titleLabel(): string
    {
        return 'Spedizioni dell\'ordine';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'spedizione',
            'plural_label' => 'spedizioni',
            'last' => 'ultime',
            'all' => 'tutte',
            'article' => 'le',
            'this' => 'questa',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'code' => 'Codice',
            'status' => 'Stato',
            'lines' => 'Contenuto',
            'carrier_id' => 'Corriere',
            'tracking_number' => 'Tracking',
            'actions' => 'Azioni',
        ];
    }

    public static function tableSchema(): array
    {
        $back = static fn (): string => StockAdjustmentResource::backUrlFrom($_GET['torna'] ?? '');

        return [
            TableColumn::key('code')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => '<a href="'
                    .static::escape(ShipmentResource::detailUrl((int) ($row['id'] ?? 0), $back() ?: null)).'">'
                    .static::escape((string) ($row['code'] ?? '')).'</a>'),
            TableColumn::key('status')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => StatusLabels::badge('shipment', (string) ($row['status'] ?? ''))
                    .((string) ($row['type'] ?? '') === 'pickup' ? ' <span class="small text-muted">ritiro</span>' : '')),
            TableColumn::key('lines')
                ->text()
                ->formatter(static fn (array $row): string => static::linesOf((int) ($row['id'] ?? 0))),
            TableColumn::key('carrier_id')
                ->text()
                ->hiddenDevice('mobile')
                ->formatter(static fn (array $row): string => static::escape(ShipmentResource::carrierName((int) ($row['carrier_id'] ?? 0)))),
            TableColumn::key('tracking_number')
                ->text()
                ->hiddenDevice('mobile')
                ->formatter(static fn (array $row): string => ShipmentResource::trackingHtml($row)),
            TableColumn::key('actions')
                ->text()
                ->formatter(static fn (array $row): string => ShipmentResource::actionsHtml($row, $back())),
        ];
    }

    /** «Crema × 2, Sapone × 1»: cosa c'è in quella spedizione. */
    private static function linesOf(int $shipmentId): string
    {
        if ($shipmentId <= 0) {
            return '';
        }

        $testi = [];

        try {
            foreach (static::rowsOf(ShipmentItem::class, ['shipment_id' => $shipmentId], 'id') as $item) {
                $riga = OrderItem::findById((int) ($item['order_item_id'] ?? 0));
                $nome = is_array($riga) ? (string) ($riga['name'] ?? '') : '';
                $testi[] = static::escapeStored($nome).' × '.static::escape(OrderSheet::number((float) ($item['quantity'] ?? 0)));
            }
        } catch (Throwable) {
            return '';
        }

        return implode(', ', $testi);
    }

    protected static function emptyText(): string
    {
        return 'Nessuna spedizione su questo ordine.';
    }

    /** Dalla più vecchia alla più nuova: l'ordine in cui sono state create. */
    protected static function sortColumn(): string
    {
        return 'id';
    }
}
