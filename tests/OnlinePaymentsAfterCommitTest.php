<?php
/** php tests/OnlinePaymentsAfterCommitTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

/*
 * In `OnlinePayments::succeeded()` email e avvisi partono solo dopo la
 * transazione: dentro, un rollback lascerebbe mail già spedite.
 */

$file = (string) file_get_contents(__DIR__ . '/../src/Support/Payments/OnlinePayments.php');
$inizio = strpos($file, 'public static function succeeded(');
$fine = $inizio === false ? false : strpos($file, "\n    }\n", $inizio);
$corpo = $inizio === false || $fine === false ? '' : substr($file, $inizio, $fine - $inizio);
$apertura = strpos($corpo, 'Transaction::run(');
$chiusura = $apertura === false ? false : strpos($corpo, "\n        });\n", $apertura);

check('succeeded() apre una transazione e la chiude', fn () => $corpo !== '' && $apertura !== false && $chiusura !== false);

check('dentro la transazione non parte nessuna email né avviso', function () use ($corpo, $apertura, $chiusura) {
    $dentro = substr($corpo, (int) $apertura, (int) $chiusura - (int) $apertura);

    return !str_contains($dentro, 'OrderNotifier::')
        && !str_contains($dentro, 'self::alert(')
        && !str_contains($dentro, 'Mailer::');
});

check('dentro la transazione la prima lettura è il lock dell\'ordine', function () use ($corpo, $apertura, $chiusura) {
    $dentro = substr($corpo, (int) $apertura, (int) $chiusura - (int) $apertura);
    $lock = strpos($dentro, 'Order::findForUpdate(');

    return !str_contains($dentro, 'Ledger::byReference(')
        && $lock !== false
        && $lock < (int) strpos($dentro, 'Payment::findForUpdate(')
        && str_contains(substr($corpo, 0, (int) $apertura), 'Ledger::byReference(');
});

check('dopo il commit partono le email del pagamento e l\'avviso', function () use ($corpo, $chiusura) {
    $dopo = substr($corpo, (int) $chiusura);

    return str_contains($dopo, "OrderNotifier::send('paid'")
        && str_contains($dopo, "OrderNotifier::send('merchant_paid'")
        && str_contains($dopo, 'self::alert(');
});

summary();
