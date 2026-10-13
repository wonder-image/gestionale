<?php

namespace Wonder\Plugin\Gestionale\Resources\Subscriptions;

use Throwable;
use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\App\ResourceSchema\TableLayoutSchema;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\DataItem;
use Wonder\Elements\Components\RichText;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Plan;
use Wonder\Plugin\Gestionale\Models\Subscriptions\PlanPrice;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Subscription;
use Wonder\Plugin\Gestionale\Models\Subscriptions\SubscriptionPlanChange;
use Wonder\Plugin\Gestionale\Models\Subscriptions\SubscriptionStatusLog;
use Wonder\Plugin\Gestionale\Resources\Contacts\CustomerResource;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderResource;
use Wonder\Plugin\Gestionale\Support\Contacts\Contacts;

/**
 * «Abbonamenti» (G10 §1.2), in sola lettura: in G10.1 nessuno li crea dal
 * backend. Li scrivono le notifiche di Stripe (piano 2) e *Nuovo abbonamento*
 * da ufficio (piano 3).
 *
 * L'elenco ha cliente, piano, stato, prossimo rinnovo, totale e canale, con i
 * filtri per stato e canale. La scheda ha il periodo, i pagamenti
 * (`gst_payments.subscription_id`), i cambi di piano e la storia degli stati.
 */
final class SubscriptionResource extends GestionaleResource
{
    public static string $feature = 'subscriptions';
    public static string $model = Subscription::class;
    public static string $orderColumn = 'id';
    public static string $orderDirection = 'DESC';
    public static string $docsPage = 'vendite/vendite-abbonamenti';

    public static function path(): string
    {
        return 'app/gestionale/abbonamenti';
    }

    public static function icon(): string
    {
        return 'bi-arrow-repeat';
    }

    public static function titleLabel(): string
    {
        return 'Abbonamenti';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'abbonamento',
            'plural_label' => 'abbonamenti',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'gli',
            'full' => 'attivo',
            'empty' => 'chiuso',
            'this' => 'questo',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'code' => 'Codice',
            'customer_id' => 'Cliente',
            'plan_price_id' => 'Piano',
            'status' => 'Stato',
            'next_renewal_at' => 'Prossimo rinnovo',
            'total' => 'Totale',
            'channel' => 'Canale',
        ];
    }

    /** @return array<string, string> */
    public static function statusLabels(): array
    {
        return [
            'pending' => 'In attesa',
            'trialing' => 'In prova',
            'active' => 'Attivo',
            'past_due' => 'Da pagare',
            'unpaid' => 'Non pagato',
            'cancelled' => 'Chiuso',
        ];
    }

    /** @return array<string, string> */
    public static function channelLabels(): array
    {
        return ['online' => 'Sito', 'office' => 'Ufficio'];
    }

    public static function statusBadge(string $status): string
    {
        $class = [
            'pending' => 'secondary',
            'trialing' => 'info',
            'active' => 'success',
            'past_due' => 'warning',
            'unpaid' => 'danger',
            'cancelled' => 'light',
        ][$status] ?? 'light';

        return '<span class="badge text-bg-'.$class.'">'.static::escape(static::statusLabels()[$status] ?? $status).'</span>';
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('code')->text()->link('view'),
            TableColumn::key('customer_id')
                ->text()
                ->formatter(static fn (array $row): string => static::escape(static::customerName((int) ($row['customer_id'] ?? 0)))),
            TableColumn::key('plan_price_id')
                ->text()
                ->formatter(static fn (array $row): string => static::escape(static::planLabel((int) ($row['plan_price_id'] ?? 0)))),
            TableColumn::key('status')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::statusBadge((string) ($row['status'] ?? ''))),
            TableColumn::key('next_renewal_at')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(static::day($row['next_renewal_at'] ?? ''))),
            TableColumn::key('total')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(OrderResource::money((float) ($row['total'] ?? 0)))),
            TableColumn::key('channel')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(static::channelLabels()[(string) ($row['channel'] ?? '')] ?? '')),
            TableColumn::key('actions')->button()->actions(['view']),
        ];
    }

    public static function tableLayoutSchema(): TableLayoutSchema
    {
        return TableLayoutSchema::for(static::class)
            ->title('Abbonamenti')
            ->results()
            ->hideButtonAdd()
            ->filterSearch()
            ->searchFields(['code', 'billing_name', 'billing_surname', 'billing_business_name', 'coupon_code'])
            ->filterCustom('Stato', 'status', static::statusLabels())
            ->filterCustom('Canale', 'channel', static::channelLabels());
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()
            ->only(['list'])
            ->enable(['view'])
            ->titles(['list' => 'Abbonamenti', 'view' => 'Abbonamento'])
            ->subtitles(['list' => 'Gli abbonamenti dei clienti, dal sito e dall\'ufficio. Si leggono e basta: li creano il sito e, da ufficio, il commerciante.'])
            ->view('show', Gestionale::viewPath('pages/subscription-show.php'));
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->backend(['list', 'view'], ['admin', 'administrator']);
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->section('vendite', 'Vendite', 'bi-receipt', 300, ['admin', 'administrator'])
            ->title('Abbonamenti')
            ->order(55)
            ->authority(['admin', 'administrator'])
            ->enabled(static::featureActive());
    }

    /**
     * La scheda: abbonamento e periodo a sinistra, importi a destra; sotto
     * pagamenti, cambi di piano e storia degli stati.
     *
     * @param array<string, mixed> $row
     */
    public static function showLayoutSchema(array $row): Container
    {
        $id = (int) ($row['id'] ?? 0);
        $dato = static fn (string $etichetta, string $valore, bool $html = false): DataItem => DataItem::make($etichetta, $valore !== '' ? $valore : '—')
            ->html($html)
            ->columnSpan(['default' => 12, 'sm' => 4]);
        $customerId = (int) ($row['customer_id'] ?? 0);
        $customer = $customerId > 0
            ? '<a href="'.static::escape(CustomerResource::viewUrl($customerId)).'">'.static::escape(static::customerName($customerId)).'</a>'
            : '';
        $coupon = trim((string) ($row['coupon_code'] ?? ''));

        $main = (new Card)->components([
            SectionTitle::make('Abbonamento')->columnSpan(12),
            $dato('Stato', static::statusBadge((string) ($row['status'] ?? '')), true),
            $dato('Cliente', $customer, true),
            $dato('Piano', static::planLabel((int) ($row['plan_price_id'] ?? 0))),
            $dato('Canale', static::channelLabels()[(string) ($row['channel'] ?? '')] ?? ''),
            $dato('Pagamento', (string) ($row['provider'] ?? '') === 'stripe' ? 'Stripe' : 'Registrato a mano'),
            $dato('Rinnovo automatico', (string) ($row['auto_renew'] ?? 'true') === 'true' ? 'Sì' : 'No'),
        ])->columns(12)->columnSpan(12);

        $period = (new Card)->components([
            SectionTitle::make('Periodo')
                ->tooltip('Il periodo in corso e le date che contano: la fine della prova, del vincolo, il prossimo rinnovo. Con una disdetta durante il vincolo, «Disdetta chiesta» dice quando: vale a fine vincolo.')
                ->columnSpan(12),
            $dato('Iniziato', static::day($row['started_at'] ?? '')),
            $dato('Periodo in corso', static::range($row['current_period_start'] ?? '', $row['current_period_end'] ?? '')),
            $dato('Prossimo rinnovo', static::day($row['next_renewal_at'] ?? '')),
            $dato('Fine prova', static::day($row['trial_ends_at'] ?? '')),
            $dato('Fine vincolo', static::day($row['commitment_ends_at'] ?? '')),
            $dato('Disdetta chiesta', static::day($row['cancel_requested_at'] ?? '')),
            $dato('Chiuso', static::day($row['cancelled_at'] ?? '')),
            $dato('Finito', static::day($row['ended_at'] ?? '')),
        ])->columns(12)->columnSpan(12);

        $amounts = (new Card)->components([
            SectionTitle::make('Importi del periodo')->columnSpan(12),
            DataItem::make('Subtotale', OrderResource::money((float) ($row['subtotal'] ?? 0)))->columnSpan(12),
            DataItem::make('Sconto'.($coupon !== '' ? ' ('.$coupon.')' : ''), OrderResource::money((float) ($row['discount_total'] ?? 0)))->columnSpan(12),
            DataItem::make('IVA '.rtrim(rtrim(number_format((float) ($row['tax_rate'] ?? 0), 2, ',', ''), '0'), ',').'%', OrderResource::money((float) ($row['tax_total'] ?? 0)))->columnSpan(12),
            DataItem::make('Totale', OrderResource::money((float) ($row['total'] ?? 0)))->columnSpan(12),
        ])->columns(12)->columnSpan(12);

        $tables = [
            static::tableCard('Pagamenti', static::paymentsHtml($id)),
            static::tableCard('Cambi di piano', static::changesHtml($id)),
            static::tableCard('Storia', static::historyHtml($id)),
        ];

        return (new Container)->components([
            (new Container)->components([$main, $period, ...$tables])->columns(12)->columnSpan(8),
            (new Container)->components([$amounts])->columns(12)->columnSpan(4),
        ])->columns(12);
    }

    /** L'indirizzo della scheda; il percorso è il ripiego. */
    public static function detailUrl(int $id): string
    {
        $fallback = '/backend/'.static::path().'/'.$id.'/';

        if (!function_exists('__r')) {
            return $fallback;
        }

        try {
            $named = (string) __r('backend.resource.'.static::slug().'.view', ['id' => $id]);
        } catch (Throwable) {
            return $fallback;
        }

        return $named !== '' ? $named : $fallback;
    }

    /** «Pro — Mensile»; vuoto se il prezzo non c'è più. */
    public static function planLabel(int $planPriceId): string
    {
        $price = $planPriceId > 0 ? (static::rowsOf(PlanPrice::class, ['id' => $planPriceId])[0] ?? null) : null;

        if ($price === null) {
            return $planPriceId > 0 ? '#'.$planPriceId : '';
        }

        $plan = static::rowsOf(Plan::class, ['id' => (int) $price['plan_id']])[0] ?? null;

        return trim(($plan !== null ? (string) $plan['name'].' — ' : '').(string) $price['name']);
    }

    /** I pagamenti dell'abbonamento, dal più recente. */
    public static function paymentsHtml(int $subscriptionId): string
    {
        $rows = $subscriptionId > 0 ? static::rowsOf(Payment::class, ['subscription_id' => $subscriptionId], 'id', 'DESC') : [];

        if ($rows === []) {
            return '<p class="text-muted mb-0">Nessun pagamento.</p>';
        }

        $statuses = ['pending' => 'In attesa', 'paid' => 'Pagato', 'failed' => 'Non riuscito', 'cancelled' => 'Annullato'];
        $html = '<table class="table table-sm align-middle mb-0"><thead><tr><th>Codice</th><th>Scadenza</th><th>Pagato il</th><th>Stato</th><th class="text-end">Importo</th></tr></thead><tbody>';

        foreach ($rows as $row) {
            $refund = (string) ($row['type'] ?? 'payment') === 'refund';
            $html .= '<tr><td>'.static::escape((string) ($row['code'] ?? '')).($refund ? ' <span class="badge text-bg-light">Rimborso</span>' : '').'</td>'
                .'<td>'.static::escape(static::day($row['due_date'] ?? '')).'</td>'
                .'<td>'.static::escape(static::day($row['paid_at'] ?? '')).'</td>'
                .'<td>'.static::escape($statuses[(string) ($row['status'] ?? '')] ?? (string) ($row['status'] ?? '')).'</td>'
                .'<td class="text-end">'.static::escape(($refund ? '−' : '').OrderResource::money((float) ($row['amount'] ?? 0))).'</td></tr>';
        }

        return $html.'</tbody></table>';
    }

    /** I cambi di piano, dal più recente. */
    public static function changesHtml(int $subscriptionId): string
    {
        $rows = $subscriptionId > 0 ? static::rowsOf(SubscriptionPlanChange::class, ['subscription_id' => $subscriptionId], 'id', 'DESC') : [];

        if ($rows === []) {
            return '<p class="text-muted mb-0">Nessun cambio di piano.</p>';
        }

        $html = '<table class="table table-sm align-middle mb-0"><thead><tr><th>Quando</th><th>Da</th><th>A</th><th>Da dove</th></tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr><td>'.static::escape(static::day($row['changed_at'] ?? '')).'</td>'
                .'<td>'.static::escape(static::planLabel((int) ($row['old_plan_price_id'] ?? 0))).'</td>'
                .'<td>'.static::escape(static::planLabel((int) ($row['new_plan_price_id'] ?? 0))).'</td>'
                .'<td>'.static::escape(static::sourceLabel((string) ($row['source'] ?? ''))).'</td></tr>';
        }

        return $html.'</tbody></table>';
    }

    /** La storia degli stati, dal più recente. */
    public static function historyHtml(int $subscriptionId): string
    {
        $rows = $subscriptionId > 0 ? static::rowsOf(SubscriptionStatusLog::class, ['subscription_id' => $subscriptionId], 'id', 'DESC') : [];

        if ($rows === []) {
            return '<p class="text-muted mb-0">Nessun cambio di stato.</p>';
        }

        $labels = static::statusLabels();
        $html = '<table class="table table-sm align-middle mb-0"><thead><tr><th>Quando</th><th>Da</th><th>A</th><th>Da dove</th><th>Nota</th></tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr><td>'.static::escape(OrderResource::date((string) ($row['creation'] ?? ''))).'</td>'
                .'<td>'.static::escape($labels[(string) ($row['from_value'] ?? '')] ?? (string) ($row['from_value'] ?? '')).'</td>'
                .'<td>'.static::escape($labels[(string) ($row['to_value'] ?? '')] ?? (string) ($row['to_value'] ?? '')).'</td>'
                .'<td>'.static::escape(static::sourceLabel((string) ($row['source'] ?? ''))).'</td>'
                .'<td>'.static::escapeStored((string) ($row['message'] ?? '')).'</td></tr>';
        }

        return $html.'</tbody></table>';
    }

    public static function sourceLabel(string $source): string
    {
        return [
            'user' => 'Backend',
            'api' => 'Sito',
            'webhook' => 'Stripe',
            'cron' => 'Automatico',
            'system' => 'Sistema',
        ][$source] ?? $source;
    }

    protected static function tableCard(string $title, string $html): Card
    {
        return (new Card)->components([
            SectionTitle::make($title)->columnSpan(12),
            RichText::make($html)->tag('div')->columnSpan(12),
        ])->columns(12)->columnSpan(12);
    }

    protected static function customerName(int $customerId): string
    {
        if ($customerId <= 0) {
            return '';
        }

        $row = static::rowsOf(Contact::class, ['id' => $customerId])[0] ?? null;

        return $row !== null ? Contacts::displayName($row) : '#'.$customerId;
    }

    /** `d/m/Y`; vuoto se manca o è la data zero di MySQL. */
    protected static function day(mixed $value): string
    {
        $value = trim((string) $value);

        if ($value === '' || str_starts_with($value, '0000') || strtotime($value) === false) {
            return '';
        }

        return date('d/m/Y', strtotime($value));
    }

    protected static function range(mixed $from, mixed $to): string
    {
        $from = static::day($from);
        $to = static::day($to);

        return $from !== '' && $to !== '' ? $from.' – '.$to : $from.$to;
    }
}
