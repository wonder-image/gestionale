<?php

namespace Wonder\Plugin\Gestionale\Resources\Sales;

use Wonder\App\ResourceSchema\TableColumn;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Support\Orders\StatusLabels;

/** Gli ordini di un cliente, per la sua scheda: ogni numero porta alla scheda dell'ordine. */
final class CustomerOrderTableResource extends OrderSectionResource
{
    public static string $model = Order::class;
    public static string $orderColumn = 'ordered_at';
    public static string $orderDirection = 'DESC';

    public static function path(): string
    {
        return 'app/gestionale/cliente-ordini';
    }

    public static function icon(): string
    {
        return 'bi-receipt';
    }

    public static function titleLabel(): string
    {
        return 'Ordini del cliente';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'ordine',
            'plural_label' => 'ordini',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'gli',
            'this' => 'questo',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'order_number' => 'Numero',
            'ordered_at' => 'Data',
            'total' => 'Totale',
            'status' => 'Ordine',
            'payment_status' => 'Pagamento',
            'fulfillment_status' => 'Evasione',
        ];
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('order_number')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => '<a href="'.static::escape(OrderResource::detailUrl((int) ($row['id'] ?? 0))).'">'
                    .static::escape(trim((string) ($row['order_number'] ?? '')) ?: '—').'</a>'),
            TableColumn::key('ordered_at')->date()->size('medium'),
            TableColumn::key('total')->price()->size('little'),
            TableColumn::key('status')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => StatusLabels::badge('order', (string) ($row['status'] ?? ''))),
            TableColumn::key('payment_status')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => StatusLabels::badge('payment', (string) ($row['payment_status'] ?? ''))),
            TableColumn::key('fulfillment_status')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => StatusLabels::badge('fulfillment', (string) ($row['fulfillment_status'] ?? ''))),
        ];
    }

    /**
     * Gli ordini veri di un cliente: quelli sulla sua scheda e quelli fatti
     * con il suo account del sito, anche se l'ordine non porta la scheda.
     */
    public static function embedForCustomer(int $customerId, int $userId = 0): string
    {
        if ($customerId <= 0) {
            return static::embedMany([]);
        }

        return static::embedWhere(static::condition($customerId, $userId));
    }

    /** La condizione degli ordini del cliente, pubblica perché la usa anche la scheda per leggerli. */
    public static function condition(int $customerId, int $userId = 0): string
    {
        $mine = '`customer_id` = '.$customerId.($userId > 0 ? ' OR `user_id` = '.$userId : '');

        return "`stage` = 'order' AND ({$mine})";
    }

    protected static function emptyText(): string
    {
        return 'Nessun ordine: questo cliente non ha ancora acquistato.';
    }
}
