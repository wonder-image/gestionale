<?php

namespace Wonder\Plugin\Gestionale\Models\Subscriptions;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Columns;
use Wonder\Sql\TableSchema as Column;

/**
 * Una riga di un abbonamento (G10b.1): un servizio, cioè un prezzo di un
 * piano, con quantità, personalizzazioni e legame alla riga madre
 * (`parent_item_id`, «Si lega a»). L'abbonamento tiene cliente, date di
 * rinnovo, pagamento e coupon; la riga il resto.
 *
 * Gli importi sono quelli del periodo pieno, senza coupon: il coupon vale
 * sull'abbonamento. `LIVE` sono le righe che il cliente ha: contano per i
 * limiti e per i piani che non si eliminano. Una riga `ending` finisce a fine
 * periodo (`ends_at`).
 */
final class SubscriptionItem extends Model
{
    public static string $table = 'gst_subscription_items';
    public static string $folder = 'gestionale/subscriptions';
    public static string $icon = 'bi bi-list-ul';

    public const STATUSES = ['pending', 'active', 'ending', 'ended'];
    public const LIVE = ['active', 'ending'];

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('subscription_id')->int()->null(false)->foreign(Subscription::$table),
            Column::key('plan_price_id')->int()->null(false)->foreign(PlanPrice::$table),
            Column::key('parent_item_id')->int()->default(0),
            Column::key('quantity')->int()->default(1),
            Column::key('status')->enum(static::STATUSES)->default('pending'),
            Column::key('customization')->type('TEXT'),
            Columns::decimal('unit_price', '12,2'),
            Columns::decimal('customization_total', '12,2'),
            Columns::decimal('subtotal', '12,2'),
            Columns::decimal('tax_total', '12,2'),
            Columns::decimal('total', '12,2'),
            Column::key('tax_id')->int()->default(0),
            Columns::decimal('tax_rate', '5,2'),
            Column::key('starts_at')->datetime(),
            Column::key('commitment_ends_at')->datetime(),
            Column::key('ends_at')->datetime(),
            Column::key('provider_reference')->length(191),
            Column::key('provider_price')->length(191),
            Column::key('position')->int(),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_subscription' => ['index' => 'subscription_id'],
            'ind_plan_price' => ['index' => 'plan_price_id'],
            'ind_parent' => ['index' => 'parent_item_id'],
            'ind_provider_reference' => ['index' => 'provider_reference'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('subscription_id')->number()->decimals(0),
            Field::key('plan_price_id')->number()->decimals(0),
            Field::key('parent_item_id')->number()->decimals(0),
            Field::key('quantity')->number()->decimals(0),
            Field::key('status')->text()->sanitize(false),
            Field::key('customization')->text()->sanitize(false),
            Field::key('unit_price')->number()->decimals(2),
            Field::key('customization_total')->number()->decimals(2),
            Field::key('subtotal')->number()->decimals(2),
            Field::key('tax_total')->number()->decimals(2),
            Field::key('total')->number()->decimals(2),
            Field::key('tax_id')->number()->decimals(0),
            Field::key('tax_rate')->number()->decimals(2),
            Field::key('starts_at')->date(),
            Field::key('commitment_ends_at')->date(),
            Field::key('ends_at')->date(),
            Field::key('provider_reference')->text()->sanitize(false),
            Field::key('provider_price')->text()->sanitize(false),
            Field::key('position')->number()->decimals(0),
        ];
    }
}
