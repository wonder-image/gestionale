<?php

namespace Wonder\Plugin\Gestionale\Backend\Widgets;

use Throwable;
use Wonder\Backend\Contracts\HomeWidget;
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
        return ['admin', 'administrator'];
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

        $giorni = ShipmentAlerts::STALE_DAYS;
        $quante = $count === 1 ? '1 spedizione' : $count.' spedizioni';
        $url = htmlspecialchars(ShipmentResource::filteredUrl('non_consegnate'), ENT_QUOTES, 'UTF-8');

        return <<<HTML
<wi-card class="col-12">
    <h5 class="mb-1 d-flex justify-content-between align-items-center gap-3">
        <span><i class="bi bi-hourglass-split"></i> Spedizioni in ritardo</span>
        <a class="btn btn-sm btn-secondary" href="{$url}">Apri le spedizioni</a>
    </h5>
    <p class="small mb-0">{$quante} partite da più di {$giorni} giorni e non ancora consegnate.</p>
</wi-card>
HTML;
    }
}
