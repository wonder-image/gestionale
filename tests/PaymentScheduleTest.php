<?php
/** php tests/PaymentScheduleTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Payments\InstallmentMath;
use Wonder\Plugin\Gestionale\Support\Payments\PaymentSchedule;
use Wonder\Plugin\Gestionale\Support\Payments\PaymentTermRules;

$rifiuto = static function (callable $fn): string {
    try {
        $fn();
    } catch (UserError $e) {
        return $e->key();
    }

    return '';
};

$dal = new DateTimeImmutable('2026-10-10');
$riba = [
    ['days' => 30, 'end_of_month' => 'true', 'percentage' => 50],
    ['days' => 60, 'end_of_month' => 'true', 'percentage' => 50],
];

echo "\nScadenze\n";

check('«30 gg fine mese» dal 10 ottobre scade il 30 novembre', fn () =>
    PaymentSchedule::dueDate($dal, 30, true) === '2026-11-30'
    && PaymentSchedule::dueDate($dal, 30, false) === '2026-11-09'
    && PaymentSchedule::dueDate($dal, 0, false) === '2026-10-10'
);

check('RiBa 30/60 fine mese: due rate, a fine novembre e a fine dicembre', fn () =>
    PaymentSchedule::of($riba, 200.0, $dal) === [
        ['position' => 1, 'due_date' => '2026-11-30', 'amount' => 100.0],
        ['position' => 2, 'due_date' => '2026-12-31', 'amount' => 100.0],
    ]
);

check('l\'ultima rata prende il resto dell\'arrotondamento', function () use ($dal) {
    $tre = PaymentSchedule::of([
        ['days' => 30, 'end_of_month' => 'false', 'percentage' => 33.33],
        ['days' => 60, 'end_of_month' => 'false', 'percentage' => 33.33],
        ['days' => 90, 'end_of_month' => 'false', 'percentage' => 33.34],
    ], 100.0, $dal);

    return array_column($tre, 'amount') === [33.33, 33.33, 33.34]
        && round(array_sum(array_column(PaymentSchedule::of([
            ['days' => 0, 'end_of_month' => 'false', 'percentage' => 50],
            ['days' => 30, 'end_of_month' => 'false', 'percentage' => 50],
        ], 99.99, $dal), 'amount')), 2) === 99.99;
});

check('senza condizione è una rata sola, subito; totale zero, nessuna rata', fn () =>
    PaymentSchedule::of([], 50.0, $dal) === [['position' => 1, 'due_date' => '2026-10-10', 'amount' => 50.0]]
    && PaymentSchedule::of($riba, 0.0, $dal) === []
);

check('fine mese da fine gennaio non salta febbraio', fn () =>
    PaymentSchedule::dueDate(new DateTimeImmutable('2027-01-31'), 0, true) === '2027-01-31'
    && PaymentSchedule::dueDate(new DateTimeImmutable('2027-01-31'), 30, true) === '2027-03-31'
    && PaymentSchedule::dueDate(new DateTimeImmutable('2027-01-15'), 30, true) === '2027-02-28'
);

echo "\nRegole delle rate\n";

check('rate valide: righe vuote ignorate, virgola nella percentuale, fine mese', fn () =>
    PaymentTermRules::installments([
        ['days' => '30', 'end_of_month' => 'true', 'percentage' => '50,5'],
        ['days' => '', 'percentage' => ''],
        ['days' => '60', 'percentage' => '49,5'],
    ]) === [
        ['days' => 30, 'end_of_month' => 'true', 'percentage' => 50.5],
        ['days' => 60, 'end_of_month' => 'false', 'percentage' => 49.5],
    ]
);

check('nessuna rata, giorni storti, percentuale fuori, somma diversa da 100 sono rifiuti', fn () =>
    $rifiuto(fn () => PaymentTermRules::installments([])) === 'payment_term.no_installments'
    && $rifiuto(fn () => PaymentTermRules::installments([['days' => '30x', 'percentage' => 100]])) === 'payment_term.invalid_days'
    && $rifiuto(fn () => PaymentTermRules::installments([['days' => '400', 'percentage' => 100]])) === 'payment_term.invalid_days'
    && $rifiuto(fn () => PaymentTermRules::installments([['days' => '0', 'percentage' => 0]])) === 'payment_term.invalid_percentage'
    && $rifiuto(fn () => PaymentTermRules::installments([['days' => '0', 'percentage' => 120]])) === 'payment_term.invalid_percentage'
    && $rifiuto(fn () => PaymentTermRules::installments([['days' => '0', 'percentage' => 50], ['days' => '30', 'percentage' => 40]])) === 'payment_term.percentages_sum'
);

echo "\nIncassi sulle rate\n";

$rate = [
    ['id' => 1, 'amount' => 100.0, 'due_date' => '2026-11-30'],
    ['id' => 2, 'amount' => 100.0, 'due_date' => '2026-12-31'],
    ['id' => 3, 'amount' => 50.0, 'due_date' => '2027-01-31'],
];

check('incasso uguale: la rata è pagata e basta', fn () =>
    InstallmentMath::register($rate, 1, 100.0) === ['paid' => ['id' => 1, 'amount' => 100.0], 'create' => [], 'update' => [], 'cancel' => []]
);

check('incasso minore: pagata per quanto incassato, il resto con la stessa scadenza', fn () =>
    InstallmentMath::register($rate, 2, 60.0) === [
        'paid' => ['id' => 2, 'amount' => 60.0],
        'create' => [['amount' => 40.0, 'due_date' => '2026-12-31']],
        'update' => [],
        'cancel' => [],
    ]
);

check('incasso maggiore: l\'eccedenza scala le rate dopo, una azzerata si annulla', fn () =>
    InstallmentMath::register($rate, 1, 230.0) === [
        'paid' => ['id' => 1, 'amount' => 230.0],
        'create' => [],
        'update' => [3 => 20.0],
        'cancel' => [2],
    ]
);

check('oltre il dovuto, zero, o una rata che non è in attesa: rifiuti', fn () =>
    $rifiuto(fn () => InstallmentMath::register($rate, 1, 250.01)) === 'payment.over_balance'
    && $rifiuto(fn () => InstallmentMath::register($rate, 2, 150.01)) === 'payment.over_balance'
    && $rifiuto(fn () => InstallmentMath::register($rate, 1, 0)) === 'payment.zero_amount'
    && $rifiuto(fn () => InstallmentMath::register($rate, 9, 10)) === 'payment.installment_not_pending'
);

echo "\nRicalcolo\n";

check('il da pagare si ridistribuisce in proporzione, l\'ultima col resto', fn () =>
    InstallmentMath::redistribute($rate, 125.0) === ['update' => [1 => 50.0, 2 => 50.0, 3 => 25.0], 'cancel' => []]
    && InstallmentMath::redistribute($rate, 100.01)['update'] === [1 => 40.0, 2 => 40.0, 3 => 20.01]
);

check('da pagare zero o meno: tutte annullate; nessuna rata, niente da fare', fn () =>
    InstallmentMath::redistribute($rate, 0.0) === ['update' => [], 'cancel' => [1, 2, 3]]
    && InstallmentMath::redistribute([], 50.0) === ['update' => [], 'cancel' => []]
);

summary();
