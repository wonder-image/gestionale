<?php
/** php tests/OrderActionsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Orders\OrderActions;

$ordine = static fn (string $status, string $evasione = 'unfulfilled'): array => ['status' => $status, 'fulfillment_status' => $evasione];

check('un ordine in attesa si conferma e si annulla', fn () =>
    OrderActions::available($ordine('pending'), []) === ['confirm', 'cancel']
);

check('una bozza si conferma e si annulla', fn () =>
    OrderActions::available($ordine('draft'), []) === ['confirm', 'cancel']
);

check('un ordine confermato si evade e si annulla', fn () =>
    OrderActions::available($ordine('confirmed'), []) === ['fulfill', 'cancel']
    && OrderActions::available($ordine('processing'), []) === ['fulfill', 'cancel']
);

check('un ordine già evaso non si evade due volte', fn () =>
    OrderActions::available($ordine('confirmed', 'fulfilled'), []) === ['cancel']
);

check('un ordine chiuso o annullato non ha azioni', fn () =>
    OrderActions::available($ordine('completed', 'fulfilled'), []) === []
    && OrderActions::available($ordine('cancelled'), []) === []
);

check('i pezzi si contano dalle righe prodotto, al singolare e al plurale', function () use ($ordine) {
    $uno = [['type' => 'product', 'quantity' => '1.000']];
    $tre = [['type' => 'product', 'quantity' => '2.000'], ['type' => 'product', 'quantity' => '1.000'], ['type' => 'shipping', 'quantity' => '1.000']];

    return OrderActions::summary('confirm', $ordine('pending'), $uno) === 'Conferma l\'ordine e scarica 1 pezzo.'
        && OrderActions::summary('confirm', $ordine('pending'), $tre) === 'Conferma l\'ordine e scarica 3 pezzi.'
        && OrderActions::summary('confirm', $ordine('pending'), []) === 'Conferma l\'ordine e scarica 0 pezzi.';
});

check('annullare dice cosa succede alla merce, in attesa o confermato', function () use ($ordine) {
    $tre = [['type' => 'product', 'quantity' => '3.000']];
    $uno = [['type' => 'product', 'quantity' => '1.000']];

    return OrderActions::summary('cancel', $ordine('pending'), $tre) === 'Annulla l\'ordine e libera 3 pezzi prenotati.'
        && OrderActions::summary('cancel', $ordine('pending'), $uno) === 'Annulla l\'ordine e libera 1 pezzo prenotato.'
        && OrderActions::summary('cancel', $ordine('confirmed'), $tre) === 'Annulla l\'ordine e rimette 3 pezzi in magazzino.';
});

check('la frase del denaro incassato compare solo con un incassato', function () use ($ordine) {
    $tre = [['type' => 'product', 'quantity' => '3.000']];
    $senza = OrderActions::summary('cancel', $ordine('confirmed') + ['paid_total' => '0.00'], $tre);
    $con = OrderActions::summary('cancel', $ordine('confirmed') + ['paid_total' => '40.00'], $tre);

    return !str_contains($senza, 'incassato')
        && str_contains($con, 'Il denaro già incassato (40,00 €) non si rimborsa da qui.');
});

check('evadere non tocca il magazzino e lo dice', fn () =>
    OrderActions::summary('fulfill', $ordine('confirmed'), []) === 'Segna l\'ordine come evaso: il magazzino non cambia, la merce è già uscita alla conferma.'
);

check('un\'azione sconosciuta non cade: frase vuota', fn () =>
    OrderActions::summary('boh', $ordine('pending'), []) === ''
);

check('ogni azione ha il suo nome e il suo colore', function () {
    foreach (['confirm', 'cancel', 'fulfill'] as $azione) {
        if (OrderActions::label($azione) === '' || OrderActions::buttonClass($azione) === '') {
            return false;
        }
    }

    return OrderActions::label('confirm') === 'Conferma';
});

check('Registra pagamento vale su un ordine vivo non ancora saldato', function () use ($ordine) {
    foreach (['pending', 'confirmed', 'processing'] as $stato) {
        foreach (['unpaid', 'pending', 'partially_paid'] as $pagamento) {
            if (!OrderActions::canRegisterPayment($ordine($stato) + ['payment_status' => $pagamento])) {
                return false;
            }
        }
    }

    // Senza `payment_status` l\'ordine conta come non pagato.
    return OrderActions::canRegisterPayment($ordine('pending'));
});

check('Registra pagamento non c\'è su un ordine saldato, rimborsato, chiuso, annullato o in bozza', function () use ($ordine) {
    return !OrderActions::canRegisterPayment($ordine('confirmed') + ['payment_status' => 'paid'])
        && !OrderActions::canRegisterPayment($ordine('confirmed') + ['payment_status' => 'refunded'])
        && !OrderActions::canRegisterPayment($ordine('confirmed') + ['payment_status' => 'partially_refunded'])
        && !OrderActions::canRegisterPayment($ordine('cancelled') + ['payment_status' => 'unpaid'])
        && !OrderActions::canRegisterPayment($ordine('completed') + ['payment_status' => 'partially_paid'])
        && !OrderActions::canRegisterPayment($ordine('draft') + ['payment_status' => 'unpaid']);
});

summary();
