<?php

namespace Wonder\Plugin\Gestionale\Resources\Sales;

use Throwable;
use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\App\ResourceSchema\TableLayoutSchema;
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;
use Wonder\Plugin\Gestionale\Support\Payments\DuePayments;

/**
 * *Vendite → Scadenzario* (G9.3): le rate da incassare, dalla scadenza più
 * vicina. Una rata è una riga di `gst_payments` con la scadenza: la scrive
 * `Installments` alla conferma di un ordine da ufficio.
 *
 * Si consulta e basta: *Registra pagamento* porta alla pagina dell'incasso
 * sull'ordine, con la rata già scelta.
 */
final class ScheduleResource extends GestionaleResource
{
    public static string $feature = 'office_sales';
    public static string $model = Payment::class;
    public static string $orderColumn = 'due_date';
    public static string $orderDirection = 'ASC';
    public static string $docsPage = 'vendite/vendite-pagamenti-a-rate';

    /** @var array<int, array<string, mixed>> */
    private static array $orders = [];
    /** @var array<int, string>|null */
    private static ?array $methods = null;

    public static function path(): string
    {
        return 'app/gestionale/scadenzario';
    }

    public static function icon(): string
    {
        return 'bi-calendar-check';
    }

    public static function titleLabel(): string
    {
        return 'Scadenzario';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'rata',
            'plural_label' => 'rate',
            'last' => 'ultime',
            'all' => 'tutte',
            'article' => 'le',
            'this' => 'questa',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'due_date' => 'Scadenza',
            'customer' => 'Cliente',
            'order_id' => 'Ordine',
            'note' => 'Rata',
            'amount' => 'Importo',
            'payment_method_id' => 'Metodo',
            'status' => 'Stato',
        ];
    }

    public static function formSchema(): array
    {
        return [];
    }

    public static function tableSchema(): array
    {
        $today = date('Y-m-d');
        $days = DuePayments::days();

        return [
            TableColumn::key('due_date')
                ->text()
                ->size('little')
                ->sortable()
                ->formatter(static fn (array $row): string => DuePayments::badge((string) ($row['due_date'] ?? ''), $today, $days)),
            TableColumn::key('customer')
                ->text()
                ->formatter(static fn (array $row): string => static::escape(static::customerOf($row))),
            TableColumn::key('order_id')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::orderLink((int) ($row['order_id'] ?? 0))),
            TableColumn::key('note')->text()->hiddenDevice('mobile'),
            TableColumn::key('amount')->price()->size('little')->sortable(),
            TableColumn::key('payment_method_id')
                ->text()
                ->size('little')
                ->hiddenDevice('mobile')
                ->formatter(static fn (array $row): string => static::escape(static::methodName((int) ($row['payment_method_id'] ?? 0)))),
            TableColumn::key('status')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::statusLabel($row)),
            TableColumn::key('actions')->button()->actions([
                'registra' => [
                    'label' => 'Registra pagamento',
                    'href' => OrderPaymentResource::installmentUrlPattern('{order_id}', '{id}', static::listUrl()),
                    'filter' => ['row' => ['status' => ['pending']]],
                ],
                'ordine' => [
                    'label' => 'Visualizza ordine',
                    'href' => OrderResource::detailUrlPattern('{order_id}'),
                ],
            ]),
        ];
    }

    public static function tableLayoutSchema(): TableLayoutSchema
    {
        return TableLayoutSchema::for(static::class)
            ->title('Scadenzario')
            ->results()
            ->hideButtonAdd()
            ->filterSearch()
            ->searchFields(['note', [
                'table' => Order::$table,
                'local_key' => 'order_id',
                'foreign_key' => 'id',
                'columns' => ['order_number', 'billing_business_name', 'billing_name', 'billing_surname', 'email'],
            ]])
            ->filterQuery(
                'Stato',
                'stato',
                DuePayments::filterOptions(),
                static fn (array $values): string => DuePayments::filterCondition($values, date('Y-m-d'), DuePayments::days()),
                'select',
                false,
                'attesa'
            );
    }

    /**
     * Solo le rate: gli incassi con una scadenza. Il pagamento del checkout e
     * il bonifico registrato a mano non ne hanno.
     *
     * @return array<string, mixed>
     */
    public static function querySchema(): array
    {
        $schema = parent::querySchema();
        $schema['condition'] = DuePayments::baseCondition();

        return $schema;
    }

    public static function pageSchema(): PageSchema
    {
        return static::withFeature(parent::pageSchema()
            ->only(['list'])
            ->titles(['list' => 'Scadenzario'])
            ->subtitles(['list' => 'Le rate degli ordini da incassare, dalla scadenza più vicina. In rosso le scadute, in giallo quelle che scadono a breve.']));
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->inSection('vendite')
            ->title('Scadenzario')
            ->order(15)
            ->authority(['admin', 'administrator'])
            ->enabled(static::featureActive());
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->backend(['list'], ['admin', 'administrator']);
    }

    public static function listUrl(): string
    {
        $fallback = '/backend/'.static::path();

        if (!function_exists('__r')) {
            return $fallback;
        }

        try {
            $named = (string) __r('backend.resource.'.static::slug().'.list');
        } catch (Throwable) {
            return $fallback;
        }

        return $named !== '' ? $named : $fallback;
    }

    /** L'elenco già filtrato: `scadute`, `scadenza`, `attesa`, `pagate`. */
    public static function filteredUrl(string $stato): string
    {
        return static::listUrl().'?'.Payment::$table.'__stato='.rawurlencode($stato);
    }

    /** «In attesa», o «Pagata il 12/10/2026», o «Annullata». */
    public static function statusLabel(array $row): string
    {
        return match ((string) ($row['status'] ?? '')) {
            'pending' => '<span class="badge text-bg-secondary">In attesa</span>',
            'paid' => '<span class="badge text-bg-success">Pagata il '.static::escape(substr(OrderSheet::date((string) ($row['paid_at'] ?? '')), 0, 10)).'</span>',
            'cancelled' => '<span class="badge text-bg-light">Annullata</span>',
            default => static::escape((string) ($row['status'] ?? '')),
        };
    }

    /** Il cliente della rata: quello dell'ordine, come lo mostrano gli Ordini. */
    public static function customerOf(array $row): string
    {
        $order = static::orderOf((int) ($row['order_id'] ?? 0));

        return $order === [] ? '—' : OrderResource::customerName($order);
    }

    private static function orderLink(int $orderId): string
    {
        $order = static::orderOf($orderId);

        if ($order === []) {
            return '—';
        }

        $number = trim((string) ($order['order_number'] ?? ''));

        return '<a href="'.static::escape(OrderResource::detailUrl($orderId, static::listUrl())).'">'
            .static::escape($number !== '' ? $number : '#'.$orderId).'</a>';
    }

    /** @return array<string, mixed> */
    private static function orderOf(int $orderId): array
    {
        if ($orderId <= 0) {
            return [];
        }

        if (!array_key_exists($orderId, static::$orders)) {
            try {
                $order = Order::findById($orderId);
            } catch (Throwable) {
                $order = null;
            }

            static::$orders[$orderId] = is_array($order) ? $order : [];
        }

        return static::$orders[$orderId];
    }

    private static function methodName(int $id): string
    {
        if (static::$methods === null) {
            static::$methods = [];

            foreach (static::rowsOf(PaymentMethod::class, [], 'position') as $method) {
                static::$methods[(int) $method['id']] = (string) ($method['name'] ?? '');
            }
        }

        return static::$methods[$id] ?? '—';
    }
}
