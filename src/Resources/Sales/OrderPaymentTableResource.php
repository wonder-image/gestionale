<?php

namespace Wonder\Plugin\Gestionale\Resources\Sales;

use Wonder\App\ResourceSchema\TableColumn;
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Resources\Payments\PaymentMethodResource;
use Wonder\Plugin\Gestionale\Support\Backend\RowMenu;
use Wonder\Plugin\Gestionale\Support\Orders\OrderActions;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;

/** I pagamenti dell'ordine: incassi e rimborsi. */
final class OrderPaymentTableResource extends OrderSectionResource
{
    public static string $model = Payment::class;

    /** @var array<int, bool> se l'ordine si può ancora incassare, per id */
    private static array $collectable = [];

    /** @var array<int, string>|null i nomi dei metodi, per id */
    private static ?array $methods = null;

    public static function path(): string
    {
        return 'app/gestionale/ordine-pagamenti';
    }

    public static function icon(): string
    {
        return 'bi-cash-coin';
    }

    public static function titleLabel(): string
    {
        return 'Pagamenti dell\'ordine';
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
            'status' => 'Stato',
            'paid_at' => 'Data',
            'payment_method_id' => 'Metodo',
            'provider' => 'Gestito da',
            'provider_reference' => 'Riferimento',
            'actions' => '',
        ];
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('code')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape((string) ($row['code'] ?? ''))),
            TableColumn::key('type')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => ($row['type'] ?? '') === 'refund' ? 'Rimborso' : 'Incasso'),
            TableColumn::key('amount')->price()->size('little'),
            TableColumn::key('status')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => OrderSheet::paymentBadge((string) ($row['status'] ?? ''))),
            TableColumn::key('paid_at')
                ->text()
                ->size('medium')
                ->formatter(static fn (array $row): string => static::escape(OrderSheet::date((string) ($row['paid_at'] ?? '')))),
            TableColumn::key('payment_method_id')
                ->text()
                ->formatter(static fn (array $row): string => static::orDash(PaymentMethod::choiceLabel(
                    (string) ($row['provider_method'] ?? ''),
                    static::methodName((int) ($row['payment_method_id'] ?? 0))
                ))),
            TableColumn::key('provider')
                ->text()
                ->formatter(static fn (array $row): string => static::orDash(static::providerName((string) ($row['provider'] ?? '')))),
            TableColumn::key('provider_reference')
                ->text()
                ->formatter(static fn (array $row): string => static::orDash((string) ($row['provider_reference'] ?? ''))),
            TableColumn::key('actions')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::actionsOf($row)),
        ];
    }

    /**
     * Il menu ⋯ della rata in attesa: «Registra pagamento» apre la sua
     * finestra, che la scheda disegna solo se l'ordine si può ancora incassare.
     */
    public static function actionsOf(array $row): string
    {
        if (!OrderPaymentResource::isPendingInstallment($row) || (int) ($row['id'] ?? 0) <= 0) {
            return '';
        }

        $orderId = (int) ($row['order_id'] ?? 0);

        if (!isset(self::$collectable[$orderId])) {
            $order = $orderId > 0 ? Order::findById($orderId) : null;
            self::$collectable[$orderId] = is_array($order) && $order !== [] && OrderActions::canRegisterPayment($order);
        }

        if (!self::$collectable[$orderId]) {
            return '';
        }

        return RowMenu::render([[
            'label' => 'Registra pagamento',
            'icon' => 'bi bi-cash-coin',
            'modal' => OrderPaymentResource::installmentModalId((int) $row['id']),
        ]]);
    }

    protected static function emptyText(): string
    {
        return 'Nessun pagamento registrato.';
    }

    private static function orDash(string $value): string
    {
        return trim($value) !== '' ? static::escape($value) : '—';
    }

    /** Chi ha gestito il pagamento: il gateway (Stripe…); vuoto se l'ha scritto una persona. */
    public static function providerName(string $provider): string
    {
        return PaymentMethod::ledgerProvider($provider) === 'manual' ? '' : (PaymentMethodResource::providers()[$provider] ?? ucfirst($provider));
    }

    private static function methodName(int $id): string
    {
        if (self::$methods === null) {
            self::$methods = [];

            foreach (static::rowsOf(PaymentMethod::class) as $metodo) {
                self::$methods[(int) $metodo['id']] = (string) ($metodo['name'] ?? '');
            }
        }

        return self::$methods[$id] ?? '';
    }
}
