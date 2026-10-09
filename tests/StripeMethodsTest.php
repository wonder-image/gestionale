<?php
/** php tests/StripeMethodsTest.php */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

use Wonder\Plugin\Gestionale\Providers\Payments\StripeMethods;

$predefinita = ['id' => 'pmc_def', 'active' => true, 'is_default' => true, 'livemode' => false, 'name' => 'Default',
    'card' => ['available' => true], 'apple_pay' => ['available' => true], 'klarna' => ['available' => true],
    'paypal' => ['available' => false], 'link' => ['available' => true], 'sepa_debit' => ['available' => true]];
$altra = ['id' => 'pmc_alt', 'active' => true, 'is_default' => false, 'card' => ['available' => true], 'bancontact' => ['available' => true]];

check('i tipi vengono dalla configurazione predefinita e attiva, solo quelli disponibili', fn () =>
    StripeMethods::typesFrom([$altra, $predefinita]) === ['card', 'apple_pay', 'klarna', 'link', 'sepa_debit']);

check('senza predefinita attiva vale la prima attiva', fn () =>
    StripeMethods::typesFrom([['active' => false, 'is_default' => true, 'card' => ['available' => true]], $altra]) === ['card', 'bancontact']);

check('nessuna configurazione attiva: nessun tipo', fn () =>
    StripeMethods::typesFrom([['active' => false, 'is_default' => true, 'card' => ['available' => true]]]) === []);

check('le scelte: carta, poi gli altri in ordine alfabetico; Link sta nella barra rapida', fn () =>
    StripeMethods::choices(['sepa_debit', 'card', 'klarna', 'link']) === ['card', 'klarna', 'sepa_debit']);

check('Apple Pay e Google Pay non sono scelte; la carta c\'è sempre', fn () =>
    StripeMethods::choices(['apple_pay', 'google_pay', 'klarna']) === ['card', 'klarna'] && StripeMethods::choices([]) === ['card']);

check('nomi: la carta tiene quello della riga, gli sconosciuti diventano leggibili', fn () =>
    StripeMethods::name('card', 'Carta di credito') === 'Carta di credito'
    && StripeMethods::name('link', 'x') === 'Link'
    && StripeMethods::name('klarna', 'x') === 'Klarna'
    && StripeMethods::name('sepa_debit', 'x') === 'Addebito SEPA'
    && StripeMethods::name('nuovo_metodo', 'x') === 'Nuovo metodo');

check('icone: la carta perde wallet e metodi separati, gli sconosciuti hanno l\'icona generica', fn () =>
    StripeMethods::icons('card', ['visa', 'master', 'google_pay', 'apple_pay', 'klarna', 'paypal']) === ['visa', 'master']
    && StripeMethods::icons('klarna', []) === ['klarna']
    && StripeMethods::icons('kakao_pay', []) === ['genericbank']);

check('i metodi Stripe aggiunti hanno la loro icona, e la carta mostra solo i circuiti', fn () =>
    StripeMethods::icons('amazon_pay', []) === ['amazon_pay']
    && StripeMethods::icons('revolut_pay', []) === ['revolut_pay']
    && StripeMethods::icons('satispay', []) === ['satispay']
    && StripeMethods::icons('scalapay', []) === ['scalapay']
    && StripeMethods::icons('twint', []) === ['twint']
    && StripeMethods::icons('afterpay_clearpay', []) === ['afterpay_clearpay']
    && StripeMethods::icons('card', ['visa', 'satispay', 'amazon_pay', 'master', 'genericbank']) === ['visa', 'master']);

check('tipi dell\'intento: solo quello della scelta', fn () =>
    StripeMethods::intentTypes('card') === ['card'] && StripeMethods::intentTypes('klarna') === ['klarna']);

summary();
