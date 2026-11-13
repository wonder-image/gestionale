<?php

namespace Wonder\Plugin\Gestionale\Backend\Widgets;

use Throwable;
use Wonder\App\Permission\Access;
use Wonder\Backend\Contracts\HomeWidget;
use Wonder\Elements\Components\StatusCard;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderResource;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Orders\OrderQueues;
use Wonder\Plugin\Gestionale\Support\SalesAgents\SalesAgents;

/**
 * «Ordini»: quanti ne sono arrivati questo mese e le tre code su cui c'è da
 * lavorare (in attesa, da evadere, da pagare). Ogni card apre l'elenco con
 * il filtro che dà lo stesso numero.
 */
final class OrdersWidget implements HomeWidget
{
    public function title(): string
    {
        return 'Ordini';
    }

    public function authorities(): array
    {
        // I totali sono di tutti: il commerciale ha il suo riquadro.
        return SalesAgents::withoutAgents(Access::viewers(Gestionale::area('vendite')));
    }

    public function order(): int
    {
        return 15;
    }

    public function render(): string
    {
        if (!Gestionale::feature('orders')) {
            return '';
        }

        try {
            return self::markup(OrderQueues::counts(), self::urls());
        } catch (Throwable $error) {
            // La home si apre lo stesso: il guasto va nel log, non in pagina.
            Errors::internal($error, 'widget.orders');

            return '';
        }
    }

    /** @return array{mese: string, in_attesa: string, da_evadere: string, da_pagare: string} */
    public static function urls(): array
    {
        $urls = ['mese' => OrderResource::filteredUrl(['periodo' => 'questo-mese'])];

        foreach (array_keys(OrderQueues::LABELS) as $key) {
            $urls[$key] = OrderResource::filteredUrl(['situazione' => $key]);
        }

        return $urls;
    }

    /**
     * @param array{in_attesa: int, da_evadere: int, da_pagare: int, mese: int, oggi: int} $counts
     * @param array<string, string> $urls
     */
    public static function markup(array $counts, array $urls): string
    {
        $counts = OrderQueues::normalize($counts);

        $cards = [
            StatusCard::make('Ordini del mese', (string) $counts['mese'])
                ->icon('bi-bag')
                ->variant('primary')
                ->detail($counts['oggi'] === 0 ? 'Nessuno oggi.' : ($counts['oggi'] === 1 ? '1 arrivato oggi.' : $counts['oggi'].' arrivati oggi.'))
                ->href($urls['mese'] ?? ''),
            self::queue('in_attesa', $counts['in_attesa'], 'bi-hourglass-split', 'Da confermare.', $urls),
            self::queue('da_evadere', $counts['da_evadere'], 'bi-box-seam', 'Confermati, merce non ancora partita.', $urls),
            self::queue('da_pagare', $counts['da_pagare'], 'bi-credit-card', 'Pagamento non ancora saldato.', $urls),
        ];

        $columns = '';

        foreach ($cards as $card) {
            $columns .= WidgetCard::column($card, 'col-12 col-md-6 col-xl-3');
        }

        return '<div class="col-12"><div class="row g-3">'.$columns.'</div></div>';
    }

    /** @param array<string, string> $urls */
    private static function queue(string $key, int $count, string $icon, string $detail, array $urls): StatusCard
    {
        return StatusCard::make(OrderQueues::LABELS[$key], $count === 1 ? '1 ordine' : $count.' ordini')
            ->icon($icon)
            ->variant($count > 0 ? 'warning' : 'success')
            ->detail($detail)
            ->href($urls[$key] ?? '');
    }
}
