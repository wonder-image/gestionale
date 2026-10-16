<?php
/** php tests/StripeSubscriptionDataTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Subscriptions\Subscription;
use Wonder\Plugin\Gestionale\Support\Subscriptions\StripeSubscriptionData;
use Wonder\Plugin\Gestionale\Support\Subscriptions\SubscriptionEmail;
use Wonder\Plugin\Gestionale\Support\Subscriptions\SubscriptionEvents;

$leggi = static fn (string $nome): array => json_decode((string) file_get_contents(__DIR__.'/supporto/stripe/'.$nome.'.json'), true);

check('forma «basil»: periodo sulla voce, id nei metadati, prezzo come oggetto', function () use ($leggi) {
    $d = StripeSubscriptionData::subscription($leggi('subscription-basil'));

    return $d['reference'] === 'sub_basil1' && $d['local_id'] === 42 && $d['status'] === 'trialing'
        && $d['price'] === 'price_77' && $d['customer'] === 'cus_9'
        && $d['current_period_start'] === date('Y-m-d H:i:s', 1760000000)
        && $d['current_period_end'] === date('Y-m-d H:i:s', 1761209600)
        && $d['trial_ends_at'] === date('Y-m-d H:i:s', 1761209600)
        && $d['auto_renew'] === true && $d['cancelled_at'] === null;
});

check('forma di prima: periodo in cima, prezzo come stringa, cliente espanso', function () use ($leggi) {
    $d = StripeSubscriptionData::subscription($leggi('subscription-old'));

    return $d['reference'] === 'sub_old1' && $d['local_id'] === 0 && $d['status'] === 'active'
        && $d['price'] === 'price_55' && $d['customer'] === 'cus_8'
        && $d['current_period_end'] === date('Y-m-d H:i:s', 1762678400)
        && $d['trial_ends_at'] === null;
});

check('disdetto a fine periodo: non rinnova più, ma resta attivo', function () use ($leggi) {
    $d = StripeSubscriptionData::subscription($leggi('subscription-old'));

    return $d['status'] === 'active' && $d['auto_renew'] === false;
});

check('più servizi: un item per voce con prezzo, quantità, riga e periodo; in alto il periodo che finisce prima', function () use ($leggi) {
    $d = StripeSubscriptionData::subscription([
        'id' => 'sub_m', 'status' => 'active',
        'items' => ['data' => [
            ['id' => 'si_a', 'quantity' => 1, 'price' => ['id' => 'price_a'], 'metadata' => ['item_id' => '7'], 'current_period_start' => 1760000000, 'current_period_end' => 1791536000],
            ['id' => 'si_b', 'quantity' => 3, 'price' => 'price_b', 'current_period_start' => 1760000000, 'current_period_end' => 1762678400],
        ]],
    ]);
    $basil = StripeSubscriptionData::subscription($leggi('subscription-basil'));
    $old = StripeSubscriptionData::subscription($leggi('subscription-old'));

    return count($d['items']) === 2
        && $d['items'][0] === ['id' => 'si_a', 'price' => 'price_a', 'quantity' => 1, 'item_id' => 7, 'period_start' => date('Y-m-d H:i:s', 1760000000), 'period_end' => date('Y-m-d H:i:s', 1791536000)]
        && $d['items'][1]['price'] === 'price_b' && $d['items'][1]['quantity'] === 3 && $d['items'][1]['item_id'] === 0
        && $d['current_period_end'] === date('Y-m-d H:i:s', 1762678400) && $d['price'] === 'price_b'
        && count($basil['items']) === 1 && $basil['items'][0]['id'] === 'si_1' && $basil['items'][0]['quantity'] === 1
        && $old['items'][0]['period_end'] === null && $old['current_period_end'] === date('Y-m-d H:i:s', 1762678400)
        && StripeSubscriptionData::subscription(['status' => 'active'])['items'] === [];
});

check('gli stati di Stripe finiscono tutti in uno stato del gestionale', function () {
    foreach (StripeSubscriptionData::STATUSES as $nostro) {
        if (!in_array($nostro, Subscription::STATUSES, true)) {
            return false;
        }
    }

    return StripeSubscriptionData::subscription(['status' => 'inventato'])['status'] === 'pending'
        && StripeSubscriptionData::subscription(['status' => 'incomplete_expired'])['status'] === 'cancelled';
});

check('chiuso: annullato, o non pagato se chiuso per il pagamento', fn () =>
    StripeSubscriptionData::subscription(['status' => 'canceled'], true)['status'] === 'cancelled'
    && StripeSubscriptionData::subscription(['status' => 'canceled', 'cancellation_details' => ['reason' => 'payment_failed']], true)['status'] === 'unpaid'
    && StripeSubscriptionData::subscription(['status' => 'canceled'], true)['auto_renew'] === false
);

check('la fattura trova il suo abbonamento nelle due forme', function () use ($leggi) {
    return StripeSubscriptionData::invoiceSubscription($leggi('invoice-basil')) === 'sub_basil1'
        && StripeSubscriptionData::invoiceSubscription($leggi('invoice-old')) === 'sub_old1'
        && StripeSubscriptionData::invoiceLocalId($leggi('invoice-basil')) === 42
        && StripeSubscriptionData::invoiceLocalId($leggi('invoice-old')) === 7
        && StripeSubscriptionData::invoiceSubscription(['subscription' => ['id' => 'sub_x']]) === 'sub_x'
        && StripeSubscriptionData::invoiceSubscription([]) === '';
});

check('importo, valuta e data del pagamento dalla fattura', function () use ($leggi) {
    $pagata = StripeSubscriptionData::invoicePayment($leggi('invoice-basil'));
    $zero = StripeSubscriptionData::invoicePayment($leggi('invoice-old'));

    return $pagata['amount'] === 24.4 && $pagata['currency'] === 'EUR' && $pagata['paid_at'] === date('Y-m-d H:i:s', 1760003600)
        && $zero['amount'] === 0.0 && $zero['paid_at'] === date('Y-m-d H:i:s', 1760000000);
});

check('le notifiche degli abbonamenti si riconoscono; quelle degli ordini no', fn () =>
    SubscriptionEvents::handles('invoice.paid')
    && SubscriptionEvents::handles('customer.subscription.deleted')
    && SubscriptionEvents::handles('checkout.session.completed')
    && !SubscriptionEvents::handles('payment_intent.succeeded')
    && !SubscriptionEvents::handles('charge.refunded')
);

check('email di conferma: piano, prova e rinnovo, con il link all\'area cliente', function () {
    $email = SubscriptionEmail::compose('confirmed', [
        'total' => '24.40', 'trial_ends_at' => '2026-11-01 10:00:00', 'next_renewal_at' => '2026-11-01 10:00:00',
    ], ['plan' => 'Pro — Mensile', 'account_url' => 'https://negozio.test/account/abbonamenti/']);

    return $email['subject'] === 'Abbonamento Pro — Mensile attivato'
        && str_contains($email['body'], '01/11/2026')
        && str_contains($email['body'], 'https://negozio.test/account/abbonamenti/');
});

check('disdetta: la fine è a fine vincolo se dura ancora, altrimenti a fine periodo', function () {
    $vincolo = date('Y-m-d H:i:s', time() + 90 * 86400);
    $periodo = date('Y-m-d H:i:s', time() + 10 * 86400);

    return SubscriptionEmail::endOf(['commitment_ends_at' => $vincolo, 'current_period_end' => $periodo]) === $vincolo
        && SubscriptionEmail::endOf(['commitment_ends_at' => '2020-01-01 00:00:00', 'current_period_end' => $periodo]) === $periodo
        && str_contains(SubscriptionEmail::compose('cancel_requested', ['current_period_end' => $periodo], ['plan' => 'Base'])['body'], date('d/m/Y', strtotime($periodo)));
});

check('una chiave inventata dà la conferma, il nome del piano non inietta markup', function () {
    $email = SubscriptionEmail::compose('boh', [], ['plan' => '<b>Pro</b>']);

    return str_contains($email['subject'], 'attivato') && !str_contains($email['body'], '<b>Pro</b>');
});

summary();
