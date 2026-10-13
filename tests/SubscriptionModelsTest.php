<?php
/** php tests/SubscriptionModelsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Promotions\Coupon;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Plan;
use Wonder\Plugin\Gestionale\Models\Subscriptions\PlanFeature;
use Wonder\Plugin\Gestionale\Models\Subscriptions\PlanPrice;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Subscription;
use Wonder\Plugin\Gestionale\Models\Subscriptions\SubscriptionPlanChange;
use Wonder\Plugin\Gestionale\Models\Subscriptions\SubscriptionStatusLog;

$colonne = static function (string $model): array {
    $nomi = [];

    foreach ($model::tableSchema() as $column) {
        $nomi[] = (string) $column->name;
    }

    return $nomi;
};

$tutti = [Plan::class, PlanFeature::class, PlanPrice::class, Subscription::class, SubscriptionPlanChange::class, SubscriptionStatusLog::class];

check('le tabelle hanno il prefisso del gestionale', fn () =>
    Plan::$table === 'gst_plans'
    && PlanFeature::$table === 'gst_plan_features'
    && PlanPrice::$table === 'gst_plan_prices'
    && Subscription::$table === 'gst_subscriptions'
    && SubscriptionPlanChange::$table === 'gst_subscription_plan_changes'
    && SubscriptionStatusLog::$table === 'gst_subscription_status_logs'
);

check('piani e abbonamenti non viaggiano con il deploy', function () use ($tutti) {
    foreach ($tutti as $model) {
        if ($model::syncSchema() !== null) {
            return false;
        }
    }

    return true;
});

check('ogni tabella ha le sue colonne', function () use ($colonne) {
    $attese = [
        Plan::class => ['code', 'slug', 'name', 'description', 'tax_category_id', 'visible_online', 'active', 'position'],
        PlanFeature::class => ['plan_id', 'feature_key', 'feature_value', 'position'],
        PlanPrice::class => [
            'code', 'plan_id', 'name', 'billing_interval', 'term_months', 'commitment_months', 'trial_days',
            'price', 'sale_price', 'renewal_plan_price_id', 'active', 'position',
        ],
        Subscription::class => [
            'code', 'customer_id', 'plan_price_id', 'channel', 'status', 'provider', 'payment_method_id',
            'payment_term_id', 'coupon_id', 'coupon_code', 'billing_country', 'prices_include_tax', 'subtotal',
            'discount_total', 'tax_id', 'tax_country', 'tax_rate', 'tax_total', 'total', 'currency', 'started_at',
            'trial_ends_at', 'commitment_ends_at', 'current_period_start', 'current_period_end', 'next_renewal_at',
            'auto_renew', 'cancel_requested_at', 'cancelled_at', 'ended_at', 'provider_reference', 'environment', 'note',
        ],
        SubscriptionPlanChange::class => ['subscription_id', 'old_plan_price_id', 'new_plan_price_id', 'user_id', 'source', 'changed_at'],
        SubscriptionStatusLog::class => ['subscription_id', 'field', 'from_value', 'to_value', 'source', 'user_id', 'message'],
    ];

    foreach ($attese as $model => $nomi) {
        $presenti = $colonne($model);

        if (array_diff($nomi, $presenti) !== []) {
            echo '    mancano in '.$model.': '.implode(', ', array_diff($nomi, $presenti))."\n";

            return false;
        }
    }

    return true;
});

check('i limiti non usano parole riservate di MySQL come nome di colonna', fn () =>
    array_intersect(['key', 'value'], $colonne(PlanFeature::class)) === []
);

check('gli stati sono tutti dichiarati, e quelli vivi sono un sottoinsieme', fn () =>
    Subscription::STATUSES === ['pending', 'trialing', 'active', 'past_due', 'unpaid', 'cancelled']
    && Subscription::LIVE === ['trialing', 'active', 'past_due']
    && array_diff(Subscription::LIVE, Subscription::STATUSES) === []
    && Subscription::CHANNELS === ['online', 'office']
    && Subscription::PROVIDERS === ['stripe', 'manual']
);

check('ogni colonna ha il suo campo, e viceversa', function () use ($tutti, $colonne) {
    foreach ($tutti as $model) {
        $campi = array_map(static fn ($field): string => (string) $field->key, $model::dataSchema());
        $tabella = $colonne($model);

        if (array_diff($campi, $tabella) !== [] || array_diff($tabella, $campi) !== []) {
            echo '    '.$model.': '.implode(', ', array_merge(array_diff($campi, $tabella), array_diff($tabella, $campi)))."\n";

            return false;
        }
    }

    return true;
});

check('il pagamento sa già a quale abbonamento appartiene', function () use ($colonne) {
    return in_array('subscription_id', $colonne(Payment::class), true);
});

check('la durata del coupon ha tre valori, il primo è il predefinito', fn () =>
    Coupon::DURATIONS === ['once', 'repeating', 'forever']
);

summary();
