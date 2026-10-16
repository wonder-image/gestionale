<?php
/** php tests/PlanFeatureMergeTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Subscriptions\PlanFeatureMerge;

$v = static fn (string $value, int $quantity = 1, string $at = '2026-01-01 00:00:00'): array => ['value' => $value, 'quantity' => $quantity, 'at' => $at];

check('i numeri si sommano per quantità: 5 × 1 più 3 × 2 fa 11', fn () =>
    PlanFeatureMerge::merge([$v('5'), $v('3', 2)]) === '11'
);

check('«illimitato» vince, in qualsiasi forma', fn () =>
    PlanFeatureMerge::merge([$v('5'), $v('illimitato')]) === 'illimitato'
    && PlanFeatureMerge::merge([$v('Illimitato'), $v('5')]) === 'Illimitato'
    && PlanFeatureMerge::merge([$v('3'), $v('UNLIMITED')]) === 'UNLIMITED'
);

check('i testi: vince quello della riga più recente', fn () =>
    PlanFeatureMerge::merge([$v('Pro', 1, '2026-05-01 00:00:00'), $v('Base', 1, '2026-01-01 00:00:00')]) === 'Pro'
    && PlanFeatureMerge::merge([$v('Base', 1, '2026-01-01 00:00:00'), $v('5', 1, '2026-05-01 00:00:00')]) === '5'
);

check('i decimali si sommano senza zeri inutili', fn () =>
    PlanFeatureMerge::merge([$v('1.5', 2)]) === '3'
    && PlanFeatureMerge::merge([$v('1.5'), $v('1')]) === '2.5'
);

check('senza valori non c\'è limite', fn () =>
    PlanFeatureMerge::merge([]) === null
);

summary();
