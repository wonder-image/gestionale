<?php

namespace Wonder\Plugin\Gestionale\Models\Subscriptions;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * Un cambio di piano (G10.13): da quale prezzo a quale, chi, da dove e quando.
 * Lo scrivono il piano 2 (area cliente e backend) e il passaggio automatico a
 * fine vincolo.
 */
final class SubscriptionPlanChange extends Model
{
    public static string $table = 'gst_subscription_plan_changes';
    public static string $folder = 'gestionale/subscriptions';
    public static string $icon = 'bi bi-arrow-left-right';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('subscription_id')->int()->null(false)->foreign(Subscription::$table),
            Column::key('old_plan_price_id')->int()->default(0),
            Column::key('new_plan_price_id')->int()->default(0),
            Column::key('user_id')->int()->default(0),
            Column::key('source')->length(20),
            Column::key('changed_at')->datetime(),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_subscription' => ['index' => 'subscription_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('subscription_id')->number()->decimals(0),
            Field::key('old_plan_price_id')->number()->decimals(0),
            Field::key('new_plan_price_id')->number()->decimals(0),
            Field::key('user_id')->number()->decimals(0),
            Field::key('source')->text()->sanitize(false),
            Field::key('changed_at')->date(),
        ];
    }
}
