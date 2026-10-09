<?php
/** php tests/PaymentProvidersTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';
require __DIR__ . '/integrazione/supporto/FakePaymentProvider.php';

use Wonder\Plugin\Gestionale\Providers\Payments\PaymentState;
use Wonder\Plugin\Gestionale\Support\Payments\PaymentProviders;

PaymentProviders::reset();
$finto = new FakePaymentProvider();

check('i metodi manuali sono sempre collegati', fn () =>
    PaymentProviders::connected('manual')
    && PaymentProviders::connected('bank_transfer')
    && PaymentProviders::connected('')
);

check('un provider registrato si ritrova col suo codice', function () use ($finto) {
    PaymentProviders::register($finto);

    return PaymentProviders::get('stripe') === $finto && PaymentProviders::get('paypal') === null;
});

check('collegato è quello che dice il provider', function () use ($finto) {
    $finto->connected = false;
    $spento = PaymentProviders::connected('stripe');
    $finto->connected = true;

    return $spento === false && PaymentProviders::connected('stripe') === true;
});

check('un provider senza adapter non è collegato', fn () => PaymentProviders::connected('paypal') === false);

check('il provider finto numera gli intenti e tiene quelli cancellati', function () use ($finto) {
    $primo = $finto->start(['id' => 1], ['id' => 1]);
    $secondo = $finto->start(['id' => 1], ['id' => 1]);
    $finto->cancel($secondo->reference);

    return $primo->reference !== $secondo->reference
        && str_starts_with($primo->reference, 'pi_finto_')
        && $finto->cancelled === [$secondo->reference];
});

check('lo stato di un intento sconosciuto è «altro»', fn () =>
    $finto->status('pi_mai_visto')->status === PaymentState::OTHER
);

PaymentProviders::reset();

summary();
