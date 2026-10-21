<?php

namespace Wonder\Plugin\Gestionale\Backend\Widgets;

use Throwable;
use Wonder\App\Permission\Access;
use Wonder\Backend\Contracts\HomeWidget;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderResource;
use Wonder\Plugin\Gestionale\Resources\Sales\ScheduleResource;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;
use Wonder\Plugin\Gestionale\Support\Payments\DuePayments;
use Wonder\Plugin\Gestionale\Support\Stock\BatchExpiry;

/**
 * «Pagamenti in scadenza» (G9.3): le rate scadute o che scadono entro
 * `payment_due_days` giorni, dalla più vicina; le prime dieci e il totale da
 * incassare.
 *
 * Senza «Vendita in ufficio», o senza rate da guardare, il riquadro non c'è.
 */
final class DuePaymentsWidget implements HomeWidget
{
    public const LIMIT = 10;

    public function title(): string
    {
        return 'Pagamenti in scadenza';
    }

    public function authorities(): array
    {
        return Access::viewers(Gestionale::area('vendite'));
    }

    public function order(): int
    {
        return 26;
    }

    public function render(): string
    {
        if (!Gestionale::feature('office_sales')) {
            return '';
        }

        $today = date('Y-m-d');
        $days = DuePayments::days();

        try {
            $rows = self::rows(Payment::find(
                DuePayments::baseCondition()." AND status = 'pending' AND due_date <= '".BatchExpiry::plus($today, $days)."'",
                null,
                'due_date',
                'ASC'
            ));
            $summary = DuePayments::summary($rows, $today, $days);
            $items = [];

            foreach (array_slice($summary['rows'], 0, self::LIMIT) as $row) {
                $items[] = [
                    'customer' => ScheduleResource::customerOf($row),
                    'note' => (string) ($row['note'] ?? ''),
                    'amount' => (float) ($row['amount'] ?? 0),
                    'due_date' => (string) ($row['due_date'] ?? ''),
                    'url' => OrderResource::detailUrl((int) ($row['order_id'] ?? 0)),
                ];
            }
        } catch (Throwable $error) {
            Errors::internal($error, 'widget.due_payments');

            return '';
        }

        return self::markup($items, $summary['count'], $summary['total'], $summary['expired'], $today, $days, ScheduleResource::filteredUrl('attesa'));
    }

    /**
     * @param list<array{customer: string, note: string, amount: float, due_date: string, url: string}> $items
     */
    public static function markup(array $items, int $count, float $total, float $expired, string $today, int $days, string $listUrl): string
    {
        if ($items === []) {
            return '';
        }

        $e = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $list = '';

        foreach ($items as $item) {
            $list .= '<li class="d-flex justify-content-between gap-2 py-1 border-bottom">'
                .'<span class="min-w-0"><a href="'.$e($item['url']).'">'.$e($item['customer']).'</a>'
                .' <span class="small text-body-secondary">'.$e($item['note']).'</span></span>'
                .'<span class="text-nowrap small">'.$e(OrderSheet::money($item['amount'])).' · '.DuePayments::badge($item['due_date'], $today, $days).'</span>'
                .'</li>';
        }

        $more = $count > count($items) ? ' e altre '.($count - count($items)) : '';
        $giorni = $days === 1 ? 'giorno' : 'giorni';
        $scadute = $expired > 0 ? ', di cui <b>'.$e(OrderSheet::money($expired)).'</b> scaduti' : '';
        $button = WidgetCard::button($listUrl, 'Apri lo scadenzario'.$more);

        return WidgetCard::wrap(<<<HTML
    <h5 class="mb-1 d-flex justify-content-between align-items-center gap-3">
        <span><i class="bi bi-calendar-check"></i> Pagamenti in scadenza</span>
        {$button}
    </h5>
    <p class="text-body-secondary small mb-2">Rate scadute o entro {$days} {$giorni}: da incassare <b>{$e(OrderSheet::money($total))}</b>{$scadute}.</p>
    <ul class="list-unstyled mb-0">{$list}</ul>
HTML);
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $rows): array
    {
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }
}
