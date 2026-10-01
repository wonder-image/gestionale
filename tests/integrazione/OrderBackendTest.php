<?php
/** php tests/integrazione/OrderBackendTest.php */

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderResource;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

function prova(callable $corpo): mixed
{
    $esito = null;

    try {
        Transaction::run(static function () use ($corpo, &$esito): void {
            $esito = $corpo();

            throw new Annulla();
        });
    } catch (Annulla) {
    }

    return $esito;
}

check('l\'elenco prende l\'ordine e lascia il carrello', function () {
    return prova(static function (): bool {
        $ordine = ordineDiProva(10.0);
        $carrello = Order::create(['stage' => 'cart', 'status' => 'draft', 'payment_status' => 'unpaid', 'total' => '5.00']);
        $carrelloId = (int) ($carrello->insert_id ?? 0);
        $condizione = (string) OrderResource::querySchema()['condition'];

        return (int) sqlCount(Order::$table, "({$condizione}) AND id = {$ordine}") === 1
            && (int) sqlCount(Order::$table, "({$condizione}) AND id = {$carrelloId}") === 0;
    });
});

check('un ordine senza nomi si riconosce dall\'email', function () {
    return prova(static function (): bool {
        $ordine = ordineDiProva(10.0);
        Order::update(['email' => 'solo.email@example.com'], $ordine);

        return OrderResource::customerName((array) Order::findById($ordine)) === 'solo.email@example.com';
    });
});

summary();
