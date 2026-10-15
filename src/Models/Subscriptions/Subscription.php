<?php

namespace Wonder\Plugin\Gestionale\Models\Subscriptions;

use Wonder\App\Model;
use Wonder\App\Schema\Extensions\AddressExtension;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Columns;
use Wonder\Sql\TableSchema as Column;

/**
 * Un abbonamento (G10 §1.1): un cliente, il periodo in corso e il prossimo
 * rinnovo; i servizi, cioè i prezzi dei piani, sono le sue righe
 * (`SubscriptionItem`, G10b). È separato dagli ordini (D17): un rinnovo non
 * crea un ordine, crea un pagamento (`gst_payments.subscription_id`).
 *
 * Gli stati sono quelli di §4.13 dell'architettura, dichiarati tutti qui anche
 * se in G10.1 nessuno li scrive ancora: lo fanno il piano 2 (online, dalle
 * notifiche di Stripe) e il piano 3 (da ufficio). `LIVE` sono gli stati in cui
 * il cliente ha l'abbonamento: valgono i limiti, il piano non si elimina e
 * `subscriptions` non si spegne.
 *
 * I dati di fatturazione e gli importi sono una copia, come sull'ordine: la
 * verità degli importi la ricalcola `Support\Subscriptions\SubscriptionAmounts`.
 */
final class Subscription extends Model
{
    public static string $table = 'gst_subscriptions';
    public static string $folder = 'gestionale/subscriptions';
    public static string $icon = 'bi bi-arrow-repeat';

    public const STATUSES = ['pending', 'trialing', 'active', 'past_due', 'unpaid', 'cancelled'];
    public const LIVE = ['trialing', 'active', 'past_due'];
    public const CHANNELS = ['online', 'office'];
    public const PROVIDERS = ['stripe', 'manual'];
    /** Come i pagamenti: un abbonamento di prova non è un abbonamento vero. */
    public const ENVIRONMENTS = ['live', 'test'];

    /** La fatturazione: gli stessi campi dell'ordine. */
    public static function billingAddress(): AddressExtension
    {
        return AddressExtension::billing('billing_', countryDefault: 'IT')->withLink(false);
    }

    /** Gli abbonamenti sono la storia di questo ambiente, non configurazione. */
    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            ...static::sqlColumnsFromDataSchema([
                'code', 'subtotal', 'discount_total', 'tax_total', 'total',
            ]),
            Column::key('customer_id')->int()->default(0),
            Column::key('channel')->enum(static::CHANNELS)->default('online'),
            Column::key('status')->enum(static::STATUSES)->default('pending'),
            Column::key('provider')->enum(static::PROVIDERS)->default('stripe'),
            Column::key('payment_method_id')->int()->default(0),
            Column::key('payment_term_id')->int()->default(0),
            Column::key('coupon_id')->int()->default(0),
            Column::key('coupon_code')->length(50),
            ...static::billingAddress()->tableSchema(),
            Column::key('prices_include_tax')->enum(['true', 'false'])->default('true'),
            Column::key('tax_id')->int()->default(0),
            Column::key('tax_country')->length(2)->default('IT'),
            Columns::decimal('tax_rate', '5,2'),
            Column::key('currency')->length(3)->default('EUR'),
            Column::key('started_at')->datetime(),
            Column::key('trial_ends_at')->datetime(),
            Column::key('commitment_ends_at')->datetime(),
            Column::key('current_period_start')->datetime(),
            Column::key('current_period_end')->datetime(),
            Column::key('next_renewal_at')->datetime(),
            Column::key('auto_renew')->enum(['true', 'false'])->default('true'),
            Column::key('cancel_requested_at')->datetime(),
            Column::key('cancelled_at')->datetime(),
            Column::key('ended_at')->datetime(),
            Column::key('provider_reference')->length(191),
            Column::key('environment')->enum(static::ENVIRONMENTS)->default('live'),
            Column::key('note')->type('TEXT'),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_customer' => ['index' => 'customer_id'],
            'ind_status' => ['index' => 'status'],
            'ind_provider_reference' => ['index' => 'provider_reference'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->uniqueCode(Codes::SUBSCRIPTION),
            Field::key('customer_id')->number()->decimals(0),
            Field::key('channel')->text()->sanitize(false),
            Field::key('status')->text()->sanitize(false),
            Field::key('provider')->text()->sanitize(false),
            Field::key('payment_method_id')->number()->decimals(0),
            Field::key('payment_term_id')->number()->decimals(0),
            Field::key('coupon_id')->number()->decimals(0),
            Field::key('coupon_code')->text()->sanitize(false),
            ...static::billingAddress()->dataSchema(),
            Field::key('prices_include_tax')->text()->sanitize(false),
            Field::key('subtotal')->number()->decimals(2),
            Field::key('discount_total')->number()->decimals(2),
            Field::key('tax_id')->number()->decimals(0),
            Field::key('tax_country')->text()->sanitize(false),
            Field::key('tax_rate')->number()->decimals(2),
            Field::key('tax_total')->number()->decimals(2),
            Field::key('total')->number()->decimals(2),
            Field::key('currency')->text()->sanitize(false),
            Field::key('started_at')->date(),
            Field::key('trial_ends_at')->date(),
            Field::key('commitment_ends_at')->date(),
            Field::key('current_period_start')->date(),
            Field::key('current_period_end')->date(),
            Field::key('next_renewal_at')->date(),
            Field::key('auto_renew')->text()->sanitize(false),
            Field::key('cancel_requested_at')->date(),
            Field::key('cancelled_at')->date(),
            Field::key('ended_at')->date(),
            Field::key('provider_reference')->text()->sanitize(false),
            Field::key('environment')->text()->sanitize(false),
            Field::key('note')->text(),
        ];
    }
}
