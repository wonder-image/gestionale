<?php
/** php tests/ReturnRulesTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Sales\SalesReturnItem;
use Wonder\Plugin\Gestionale\Support\Catalog\Customizations;
use Wonder\Plugin\Gestionale\Support\Returns\ReturnRules;

check('la merce rotta o difettosa non torna in vendita, il resto sì', fn () =>
    ReturnRules::defaultRestock('damaged') === false
    && ReturnRules::defaultRestock('defective') === false
    && ReturnRules::defaultRestock('changed_mind') === true
    && ReturnRules::defaultRestock('wrong_size') === true
    && ReturnRules::defaultRestock('other') === true
    && ReturnRules::defaultRestock('inventato') === true
);

check('si può rendere quanto ordinato meno quanto già reso, mai meno di zero', fn () =>
    ReturnRules::returnable(3, 1) === 2.0
    && ReturnRules::returnable(3, 3) === 0.0
    && ReturnRules::returnable(3, 5) === 0.0
    && ReturnRules::returnable(2.5, 0.5) === 2.0
);

check('la quantità si legge come la scrive una persona', fn () =>
    ReturnRules::quantityFrom('2') === 2.0
    && ReturnRules::quantityFrom('2,5') === 2.5
    && ReturnRules::quantityFrom('2.5') === 2.5
    && ReturnRules::quantityFrom(' 3 ') === 3.0
    && ReturnRules::quantityFrom(4) === 4.0
);

check('zero, negativo, testo e vuoto non sono quantità', fn () =>
    ReturnRules::quantityFrom('0') === null
    && ReturnRules::quantityFrom('-1') === null
    && ReturnRules::quantityFrom('abc') === null
    && ReturnRules::quantityFrom('') === null
    && ReturnRules::quantityFrom(null) === null
    && ReturnRules::quantityFrom('1,2,3') === null
);

check('un reso si registra solo su un ordine vero, vivo e con la funzionalità accesa', function () {
    $on = ['returns' => true];
    $ordine = static fn (string $s, string $stage = 'order'): array => ['stage' => $stage, 'status' => $s];

    return ReturnRules::eligibleOrder($ordine('confirmed'), $on)
        && ReturnRules::eligibleOrder($ordine('processing'), $on)
        && ReturnRules::eligibleOrder($ordine('completed'), $on)
        && !ReturnRules::eligibleOrder($ordine('cancelled'), $on)
        && !ReturnRules::eligibleOrder($ordine('pending'), $on)
        && !ReturnRules::eligibleOrder($ordine('draft'), $on)
        && !ReturnRules::eligibleOrder($ordine('confirmed', 'cart'), $on)
        && !ReturnRules::eligibleOrder($ordine('confirmed'), ['returns' => false])
        && !ReturnRules::eligibleOrder($ordine('confirmed'), []);
});

check('ogni motivo ha la sua etichetta', function () {
    foreach (SalesReturnItem::REASONS as $motivo) {
        if (trim((string) (ReturnRules::REASON_LABELS[$motivo] ?? '')) === '') {
            return false;
        }
    }

    return count(ReturnRules::REASON_LABELS) === count(SalesReturnItem::REASONS);
});

check('il reso online esclude una riga personalizzata: la merce su misura non si rimanda', function () {
    $campi = [['customization_id' => 1, 'label' => 'Incisione', 'value' => 'Marco', 'option_id' => 0, 'surcharge' => '5.00']];

    return ReturnRules::onlineReturnable([]) === true
        && ReturnRules::onlineReturnable(['customization' => '']) === true
        && ReturnRules::onlineReturnable(['customization' => []]) === true
        && ReturnRules::onlineReturnable(['customization' => Customizations::encode($campi)]) === false
        && ReturnRules::onlineReturnable(['customization' => $campi]) === false;
});

summary();
