<?php

namespace Wonder\Plugin\Gestionale\Backend\Widgets;

use Throwable;
use Wonder\Backend\Contracts\HomeWidget;
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
        return ['admin', 'administrator'];
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

        $quante = $count === 1 ? '1 spedizione' : $count.' spedizioni';
        $url = htmlspecialchars(ShipmentResource::filteredUrl('da_controllare'), ENT_QUOTES, 'UTF-8');

        return <<<HTML
<wi-card class="col-12">
    <h5 class="mb-1 d-flex justify-content-between align-items-center gap-3">
        <span><i class="bi bi-exclamation-triangle"></i> Spedizioni da controllare</span>
        <a class="btn btn-sm btn-secondary" href="{$url}">Apri le spedizioni</a>
    </h5>
    <p class="small mb-0">{$quante} con una consegna fallita o un problema da risolvere.</p>
</wi-card>
HTML;
    }
}
