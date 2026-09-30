<?php
/** php tests/PaymentStatusTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Payments\PaymentStatus;

check('un ordine senza nessuna riga di denaro non è pagato', fn () =>
    PaymentStatus::of(100, []) === 'unpaid'
);

check('una riga in attesa mette l\'ordine in attesa', fn () =>
    PaymentStatus::of(100, [
        ['type' => 'payment', 'status' => 'pending', 'amount' => 100],
    ]) === 'pending'
);

check('una riga fallita non conta: l\'ordine torna non pagato', fn () =>
    PaymentStatus::of(100, [
        ['type' => 'payment', 'status' => 'failed', 'amount' => 100],
    ]) === 'unpaid'
);

check('l\'incasso pieno paga l\'ordine', fn () =>
    PaymentStatus::of(100, [
        ['type' => 'payment', 'status' => 'paid', 'amount' => 100],
    ]) === 'paid'
);

check('un acconto lascia l\'ordine pagato a metà', fn () =>
    PaymentStatus::of(100, [
        ['type' => 'payment', 'status' => 'paid', 'amount' => 40],
    ]) === 'partially_paid'
);

check('due acconti che coprono il totale pagano l\'ordine', fn () =>
    PaymentStatus::of(100, [
        ['type' => 'payment', 'status' => 'paid', 'amount' => 40],
        ['type' => 'payment', 'status' => 'paid', 'amount' => 60],
    ]) === 'paid'
);

check('chi paga più del dovuto ha comunque pagato', fn () =>
    // Succede con le spese di spedizione tolte dopo l'incasso.
    PaymentStatus::of(100, [
        ['type' => 'payment', 'status' => 'paid', 'amount' => 120],
    ]) === 'paid'
);

check('un ordine che non costa niente nasce pagato', fn () =>
    // Tutto a sconto, o un omaggio: nessuno gli manderà mai un euro.
    PaymentStatus::of(0, []) === 'paid'
);

check('il rimborso di tutto rende l\'ordine rimborsato', fn () =>
    PaymentStatus::of(100, [
        ['type' => 'payment', 'status' => 'paid', 'amount' => 100],
        ['type' => 'refund', 'status' => 'paid', 'amount' => 100],
    ]) === 'refunded'
);

check('il rimborso di una parte lascia l\'ordine rimborsato a metà', fn () =>
    PaymentStatus::of(100, [
        ['type' => 'payment', 'status' => 'paid', 'amount' => 100],
        ['type' => 'refund', 'status' => 'paid', 'amount' => 30],
    ]) === 'partially_refunded'
);

check('un rimborso ancora in attesa non conta come rimborso', fn () =>
    PaymentStatus::of(100, [
        ['type' => 'payment', 'status' => 'paid', 'amount' => 100],
        ['type' => 'refund', 'status' => 'pending', 'amount' => 30],
    ]) === 'paid'
);

check('il rimborso di un acconto rimborsa tutto quello che c\'era', fn () =>
    PaymentStatus::of(100, [
        ['type' => 'payment', 'status' => 'paid', 'amount' => 40],
        ['type' => 'refund', 'status' => 'paid', 'amount' => 40],
    ]) === 'refunded'
);

check('gli importi scritti col segno meno valgono comunque', fn () =>
    // Un gateway che manda il rimborso come importo negativo non deve
    // ribaltare il conto.
    PaymentStatus::of(100, [
        ['type' => 'payment', 'status' => 'paid', 'amount' => 100],
        ['type' => 'refund', 'status' => 'paid', 'amount' => -100],
    ]) === 'refunded'
);

check('i centesimi non fanno perdere il pagamento pieno', fn () =>
    PaymentStatus::of(19.999, [
        ['type' => 'payment', 'status' => 'paid', 'amount' => 20],
    ]) === 'paid'
);

check('le somme si leggono anche da sole', function () {
    $somme = PaymentStatus::sums([
        ['type' => 'payment', 'status' => 'paid', 'amount' => 40],
        ['type' => 'payment', 'status' => 'pending', 'amount' => 60],
        ['type' => 'refund', 'status' => 'paid', 'amount' => 10],
        ['type' => 'payment', 'status' => 'failed', 'amount' => 999],
        'riga rotta',
    ]);

    return $somme === ['paid' => 40.0, 'refunded' => 10.0, 'pending' => 60.0];
});

summary();
