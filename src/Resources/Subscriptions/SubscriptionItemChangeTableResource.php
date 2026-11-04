<?php

namespace Wonder\Plugin\Gestionale\Resources\Subscriptions;

use Wonder\App\ResourceSchema\TableColumn;
use Wonder\Plugin\Gestionale\Models\Subscriptions\SubscriptionItemChange;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderResource;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderSectionResource;

/** La storia dei servizi: aggiunti, tolti, cambiati, corretti. Il più recente in alto. */
final class SubscriptionItemChangeTableResource extends OrderSectionResource
{
    public static string $feature = 'subscriptions';
    public static string $model = SubscriptionItemChange::class;
    public static string $foreignKey = 'subscription_id';
    public static string $orderDirection = 'DESC';

    public const ACTIONS = ['added' => 'Aggiunto', 'removed' => 'Tolto', 'changed' => 'Cambiato', 'customized' => 'Corretto'];

    public const BILLINGS = [
        'prorate' => 'Quota', 'free' => 'Gratis', 'next_period' => 'Dal prossimo periodo',
        'period_end' => 'A fine periodo', 'credit' => 'Con credito',
    ];

    public static function path(): string
    {
        return 'app/gestionale/abbonamento-storia-servizi';
    }

    public static function icon(): string
    {
        return 'bi-arrow-left-right';
    }

    public static function titleLabel(): string
    {
        return 'Storia dei servizi';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'cambio',
            'plural_label' => 'cambi',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'i',
            'this' => 'questo',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'changed_at' => 'Quando',
            'action' => 'Cosa',
            'new_plan_price_id' => 'Servizio',
            'billing' => 'Conguaglio',
            'amount' => 'Importo',
            'source' => 'Da dove',
        ];
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('changed_at')
                ->text()
                ->size('medium')
                ->formatter(static fn (array $row): string => static::whenOf($row)),
            TableColumn::key('action')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(self::ACTIONS[(string) ($row['action'] ?? '')] ?? (string) ($row['action'] ?? ''))),
            TableColumn::key('new_plan_price_id')
                ->text()
                ->formatter(static fn (array $row): string => static::serviceOf($row)),
            TableColumn::key('billing')
                ->text()
                ->size('medium')
                ->formatter(static fn (array $row): string => static::escape(self::BILLINGS[(string) ($row['billing'] ?? '')] ?? '')),
            TableColumn::key('amount')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::amountOf($row)),
            TableColumn::key('source')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(SubscriptionResource::sourceLabel((string) ($row['source'] ?? '')))),
        ];
    }

    /** Il giorno del cambio, e sotto da quando vale se è un altro giorno. */
    public static function whenOf(array $row): string
    {
        $changed = SubscriptionResource::day($row['changed_at'] ?? '');
        $effective = SubscriptionResource::day($row['effective_at'] ?? '');

        return static::escape($changed)
            .($effective !== '' && $effective !== $changed ? '<div class="small text-muted">dal '.static::escape($effective).'</div>' : '');
    }

    /** «Vecchio → nuovo» quando cambia, altrimenti il servizio; sotto la nota. */
    public static function serviceOf(array $row): string
    {
        $old = SubscriptionResource::planLabel((int) ($row['old_plan_price_id'] ?? 0));
        $new = SubscriptionResource::planLabel((int) ($row['new_plan_price_id'] ?? 0));
        $note = trim((string) ($row['note'] ?? ''));

        return static::escape($old !== '' && $new !== '' && $old !== $new ? $old.' → '.$new : ($new !== '' ? $new : $old))
            .($note !== '' ? '<div class="small text-muted">'.static::escapeStored($note).'</div>' : '');
    }

    /** L'importo del conguaglio, se c'è, e il credito rimasto da usare. */
    public static function amountOf(array $row): string
    {
        $amount = (float) ($row['amount'] ?? 0);
        $credit = (float) ($row['credit_left'] ?? 0);

        return static::escape($amount > 0 ? OrderResource::money($amount) : '')
            .($credit > 0 ? '<div class="small text-muted">credito da usare '.static::escape(OrderResource::money($credit)).'</div>' : '');
    }

    protected static function emptyText(): string
    {
        return 'Nessun cambio ai servizi.';
    }

    protected static function sortColumn(): string
    {
        return 'id';
    }
}
