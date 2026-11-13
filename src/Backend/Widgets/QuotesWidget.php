<?php

namespace Wonder\Plugin\Gestionale\Backend\Widgets;

use Throwable;
use Wonder\App\Permission\Access;
use Wonder\Backend\Contracts\HomeWidget;
use Wonder\Elements\Components\StatusCard;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Resources\Sales\QuoteResource;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Sales\QuoteCounts;
use Wonder\Plugin\Gestionale\Support\SalesAgents\SalesAgents;

/**
 * «Preventivi»: le bozze da inviare e gli inviati che aspettano il cliente.
 * Solo con la funzionalità *Preventivi*, che chiede la vendita in ufficio.
 * Ogni card apre l'elenco con il filtro «Stato» che dà lo stesso numero.
 */
final class QuotesWidget implements HomeWidget
{
    public function title(): string
    {
        return 'Preventivi';
    }

    public function authorities(): array
    {
        // I totali sono di tutti: il commerciale ha il suo riquadro.
        return SalesAgents::withoutAgents(Access::viewers(Gestionale::area('vendite')));
    }

    public function order(): int
    {
        return 19;
    }

    public function render(): string
    {
        if (!Gestionale::feature('quotes')) {
            return '';
        }

        try {
            return self::markup(QuoteCounts::counts(), self::urls());
        } catch (Throwable $error) {
            // La home si apre lo stesso: il guasto va nel log, non in pagina.
            Errors::internal($error, 'widget.quotes');

            return '';
        }
    }

    /** @return array{da_inviare: string, in_attesa: string} */
    public static function urls(): array
    {
        $list = QuoteResource::listUrl().'?'.Order::$table.'__status=';

        return ['da_inviare' => $list.'draft', 'in_attesa' => $list.'sent'];
    }

    /**
     * @param array{da_inviare: int, in_attesa: int, in_scadenza: int} $counts
     * @param array<string, string> $urls
     */
    public static function markup(array $counts, array $urls = []): string
    {
        $counts = QuoteCounts::normalize($counts);
        $expiring = $counts['in_scadenza'];

        return WidgetCard::halfRow([
            StatusCard::make('Preventivi da inviare', self::quotes($counts['da_inviare']))
                ->icon('bi-file-earmark-text')
                ->variant($counts['da_inviare'] > 0 ? 'warning' : 'success')
                ->detail('Bozze non ancora mandate al cliente.')
                ->href($urls['da_inviare'] ?? ''),
            StatusCard::make('Preventivi in attesa', self::quotes($counts['in_attesa']))
                ->icon('bi-send')
                ->variant($expiring > 0 ? 'warning' : 'primary')
                ->detail(match ($expiring) {
                    0 => 'Inviati, aspettano la risposta del cliente.',
                    1 => '1 scade entro '.QuoteCounts::EXPIRING_DAYS.' giorni.',
                    default => $expiring.' scadono entro '.QuoteCounts::EXPIRING_DAYS.' giorni.',
                })
                ->href($urls['in_attesa'] ?? ''),
        ]);
    }

    private static function quotes(int $count): string
    {
        return $count === 1 ? '1 preventivo' : $count.' preventivi';
    }
}
