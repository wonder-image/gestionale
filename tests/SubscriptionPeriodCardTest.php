<?php
/** php tests/SubscriptionPeriodCardTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Resources\Subscriptions\SubscriptionResource;

$voci = static function (array $row): array {
    $metodo = new ReflectionMethod(SubscriptionResource::class, 'periodItems');

    return $metodo->invoke(null, $row);
};

check('le date del periodo: le prime due sempre, le altre solo se ci sono', function () use ($voci): bool {
    $attivo = $voci(['started_at' => '2026-10-01 10:00:00', 'next_renewal_at' => '', 'ended_at' => '']);
    $disdetto = $voci(['started_at' => '2026-10-01', 'next_renewal_at' => '2026-11-01', 'commitment_ends_at' => '2027-10-01', 'cancel_requested_at' => '2026-10-15']);

    return count($attivo) === 2 && count($disdetto) === 4;
});

check('due date per riga', function () use ($voci): bool {
    foreach ($voci(['started_at' => '2026-10-01', 'trial_ends_at' => '2026-10-15']) as $voce) {
        if (($voce->columnSpan['default'] ?? null) !== 6) {
            return false;
        }
    }

    return true;
});

summary();
