<?php
/** php tests/CampaignPriceTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Promotions\CampaignPrice;

$campagna = static fn (int $id, string $tipo, float $valore, bool $escludi = false): array => [
    'id' => $id,
    'discount_type' => $tipo,
    'discount_value' => $valore,
    'exclude_sale_products' => $escludi,
];

check('il venti per cento su 50 dà 40,00', function () use ($campagna) {
    $r = CampaignPrice::best([$campagna(1, 'percent', 20)], 50.0, 0.0);

    return $r !== null && $r['campaign_id'] === 1 && $r['price'] === 40.0 && $r['percent'] === 20.0;
});

check('dieci euro su 50 dà 40,00 e la percentuale è quella vera', function () use ($campagna) {
    $r = CampaignPrice::best([$campagna(1, 'amount', 10)], 50.0, 0.0);

    return $r !== null && $r['price'] === 40.0 && $r['percent'] === 20.0;
});

check('uno sconto in euro più grande del prezzo si ferma a zero, mai sotto', function () use ($campagna) {
    $r = CampaignPrice::best([$campagna(1, 'amount', 80)], 50.0, 0.0);

    return $r !== null && $r['price'] === 0.0 && $r['percent'] === 100.0;
});

check('oltre il cento per cento si ferma al cento', function () use ($campagna) {
    $r = CampaignPrice::best([$campagna(1, 'percent', 150)], 50.0, 0.0);

    return $r !== null && $r['price'] === 0.0 && $r['percent'] === 100.0;
});

check('la percentuale si arrotonda a due decimali', function () use ($campagna) {
    $r = CampaignPrice::best([$campagna(1, 'amount', 1)], 3.0, 0.0);

    return $r !== null && $r['price'] === 2.0 && $r['percent'] === 33.33;
});

check('tra due campagne vince il prezzo più basso', function () use ($campagna) {
    $r = CampaignPrice::best([$campagna(1, 'percent', 10), $campagna(2, 'percent', 30), $campagna(3, 'amount', 5)], 50.0, 0.0);

    return $r !== null && $r['campaign_id'] === 2 && $r['price'] === 35.0;
});

check('a parità vince la campagna più vecchia, qualunque sia l\'ordine della lista', function () use ($campagna) {
    $a = CampaignPrice::best([$campagna(7, 'percent', 20), $campagna(3, 'amount', 10)], 50.0, 0.0);
    $b = CampaignPrice::best([$campagna(3, 'amount', 10), $campagna(7, 'percent', 20)], 50.0, 0.0);

    return $a !== null && $b !== null && $a['campaign_id'] === 3 && $b['campaign_id'] === 3;
});

check('«esclude i prodotti scontati» salta la campagna se il prezzo scontato è valido', function () use ($campagna) {
    return CampaignPrice::best([$campagna(1, 'percent', 20, true)], 50.0, 40.0) === null;
});

check('un prezzo scontato non più basso del base non conta come sconto', function () use ($campagna) {
    return CampaignPrice::best([$campagna(1, 'percent', 20, true)], 50.0, 50.0) !== null
        && CampaignPrice::best([$campagna(1, 'percent', 20, true)], 50.0, 60.0) !== null
        && CampaignPrice::best([$campagna(1, 'percent', 20, true)], 50.0, 0.0) !== null;
});

check('«esclude i prodotti scontati» salta solo quella campagna, le altre restano', function () use ($campagna) {
    $r = CampaignPrice::best([$campagna(1, 'percent', 50, true), $campagna(2, 'percent', 10)], 50.0, 40.0);

    return $r !== null && $r['campaign_id'] === 2;
});

check('con il prezzo base a zero non c\'è nessuna campagna', fn () =>
    CampaignPrice::best([$campagna(1, 'percent', 20)], 0.0, 0.0) === null
);

check('una lista vuota non dà nessuna campagna', fn () =>
    CampaignPrice::best([], 50.0, 0.0) === null
);

check('uno sconto a zero o negativo non è uno sconto', fn () =>
    CampaignPrice::best([$campagna(1, 'percent', 0), $campagna(2, 'amount', -5)], 50.0, 0.0) === null
);

summary();
