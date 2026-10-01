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
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Sales\OrderTaxSummary;
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

/** Tutto l'HTML della scheda, per cercarci dentro. */
function schedaHtml(int $ordine): string
{
    $layout = OrderResource::showLayoutSchema((array) Order::findById($ordine));
    $html = '';

    foreach ($layout->components as $card) {
        foreach ($card->components as $c) {
            $html .= (string) (new ReflectionProperty($c, 'text'))->getValue($c).' ';
        }
    }

    return $html;
}

check('la scheda mostra numero, cliente, totale e riepilogo IVA; il nome è escapato', function () {
    return prova(static function (): bool {
        $ordine = ordineDiProva(122.0);
        Order::update([
            'order_number' => '2025/777', 'billing_name' => '<b>x</b>', 'billing_surname' => 'Rossi',
            'taxable_total' => '100.00', 'tax_total' => '22.00', 'products_total' => '100.00',
        ], $ordine);
        OrderTaxSummary::create(['order_id' => $ordine, 'rate' => '22.00', 'taxable' => '100.00', 'tax' => '22.00', 'total' => '122.00']);
        $html = schedaHtml($ordine);

        return str_contains($html, '2025/777') && str_contains($html, '122,00 €')
            && str_contains($html, '22%') && str_contains($html, '&lt;b&gt;x&lt;/b&gt;')
            && !str_contains($html, '<b>x</b>');
    });
});

check('un ordine con la sola spedizione si disegna senza avvisi', function () {
    return prova(static function (): bool {
        $ordine = ordineDiProva(5.0);
        OrderItem::create([
            'order_id' => $ordine, 'type' => 'shipping', 'position' => 1, 'name' => 'Spedizione',
            'quantity' => '1.000', 'unit_price' => '5.00', 'line_total' => '5.00',
        ]);
        $avvisi = [];
        set_error_handler(static function (int $n, string $m) use (&$avvisi): bool {
            $avvisi[] = $m;

            return true;
        });

        try {
            $html = schedaHtml($ordine);
        } finally {
            restore_error_handler();
        }

        return $avvisi === [] && str_contains($html, 'Spedizione');
    });
});

check('una riga cancellata non compare nella scheda', function () {
    return prova(static function (): bool {
        $ordine = ordineDiProva(5.0);
        $riga = OrderItem::create([
            'order_id' => $ordine, 'type' => 'custom', 'position' => 1, 'name' => 'Riga da dimenticare',
            'quantity' => '1.000', 'unit_price' => '5.00', 'line_total' => '5.00',
        ]);
        OrderItem::query()->Update(OrderItem::$table, ['deleted' => 'true'], 'id', (int) ($riga->insert_id ?? 0));

        return !str_contains(schedaHtml($ordine), 'Riga da dimenticare');
    });
});

summary();
