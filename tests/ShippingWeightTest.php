<?php
/** php tests/ShippingWeightTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Shipping\ShippingWeight;

$riga = static fn (float $peso, float $l = 0, float $w = 0, float $h = 0, float $q = 1): array => [
    'weight' => $peso, 'length' => $l, 'width' => $w, 'height' => $h, 'quantity' => $q,
];

check('il peso volumetrico, se maggiore del reale, vince', fn () =>
    // 50 × 40 × 30 = 60000 cm³ ÷ 5000 = 12 kg contro 2 kg reali
    ShippingWeight::of([$riga(2.0, 50, 40, 30)], 5000.0) === 12.0
);

check('il peso reale, se maggiore del volumetrico, vince', fn () =>
    // 10 × 10 × 10 = 1000 ÷ 5000 = 0,2 kg contro 3 kg reali
    ShippingWeight::of([$riga(3.0, 10, 10, 10)], 5000.0) === 3.0
);

check('senza divisore (null) conta il peso reale', fn () =>
    ShippingWeight::of([$riga(2.0, 50, 40, 30)], null) === 2.0
);

check('con divisore zero o negativo conta il peso reale', fn () =>
    ShippingWeight::of([$riga(2.0, 50, 40, 30)], 0.0) === 2.0
    && ShippingWeight::of([$riga(2.0, 50, 40, 30)], -5.0) === 2.0
);

check('con le misure a zero (o una sola a zero) conta il peso reale', fn () =>
    ShippingWeight::of([$riga(2.0, 0, 0, 0)], 5000.0) === 2.0
    && ShippingWeight::of([$riga(2.0, 50, 40, 0)], 5000.0) === 2.0
);

check('la quantità moltiplica il peso della riga', fn () =>
    ShippingWeight::of([$riga(1.5, 0, 0, 0, 3)], 5000.0) === 4.5
    && ShippingWeight::of([$riga(2.0, 50, 40, 30, 3)], 5000.0) === 36.0
);

check('più righe si sommano, ognuna col suo maggiore', fn () =>
    // 12 kg volumetrici + 3 kg reali
    ShippingWeight::of([$riga(2.0, 50, 40, 30), $riga(3.0, 10, 10, 10)], 5000.0) === 15.0
);

check('la lista vuota pesa zero', fn () =>
    ShippingWeight::of([], 5000.0) === 0.0
);

check('il risultato è arrotondato a tre decimali', fn () =>
    // 0,3333 × 3 = 0,9999 → 1,000
    ShippingWeight::of([$riga(0.3333, 0, 0, 0, 3)], null) === 1.0
);

summary();
