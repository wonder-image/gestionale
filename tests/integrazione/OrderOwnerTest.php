<?php
/** php tests/integrazione/OrderOwnerTest.php */

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Support\Orders\OrderOwner;
use Wonder\Sql\Transaction;

final class AnnullaOrderOwner extends RuntimeException {}

/** Un ordine con l'email e il proprietario dati. */
function ordineDi(string $email, int $utente = 0, int $cliente = 0): int
{
    $ordine = ordineDiProva(30.00);
    Order::update(['email' => $email, 'user_id' => $utente, 'customer_id' => $cliente], $ordine);

    return $ordine;
}

$email = 'proprietario-'.bin2hex(random_bytes(5)).'@example.com';

try {
    Transaction::run(static function () use ($email): void {
        $senzaUtente = ordineDi($email);
        $maiuscolo = ordineDi(strtoupper($email));
        $conScheda = ordineDi($email, 0, 777);
        $altrui = ordineDi($email, 999999, 888);
        $altraEmail = ordineDi('altro-'.$email);

        $allegati = OrderOwner::claim(4242, 4343, '  '.strtoupper($email).' ');
        $di = static fn (int $id): array => (array) Order::findById($id);

        check('gli ordini con la sua email e senza utente passano al nuovo account, anche con le maiuscole', fn () =>
            $allegati === 3
            && (int) $di($senzaUtente)['user_id'] === 4242 && (int) $di($senzaUtente)['customer_id'] === 4343
            && (int) $di($maiuscolo)['user_id'] === 4242
        );

        check('la scheda cliente già scritta sull\'ordine resta quella', fn () =>
            (int) $di($conScheda)['user_id'] === 4242 && (int) $di($conScheda)['customer_id'] === 777
        );

        check('un ordine di un altro account o di un\'altra email non si tocca', fn () =>
            (int) $di($altrui)['user_id'] === 999999 && (int) $di($altrui)['customer_id'] === 888
            && (int) $di($altraEmail)['user_id'] === 0
        );

        check('senza utente o senza email non si allega niente', fn () =>
            OrderOwner::claim(0, 1, $email) === 0 && OrderOwner::claim(4242, 1, '  ') === 0
        );

        throw new AnnullaOrderOwner();
    });
} catch (AnnullaOrderOwner) {
}

summary();
