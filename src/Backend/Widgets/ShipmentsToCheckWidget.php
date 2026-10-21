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
 * «Spedizioni da controllare»: le consegne andate male o con un problema.
 *
 * Tace se non c'è niente da vedere e con le spedizioni spente: un riquadro
 * che dice «zero» tutti i giorni si smette di leggere. Il numero è lo stesso
 * dell'elenco che si apre dal pulsante.
 */
final class ShipmentsToCheckWidget implements HomeWidget
{
    public function title(): string
    {
        return 'Spedizioni da controllare';
    }

    public function authorities(): array
    {
        return Access::viewers(Gestionale::area('spedizioni'));
    }

    public function order(): int
    {
        return 26;
    }

    public function render(): string
    {
        if (!Gestionale::feature('shipping')) {
            return '';
        }

        try {
            return self::markup(ShipmentAlerts::toCheck());
        } catch (Throwable $error) {
            // La home si apre lo stesso: il guasto va nel log, non in pagina.
            Errors::internal($error, 'widget.shipments_to_check');

            return '';
        }
    }

    public static function markup(int $count): string
    {
        if ($count < 1) {
            return '';
        }

        $card = StatusCard::make('Spedizioni da controllare', $count === 1 ? '1 spedizione' : $count.' spedizioni')
            ->icon('bi-exclamation-triangle')
            ->variant('warning')
            ->detail('Con una consegna fallita o un problema da risolvere.')
            ->href(ShipmentResource::filteredUrl('da_controllare'));

        return WidgetCard::column($card, 'col-12 col-md-6');
    }
}
