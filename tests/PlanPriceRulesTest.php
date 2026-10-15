<?php
/** php tests/PlanPriceRulesTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Subscriptions\PlanPriceRules;

$rifiuto = static function (array $prezzo, array $rinnovi = []): ?string {
    try {
        PlanPriceRules::assert($prezzo, $rinnovi);
    } catch (UserError $e) {
        return $e->key();
    }

    return null;
};

$mensile = ['id' => 4, 'name' => 'Mensile', 'price' => '19.00', 'sale_price' => '', 'billing_interval' => 'month', 'term_months' => 1, 'commitment_months' => 0, 'trial_days' => 14];

check('un prezzo mensile in regola passa, anche a zero', fn () =>
    $rifiuto($mensile) === null
    && $rifiuto(['price' => '0.00'] + $mensile) === null
);

check('senza nome, o con un prezzo negativo o non numerico, no', fn () =>
    $rifiuto(['name' => ' '] + $mensile) === 'plan.price_name_required'
    && $rifiuto(['price' => '-1'] + $mensile) === 'plan.price_invalid'
    && $rifiuto(['price' => ''] + $mensile) === 'plan.price_invalid'
    && $rifiuto(['price' => 'abc'] + $mensile) === 'plan.price_invalid'
);

check('il prezzo scontato è più basso del prezzo; vuoto o zero vuol dire nessuno', fn () =>
    $rifiuto(['sale_price' => '15.00'] + $mensile) === null
    && $rifiuto(['sale_price' => '0'] + $mensile) === null
    && $rifiuto(['sale_price' => '19.00'] + $mensile) === 'plan.sale_not_lower'
    && $rifiuto(['sale_price' => '25.00'] + $mensile) === 'plan.sale_not_lower'
);

check('i mesi per addebito vanno con l\'intervallo', fn () =>
    $rifiuto(['term_months' => 3] + $mensile) === null
    && $rifiuto(['term_months' => 0] + $mensile) === 'plan.term_mismatch'
    && $rifiuto(['term_months' => 13] + $mensile) === 'plan.term_mismatch'
    && $rifiuto(['billing_interval' => 'year', 'term_months' => 12] + $mensile) === null
    && $rifiuto(['billing_interval' => 'year', 'term_months' => 24] + $mensile) === null
    && $rifiuto(['billing_interval' => 'year', 'term_months' => 6] + $mensile) === 'plan.term_mismatch'
    && $rifiuto(['billing_interval' => 'week', 'term_months' => 1] + $mensile) === 'plan.term_mismatch'
);

check('vincolo e prova non sono negativi', fn () =>
    $rifiuto(['commitment_months' => 12] + $mensile) === null
    && $rifiuto(['commitment_months' => -1] + $mensile) === 'plan.negative_period'
    && $rifiuto(['trial_days' => -3] + $mensile) === 'plan.negative_period'
);

check('il rinnovo è un altro prezzo, tra quelli ammessi', fn () =>
    $rifiuto(['renewal_plan_price_id' => 7] + $mensile, [7, 8]) === null
    && $rifiuto(['renewal_plan_price_id' => 9] + $mensile, [7, 8]) === 'plan.renewal_invalid'
    && $rifiuto(['renewal_plan_price_id' => 4] + $mensile, [4, 7]) === 'plan.renewal_invalid'
    && $rifiuto(['renewal_plan_price_id' => 0] + $mensile) === null
);

check('servizi insieme: ogni durata è multipla della più corta', function () {
    $errore = static function (array $mesi): ?string {
        try {
            PlanPriceRules::assertIntervals($mesi);
        } catch (\Wonder\Plugin\Gestionale\Support\Errors\UserError $e) {
            return $e->key();
        }

        return null;
    };

    return $errore([1, 3, 12]) === null
        && $errore([2, 3]) === 'subscription.interval_mismatch'
        && $errore([12, 1]) === null
        && $errore([12, 24]) === null
        && $errore([12, 18]) === 'subscription.interval_mismatch'
        && $errore([]) === null
        && $errore([6]) === null;
});

check('i mesi proposti: 1 per il mensile, 12 per l\'annuale', fn () =>
    PlanPriceRules::defaultTerm('month') === 1
    && PlanPriceRules::defaultTerm('year') === 12
);

summary();
