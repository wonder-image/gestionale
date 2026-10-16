<?php
/** php tests/SubscriptionProrationTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Subscriptions\SubscriptionProration as P;

$d = static fn (string $day): DateTimeImmutable => new DateTimeImmutable($day);
$giorni = static fn (array $periodo): string => $periodo[0]->format('Y-m-d').' '.$periodo[1]->format('Y-m-d');

check('il mensile si addebita ogni mese, l\'annuale ogni dodici', fn () =>
    P::charges($d('2026-01-15'), 1, $d('2026-03-15'))
    && !P::charges($d('2026-01-15'), 12, $d('2026-03-15'))
    && P::charges($d('2026-01-15'), 12, $d('2027-01-15'))
    && P::charges($d('2026-01-15'), 12, $d('2026-01-15'))
);

check('il numero dell\'addebito conta i periodi della riga', fn () =>
    P::chargeIndex($d('2026-01-15'), 12, $d('2028-01-15')) === 2
    && P::chargeIndex($d('2026-01-15'), 1, $d('2026-04-15')) === 3
    && P::chargeIndex($d('2026-01-15'), 12, $d('2026-01-15')) === 0
);

check('il periodo della riga che contiene una data', fn () =>
    $giorni(P::rowPeriod($d('2026-01-15'), 12, $d('2026-06-01'))) === '2026-01-15 2027-01-15'
    && $giorni(P::rowPeriod($d('2026-01-15'), 12, $d('2027-01-15'))) === '2027-01-15 2028-01-15'
);

check('il 31 del mese: si conta sempre dall\'ancora, all\'ultimo giorno del mese corto', fn () =>
    $giorni(P::rowPeriod($d('2026-01-31'), 1, $d('2026-02-10'))) === '2026-01-31 2026-02-28'
    && $giorni(P::rowPeriod($d('2026-01-31'), 1, $d('2026-03-05'))) === '2026-02-28 2026-03-31'
    && $giorni(P::rowPeriod($d('2026-01-31'), 1, $d('2026-04-30'))) === '2026-04-30 2026-05-31'
    && P::addMonths($d('2024-01-31'), 1)->format('Y-m-d') === '2024-02-29'
    && P::addMonths($d('2026-03-31'), -1)->format('Y-m-d') === '2026-02-28'
    && P::addMonths($d('2026-01-15'), -1)->format('Y-m-d') === '2025-12-15'
    && P::addMonths($d('2026-11-30'), 14)->format('Y-m-d') === '2028-01-30'
    && P::charges($d('2026-01-31'), 1, $d('2026-02-28'))
);

check('i mesi fra due date: solo i mesi interi', fn () =>
    P::monthsBetween($d('2026-01-15'), $d('2026-03-15')) === 2
    && P::monthsBetween($d('2026-01-15'), $d('2026-03-14')) === 1
    && P::monthsBetween($d('2026-01-31'), $d('2026-02-28')) === 1
    && P::monthsBetween($d('2026-03-15'), $d('2026-01-15')) === -2
);

check('la quota del periodo che resta', fn () =>
    P::quota(120.00, $d('2026-07-15'), $d('2026-01-15'), $d('2027-01-15')) === 60.49
    && P::quota(10.0, $d('2026-01-15'), $d('2026-01-15'), $d('2027-01-15')) === 10.0
    && P::quota(10.0, $d('2025-12-01'), $d('2026-01-15'), $d('2027-01-15')) === 10.0
    && P::quota(10.0, $d('2027-01-15'), $d('2026-01-15'), $d('2027-01-15')) === 0.0
);

summary();
