<?php

namespace Wonder\Plugin\Gestionale\Resources\Subscriptions;

use Wonder\App\ResourceSchema\TableColumn;
use Wonder\Elements\Components\Badge;
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderPaymentTableResource;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderResource;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderSectionResource;
use Wonder\Plugin\Gestionale\Support\Backend\RowMenu;

/**
 * I pagamenti dell'abbonamento (`gst_payments.subscription_id`), dal più
 * recente: le stesse colonne dei pagamenti dell'ordine, più la scadenza della
 * rata. Una rata in attesa si registra dal menu ⋯.
 */
final class SubscriptionPaymentTableResource extends OrderSectionResource
{
    public static string $feature = 'subscriptions';
    public static string $model = Payment::class;
    public static string $foreignKey = 'subscription_id';
    public static string $orderDirection = 'DESC';

    public static function path(): string
    {
        return 'app/gestionale/abbonamento-pagamenti';
    }

    public static function icon(): string
    {
        return 'bi-cash-coin';
    }

    public static function titleLabel(): string
    {
        return 'Pagamenti dell\'abbonamento';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'pagamento',
            'plural_label' => 'pagamenti',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'i',
            'this' => 'questo',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'code' => 'Codice',
            'type' => 'Tipo',
            'amount' => 'Importo',
            'due_date' => 'Scadenza',
            'status' => 'Stato',
            'paid_at' => 'Data',
            'payment_method_id' => 'Metodo',
            'provider' => 'Gestito da',
            'actions' => '',
        ];
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('code')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::codeOf($row)),
            TableColumn::key('type')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::isRefund($row) ? 'Rimborso' : 'Incasso'),
            TableColumn::key('amount')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape((static::isRefund($row) ? '−' : '').OrderResource::money((float) ($row['amount'] ?? 0)))),
            TableColumn::key('due_date')
                ->text()
                ->size('medium')
                ->formatter(static fn (array $row): string => static::escape(SubscriptionResource::day($row['due_date'] ?? ''))),
            TableColumn::key('status')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => OrderPaymentTableResource::statusOf($row)),
            TableColumn::key('paid_at')
                ->text()
                ->size('medium')
                ->formatter(static fn (array $row): string => static::escape(SubscriptionResource::day($row['paid_at'] ?? ''))),
            TableColumn::key('payment_method_id')
                ->text()
                ->formatter(static fn (array $row): string => OrderPaymentTableResource::orEmpty(PaymentMethod::choiceLabel(
                    (string) ($row['provider_method'] ?? ''),
                    OrderPaymentTableResource::methodName((int) ($row['payment_method_id'] ?? 0))
                ))),
            TableColumn::key('provider')
                ->text()
                ->formatter(static fn (array $row): string => OrderPaymentTableResource::orEmpty(OrderPaymentTableResource::providerName((string) ($row['provider'] ?? '')))),
            TableColumn::key('actions')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::actionsOf($row)),
        ];
    }

    /** Il codice, «Rimborso» se lo è, e sotto la nota. */
    public static function codeOf(array $row): string
    {
        $note = trim((string) ($row['note'] ?? ''));

        return static::escape((string) ($row['code'] ?? ''))
            .(static::isRefund($row) ? ' '.Badge::make('Rimborso')->variant('light')->inline()->render('bootstrap') : '')
            .($note !== '' ? '<div class="small text-muted">'.static::escapeStored($note).'</div>' : '');
    }

    /** «Registra pagamento» per una rata in attesa con la sua scadenza: apre la pagina della rata. */
    public static function actionsOf(array $row): string
    {
        $id = (int) ($row['id'] ?? 0);
        $subscriptionId = (int) ($row['subscription_id'] ?? 0);

        if ($id <= 0 || $subscriptionId <= 0 || (string) ($row['status'] ?? '') !== 'pending' || trim((string) ($row['due_date'] ?? '')) === '') {
            return '';
        }

        return RowMenu::render([[
            'label' => 'Registra pagamento',
            'icon' => 'bi bi-cash-coin',
            'href' => SubscriptionPaymentResource::urlFor((string) $subscriptionId, (string) $id, SubscriptionResource::detailUrl($subscriptionId)),
        ]]);
    }

    protected static function emptyText(): string
    {
        return 'Nessun pagamento.';
    }

    private static function isRefund(array $row): bool
    {
        return (string) ($row['type'] ?? 'payment') === 'refund';
    }
}
