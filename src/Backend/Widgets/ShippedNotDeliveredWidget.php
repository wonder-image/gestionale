<?php

namespace Wonder\Plugin\Gestionale\Backend\Widgets;

use Throwable;
use Wonder\App\Permission\Access;
use Wonder\Backend\Contracts\HomeWidget;
use Wonder\Elements\Components\StatusCard;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Resources\Shipping\ShipmentResource;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Shipping\ShipmentAlerts;

/**
 * «Spedizioni in ritardo»: partite da più di una settimana e ancora non consegnate.
 *
 * Tace se non c'è niente da vedere e con le spedizioni spente: un riquadro
 * che dice «zero» tutti i giorni si smette di leggere. Il numero è lo stesso
 * dell'elenco che si apre dal pulsante.
 */
final class ShippedNotDeliveredWidget implements HomeWidget
{
    public function title(): string
    {
        return 'Spedizioni in ritardo';
    }

    public function authorities(): array
    {
        return Access::viewers(Gestionale::area('spedizioni'));
    }

    public function order(): int
    {
        return 27;
    }

    public function render(): string
    {
        if (!Gestionale::feature('shipping')) {
            return '';
        }

        try {
            return self::markup(ShipmentAlerts::notDelivered());
        } catch (Throwable $error) {
            // La home si apre lo stesso: il guasto va nel log, non in pagina.
            Errors::internal($error, 'widget.shipments_late');

            return '';
        }
    }

    public static function markup(int $count): string
    {
        if ($count < 1) {
            return '';
        }

        $card = StatusCard::make('Spedizioni in ritardo', $count === 1 ? '1 spedizione' : $count.' spedizioni')
            ->icon('bi-hourglass-split')
            ->variant('warning')
            ->detail('Partite da più di '.ShipmentAlerts::STALE_DAYS.' giorni e non ancora consegnate.')
            ->href(ShipmentResource::filteredUrl('non_consegnate'));

        return WidgetCard::column($card, 'col-12 col-md-6');
    }
}
