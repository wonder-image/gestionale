<?php

namespace Wonder\Plugin\Gestionale\Models\Subscriptions;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Columns;
use Wonder\Sql\TableSchema as Column;

/**
 * La storia delle righe di un abbonamento (G10b): una riga aggiunta, tolta,
 * cambiata di prezzo o di personalizzazioni, con la scelta d'addebito
 * (`billing`), chi, da dove e quando.
 *
 * `amount` è la quota pro-rata addebitata o il credito dato; `credit_left` il
 * credito ancora da usare nei proforma dopo (0 se non è un credito).
 */
final class SubscriptionItemChange extends Model
{
    public static string $table = 'gst_subscription_item_changes';
    public static string $folder = 'gestionale/subscriptions';
    public static string $icon = 'bi bi-arrow-left-right';

    public const ACTIONS = ['added', 'removed', 'changed', 'customized'];
    public const BILLINGS = ['prorate', 'free', 'next_period', 'period_end', 'credit', ''];

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('subscription_id')->int()->null(false)->foreign(Subscription::$table),
            Column::key('item_id')->int()->default(0),
            Column::key('action')->enum(static::ACTIONS)->default('changed'),
            Column::key('old_plan_price_id')->int()->default(0),
            Column::key('new_plan_price_id')->int()->default(0),
            Column::key('billing')->length(20),
            Columns::decimal('amount', '12,2'),
            Columns::decimal('credit_left', '12,2'),
            Column::key('effective_at')->datetime(),
            Column::key('user_id')->int()->default(0),
            Column::key('source')->length(20),
            Column::key('changed_at')->datetime(),
            Column::key('note')->type('TEXT'),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_subscription' => ['index' => 'subscription_id'],
            'ind_item' => ['index' => 'item_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('subscription_id')->number()->decimals(0),
            Field::key('item_id')->number()->decimals(0),
            Field::key('action')->text()->sanitize(false),
            Field::key('old_plan_price_id')->number()->decimals(0),
            Field::key('new_plan_price_id')->number()->decimals(0),
            Field::key('billing')->text()->sanitize(false),
            Field::key('amount')->number()->decimals(2),
            Field::key('credit_left')->number()->decimals(2),
            Field::key('effective_at')->date(),
            Field::key('user_id')->number()->decimals(0),
            Field::key('source')->text()->sanitize(false),
            Field::key('changed_at')->date(),
            Field::key('note')->text(),
        ];
    }
}
