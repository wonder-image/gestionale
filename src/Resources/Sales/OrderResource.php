<?php

namespace Wonder\Plugin\Gestionale\Resources\Sales;

use Throwable;
use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\App\ResourceSchema\TableLayoutSchema;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Orders\StatusLabels;

/**
 * "Ordini": gli ordini veri, in sola consultazione.
 *
 * Un ordine non si scrive a mano da qui — nasce dal checkout e cambia con le
 * azioni della sua scheda, che passano da `Lifecycle` e `Ledger`. L'elenco
 * mostra solo le righe con `stage = 'order'`: i carrelli stanno nella stessa
 * tabella ma non sono ordini finché non passano dal checkout.
 */
final class OrderResource extends GestionaleResource
{
    public static string $model = Order::class;
    public static string $feature = 'orders';
    public static string $orderColumn = 'ordered_at';
    public static string $orderDirection = 'DESC';

    public static function path(): string
    {
        return 'app/gestionale/ordini';
    }

    public static function icon(): string
    {
        return 'bi-receipt';
    }

    public static function titleLabel(): string
    {
        return 'Ordini';
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
            'customer' => 'Cliente',
            'total' => 'Totale',
            'status' => 'Ordine',
            'payment_status' => 'Pagamento',
            'fulfillment_status' => 'Evasione',
        ];
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('order_number')->text()->size('little')->sortable(),
            TableColumn::key('ordered_at')
                ->text()
                ->size('little')
                ->sortable()
                ->formatter(static fn (array $row): string => static::escape(static::date((string) ($row['ordered_at'] ?? '')))),
            TableColumn::key('customer')
                ->text()
                ->formatter(static fn (array $row): string => static::escape(static::customerName($row))),
            TableColumn::key('total')
                ->text()
                ->size('little')
                ->sortable()
                ->formatter(static fn (array $row): string => '<span class="d-block text-end" style="font-variant-numeric: tabular-nums">'
                    .static::escape(static::money($row['total'] ?? 0)).'</span>'),
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

    public static function tableLayoutSchema(): TableLayoutSchema
    {
        return TableLayoutSchema::for(static::class)
            ->title('Ordini')
            ->results()
            ->hideButtonAdd()
            ->filterSearch()
            ->searchFields(['order_number', 'billing_name', 'billing_surname', 'billing_business_name', 'email'])
            ->filterCustom('Ordine', 'status', static::options('order', Order::LIVE_STATUSES))
            ->filterCustom('Pagamento', 'payment_status', static::options('payment', Order::PAYMENT_STATUSES))
            ->filterCustom('Evasione', 'fulfillment_status', static::options('fulfillment', Order::FULFILLMENT_STATUSES));
    }

    /**
     * Gli ordini, non i carrelli e non i preventivi.
     *
     * @return array<string, mixed>
     */
    public static function querySchema(): array
    {
        $schema = parent::querySchema();
        $schema['condition'] = "deleted = 'false' AND stage = 'order'";

        return $schema;
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()
            ->only(['list'])
            ->titles(['list' => 'Ordini'])
            ->subtitles(['list' => 'Gli ordini dei clienti, dal checkout alla consegna. Si consultano qui; ogni cambio passa dalle azioni della scheda.']);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->section('vendite', 'Vendite', 'bi-receipt', 350, ['admin', 'administrator'])
            ->title('Ordini')
            ->order(10)
            ->authority(['admin', 'administrator'])
            ->enabled(static::featureActive());
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->backend(['list'], ['admin', 'administrator']);
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
    }

    /** L'indirizzo dell'elenco, dalla rotta con il nome; il percorso è il ripiego. */
    public static function listUrl(): string
    {
        return static::routeUrl('list', '/backend/'.static::path().'/');
    }

    /** L'indirizzo della scheda di un ordine, con il ritorno facoltativo. */
    public static function detailUrl(int $id, ?string $back = null): string
    {
        $url = static::routeUrl('view', '/backend/'.static::path().'/'.$id.'/', ['id' => $id]);

        return $back === null || $back === '' ? $url : $url.'?torna='.rawurlencode($back);
    }

    /**
     * Il nome con cui l'ordine si riconosce: ragione sociale, poi nome e
     * cognome della fatturazione, poi l'email. Mai vuoto.
     */
    public static function customerName(array $row): string
    {
        $business = trim((string) ($row['billing_business_name'] ?? ''));

        if ($business !== '') {
            return $business;
        }

        $name = trim(trim((string) ($row['billing_name'] ?? '')).' '.trim((string) ($row['billing_surname'] ?? '')));

        if ($name !== '') {
            return $name;
        }

        $email = trim((string) ($row['email'] ?? ''));

        return $email !== '' ? $email : '—';
    }

    /** Un importo all'italiana, con l'euro: `1.234,50 €`. */
    public static function money(mixed $value): string
    {
        return number_format((float) $value, 2, ',', '.').' €';
    }

    /** `15/10/2025 10:30`; vuota o zero diventano un trattino. */
    public static function date(string $value): string
    {
        $time = $value === '' || str_starts_with($value, '0000') ? false : strtotime($value);

        return $time === false ? '—' : date('d/m/Y H:i', $time);
    }

    /**
     * Le opzioni di un filtro: le chiavi sono quelle del Model, le parole
     * quelle di `StatusLabels`.
     *
     * @param list<string> $values
     * @return array<string, string>
     */
    private static function options(string $kind, array $values): array
    {
        $options = ['' => 'Tutti'];

        foreach ($values as $value) {
            $options[$value] = match ($kind) {
                'order' => StatusLabels::order($value)['label'],
                'payment' => StatusLabels::payment($value)['label'],
                default => StatusLabels::fulfillment($value)['label'],
            };
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function routeUrl(string $action, string $fallback, array $params = []): string
    {
        if (!function_exists('__r')) {
            return $fallback;
        }

        try {
            $named = (string) __r('backend.resource.'.static::slug().'.'.$action, $params);
        } catch (Throwable) {
            return $fallback;
        }

        return $named !== '' ? $named : $fallback;
    }
}
