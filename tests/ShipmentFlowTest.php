<?php
/** php tests/ShipmentFlowTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Shipping\ShipmentFlow;

$riga = static fn (float $ordinata, float $spedita): array => ['ordered' => $ordinata, 'shipped' => $spedita];

check('dalla consegna in attesa si può andare avanti, anche saltando passi', fn () =>
    ShipmentFlow::allowed('delivery', 'pending') === ['label_created', 'in_transit', 'out_for_delivery', 'delivered', 'cancelled']
);

check('dall\'etichetta pronta non si torna in attesa ma si annulla ancora', fn () =>
    ShipmentFlow::allowed('delivery', 'label_created') === ['in_transit', 'out_for_delivery', 'delivered', 'cancelled']
);

check('una spedizione in viaggio non si annulla: si segna resa, fallita o in eccezione', fn () =>
    ShipmentFlow::allowed('delivery', 'in_transit') === ['out_for_delivery', 'delivered', 'failed_attempt', 'exception', 'returned']
    && !in_array('cancelled', ShipmentFlow::allowed('delivery', 'out_for_delivery'), true)
);

check('«resa» si raggiunge solo da in viaggio o in consegna', function () {
    foreach (['pending', 'label_created', 'delivered', 'failed_attempt', 'exception', 'returned', 'cancelled'] as $da) {
        if ($da !== 'failed_attempt' && $da !== 'exception' && in_array('returned', ShipmentFlow::allowed('delivery', $da), true)) {
            return false;
        }
    }

    return in_array('returned', ShipmentFlow::allowed('delivery', 'in_transit'), true)
        && in_array('returned', ShipmentFlow::allowed('delivery', 'out_for_delivery'), true);
});

check('consegnata, resa e annullata sono la fine: da lì non si va da nessuna parte', fn () =>
    ShipmentFlow::allowed('delivery', 'delivered') === []
    && ShipmentFlow::allowed('delivery', 'returned') === []
    && ShipmentFlow::allowed('delivery', 'cancelled') === []
);

check('indietro non si torna', function () {
    $ordine = ['pending', 'label_created', 'in_transit', 'out_for_delivery', 'delivered'];

    foreach ($ordine as $i => $da) {
        foreach (array_slice($ordine, 0, $i + 1) as $a) {
            if (in_array($a, ShipmentFlow::allowed('delivery', $da), true)) {
                return false;
            }
        }
    }

    return true;
});

check('dopo un tentativo fallito o un\'eccezione la consegna riprende o torna al mittente', fn () =>
    ShipmentFlow::allowed('delivery', 'failed_attempt') === ['out_for_delivery', 'delivered', 'exception', 'returned']
    && ShipmentFlow::allowed('delivery', 'exception') === ['out_for_delivery', 'delivered', 'returned']
);

check('il ritiro va da in attesa a pronto a ritirato, e si annulla finché non è ritirato', fn () =>
    ShipmentFlow::allowed('pickup', 'pending') === ['ready_for_pickup', 'cancelled']
    && ShipmentFlow::allowed('pickup', 'ready_for_pickup') === ['picked_up', 'cancelled']
    && ShipmentFlow::allowed('pickup', 'picked_up') === []
    && ShipmentFlow::allowed('pickup', 'cancelled') === []
);

check('uno stato o un tipo che non esistono non portano da nessuna parte', fn () =>
    ShipmentFlow::allowed('delivery', 'inventato') === [] && ShipmentFlow::allowed('altro', 'pending') === []
);

check('nessuna riga in viaggio: ordine da evadere', fn () =>
    ShipmentFlow::fulfillment('unfulfilled', [$riga(3, 0)]) === 'unfulfilled'
);

check('due pezzi su tre in viaggio: evaso in parte', fn () =>
    ShipmentFlow::fulfillment('unfulfilled', [$riga(3, 2)]) === 'partially_fulfilled'
);

check('tre pezzi su tre: evaso', fn () =>
    ShipmentFlow::fulfillment('unfulfilled', [$riga(3, 3)]) === 'fulfilled'
);

check('con più righe basta che una manchi per essere evaso in parte', fn () =>
    ShipmentFlow::fulfillment('unfulfilled', [$riga(1, 1), $riga(2, 0)]) === 'partially_fulfilled'
    && ShipmentFlow::fulfillment('unfulfilled', [$riga(1, 1), $riga(2, 2)]) === 'fulfilled'
);

check('i millesimi non fanno il parziale: 2,5 su 2,5 con l\'arrotondamento è evaso', fn () =>
    ShipmentFlow::fulfillment('unfulfilled', [$riga(2.5, 2.4996)]) === 'fulfilled'
);

check('un ritiro pronto da ritirare: pronto per il ritiro', fn () =>
    ShipmentFlow::fulfillment('unfulfilled', [$riga(3, 0)], true) === 'ready_for_pickup'
);

check('un ritiro ritirato conta come spedito: evaso', fn () =>
    ShipmentFlow::fulfillment('ready_for_pickup', [$riga(3, 3)], false) === 'fulfilled'
);

check('senza righe da spedire lo stato resta com\'è', fn () =>
    ShipmentFlow::fulfillment('fulfilled', []) === 'fulfilled'
    && ShipmentFlow::fulfillment('unfulfilled', []) === 'unfulfilled'
);

check('un ordine tornato indietro dopo un reso ridiventa da evadere', fn () =>
    ShipmentFlow::fulfillment('fulfilled', [$riga(3, 0)]) === 'unfulfilled'
    && ShipmentFlow::fulfillment('fulfilled', [$riga(3, 1)]) === 'partially_fulfilled'
);

summary();
