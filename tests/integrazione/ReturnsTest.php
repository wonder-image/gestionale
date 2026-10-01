<?php
/** php tests/integrazione/ReturnsTest.php */

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Sales\SalesReturn;
use Wonder\Plugin\Gestionale\Models\Sales\SalesReturnItem;
use Wonder\Plugin\Gestionale\Models\Sales\SalesReturnStatusLog;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Returns\Returns;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
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

/** L'ordine venduto: confermato, con una riga prodotto da $venduti pezzi. @return array{0:int,1:int,2:int} ordine, riga, prodotto */
function ordineVenduto(float $venduti = 3, string $stato = 'confirmed', float $giacenza = 10): array
{
    $prodotto = articoloConGiacenza($giacenza, 'TST-RESO-'.substr((string) microtime(true), -6));
    $ordine = ordineDiProva(75.0);
    Order::update(['status' => $stato, 'email' => 'cliente@example.com'], $ordine);
    $riga = OrderItem::create([
        'order_id' => $ordine, 'type' => 'product', 'product_id' => $prodotto, 'position' => 1,
        'name' => 'Crema da prova', 'quantity' => number_format($venduti, 3, '.', ''),
        'unit_price' => '25.00', 'line_total' => number_format($venduti * 25, 2, '.', ''),
    ]);

    return [$ordine, (int) ($riga->insert_id ?? 0), $prodotto];
}

function riga(int $riga, mixed $quantita, string $motivo = 'changed_mind', ?bool $ricarico = null): array
{
    return ['order_item_id' => $riga, 'quantity' => $quantita, 'reason' => $motivo, 'restock' => $ricarico];
}

/** Il rifiuto atteso: la chiave, o null se non c'è stato. */
function rifiuto(callable $fn): ?string
{
    try {
        $fn();
    } catch (UserError $errore) {
        return $errore->getMessage();
    }

    return null;
}

function righe(string $classe, string $where): array
{
    $r = $classe::find($where);

    return !is_array($r) || $r === [] ? [] : (isset($r['id']) ? [$r] : array_values($r));
}

function conReso(callable $corpo): mixed
{
    return prova(static function () use ($corpo) {
        accendiFunzionalita(['orders', 'returns']);

        return $corpo();
    });
}

check('un reso con ricarico rimette la merce in magazzino e lascia il suo segno', function () {
    return conReso(static function (): bool {
        [$ordine, $riga, $prodotto] = ordineVenduto(3);
        $prima = Levels::of($prodotto)['quantity'];
        $esito = Returns::register($ordine, [riga($riga, '2')], ['user_id' => 1]);
        $reso = SalesReturn::findById($esito['return_id']);
        $movimenti = righe(StockMovement::class, "product_id = {$prodotto} AND type = 'return'");
        $log = righe(SalesReturnStatusLog::class, 'sales_return_id = '.$esito['return_id']);

        return Levels::of($prodotto)['quantity'] === $prima + 2
            && $esito['restocked'] === 1 && $esito['kept'] === 0
            && (string) $reso['status'] === 'received' && (string) $reso['received_at'] !== ''
            && (string) $reso['number'] === $esito['number'] && $esito['number'] !== ''
            && count($movimenti) === 1 && (string) $movimenti[0]['reference_type'] === 'sales_return'
            && (int) $movimenti[0]['reference_id'] === $esito['return_id']
            && count($log) === 1 && (string) $log[0]['to_value'] === 'received';
    });
});

check('un reso di merce rotta si registra e in magazzino non entra niente', function () {
    return conReso(static function (): bool {
        [$ordine, $riga, $prodotto] = ordineVenduto(3);
        $prima = Levels::of($prodotto)['quantity'];
        $esito = Returns::register($ordine, [riga($riga, '1', 'damaged')]);
        $voci = righe(SalesReturnItem::class, 'sales_return_id = '.$esito['return_id']);

        return Levels::of($prodotto)['quantity'] === $prima
            && $esito['kept'] === 1 && $esito['restocked'] === 0
            && count($voci) === 1 && (string) $voci[0]['restock'] === 'false'
            && righe(StockMovement::class, "product_id = {$prodotto} AND type = 'return'") === [];
    });
});

check('la spunta tolta a mano vale anche per un motivo che di norma ricarica', function () {
    return conReso(static function (): bool {
        [$ordine, $riga, $prodotto] = ordineVenduto(3);
        $prima = Levels::of($prodotto)['quantity'];
        $esito = Returns::register($ordine, [riga($riga, '1', 'changed_mind', false)]);

        return Levels::of($prodotto)['quantity'] === $prima && $esito['kept'] === 1;
    });
});

check('oltre l\'ordinato si rifiuta e non resta niente', function () {
    return conReso(static function (): bool {
        [$ordine, $riga, $prodotto] = ordineVenduto(3);
        $prima = Levels::of($prodotto)['quantity'];
        $messaggio = rifiuto(static fn () => Returns::register($ordine, [riga($riga, '4')]));

        return $messaggio !== null && str_contains($messaggio, 'al massimo 3')
            && righe(SalesReturn::class, 'order_id = '.$ordine) === []
            && Levels::of($prodotto)['quantity'] === $prima;
    });
});

check('il secondo reso vede solo il residuo, e un reso annullato libera la quantità', function () {
    return conReso(static function (): bool {
        [$ordine, $riga] = ordineVenduto(3);
        $primo = Returns::register($ordine, [riga($riga, '2', 'damaged')]);
        Returns::register($ordine, [riga($riga, '1')]);
        $oltre = rifiuto(static fn () => Returns::register($ordine, [riga($riga, '1')]));
        Returns::cancel($primo['return_id']);
        $ancora = Returns::register($ordine, [riga($riga, '2', 'damaged')]);

        return $oltre !== null && $ancora['return_id'] > 0;
    });
});

check('quantità strane: zero, negativa e testo non fanno un reso, 2,5 si legge bene', function () {
    return conReso(static function (): bool {
        [$ordine, $riga] = ordineVenduto(3);

        foreach (['0', '-1', 'abc'] as $brutta) {
            if (rifiuto(static fn () => Returns::register($ordine, [riga($riga, $brutta)])) === null) {
                return false;
            }
        }

        $esito = Returns::register($ordine, [riga($riga, '2,5', 'damaged')]);
        $voce = righe(SalesReturnItem::class, 'sales_return_id = '.$esito['return_id'])[0];

        return abs((float) $voce['quantity'] - 2.5) < 0.0001
            && count(righe(SalesReturn::class, 'order_id = '.$ordine)) === 1;
    });
});

check('una riga che non è un prodotto dell\'ordine si rifiuta', function () {
    return conReso(static function (): bool {
        [$ordine, $riga] = ordineVenduto(3);
        [, $altra] = ordineVenduto(3);
        $servizio = OrderItem::create([
            'order_id' => $ordine, 'type' => 'custom', 'position' => 2, 'name' => 'Montaggio',
            'quantity' => '1.000', 'unit_price' => '10.00', 'line_total' => '10.00',
        ]);

        return rifiuto(static fn () => Returns::register($ordine, [riga($altra, '1')])) !== null
            && rifiuto(static fn () => Returns::register($ordine, [riga((int) ($servizio->insert_id ?? 0), '1')])) !== null
            && rifiuto(static fn () => Returns::register($ordine, [riga(999999999, '1')])) !== null;
    });
});

check('senza righe, con un motivo sconosciuto o su un ordine che non lo consente si rifiuta', function () {
    return conReso(static function (): bool {
        [$ordine, $riga] = ordineVenduto(3);
        [$annullato, $rigaAnnullato] = ordineVenduto(3, 'cancelled');
        [$attesa, $rigaAttesa] = ordineVenduto(3, 'pending');
        $carrello = ordineDiProva(10.0);
        Order::update(['stage' => 'cart', 'status' => 'draft'], $carrello);

        return rifiuto(static fn () => Returns::register($ordine, [])) !== null
            && rifiuto(static fn () => Returns::register($ordine, [riga($riga, '', 'changed_mind')])) !== null
            && rifiuto(static fn () => Returns::register($ordine, [riga($riga, '1', 'inventato')])) !== null
            && rifiuto(static fn () => Returns::register($annullato, [riga($rigaAnnullato, '1')])) !== null
            && rifiuto(static fn () => Returns::register($attesa, [riga($rigaAttesa, '1')])) !== null
            && rifiuto(static fn () => Returns::register($carrello, [riga($riga, '1')])) !== null
            && rifiuto(static fn () => Returns::register(999999999, [riga($riga, '1')])) !== null;
    });
});

check('con i resi spenti il servizio non registra niente', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders']);
        sqlModify(\Wonder\Plugin\Gestionale\Models\System\Feature::$table, ['enabled' => 'false'], 'feature_key', 'returns');
        \Wonder\Plugin\Gestionale\Gestionale::reset();
        [$ordine, $riga] = ordineVenduto(3);

        return rifiuto(static fn () => Returns::register($ordine, [riga($riga, '1')])) !== null
            && righe(SalesReturn::class, 'order_id = '.$ordine) === [];
    });
});

check('un errore a metà non lascia né il reso né il rientro della prima riga', function () {
    return conReso(static function (): bool {
        [$ordine, $riga, $prodotto] = ordineVenduto(3);
        $seconda = OrderItem::create([
            'order_id' => $ordine, 'type' => 'product', 'product_id' => $prodotto, 'position' => 2,
            'name' => 'Crema da prova bis', 'quantity' => '1.000', 'unit_price' => '25.00', 'line_total' => '25.00',
        ]);
        $prima = Levels::of($prodotto)['quantity'];
        $messaggio = rifiuto(static fn () => Returns::register($ordine, [
            riga($riga, '1'),
            riga((int) ($seconda->insert_id ?? 0), '5'),
        ]));

        return $messaggio !== null
            && righe(SalesReturn::class, 'order_id = '.$ordine) === []
            && Levels::of($prodotto)['quantity'] === $prima
            && righe(StockMovement::class, "product_id = {$prodotto} AND type = 'return'") === [];
    });
});

check('chiudere un reso ricevuto lo porta a completato, una volta sola', function () {
    return conReso(static function (): bool {
        [$ordine, $riga] = ordineVenduto(3);
        $id = Returns::register($ordine, [riga($riga, '1')])['return_id'];
        Returns::complete($id, ['user_id' => 1]);
        $reso = SalesReturn::findById($id);
        $log = righe(SalesReturnStatusLog::class, 'sales_return_id = '.$id);

        return (string) $reso['status'] === 'completed' && (string) $reso['completed_at'] !== ''
            && count($log) === 2
            && rifiuto(static fn () => Returns::complete($id)) !== null
            && rifiuto(static fn () => Returns::cancel($id)) !== null
            && rifiuto(static fn () => Returns::complete(999999999)) !== null;
    });
});

check('un reso senza merce rientrata si annulla, uno con merce a scaffale no', function () {
    return conReso(static function (): bool {
        [$ordine, $riga] = ordineVenduto(3);
        $rotto = Returns::register($ordine, [riga($riga, '1', 'damaged')])['return_id'];
        $buono = Returns::register($ordine, [riga($riga, '1')])['return_id'];
        Returns::cancel($rotto);

        return (string) SalesReturn::findById($rotto)['status'] === 'cancelled'
            && rifiuto(static fn () => Returns::cancel($buono)) !== null
            && (string) SalesReturn::findById($buono)['status'] === 'received';
    });
});

check('le righe dell\'ordine dicono ordinato, già reso e massimo; un reso annullato non conta', function () {
    return conReso(static function (): bool {
        [$ordine, $riga] = ordineVenduto(3);
        $uno = Returns::register($ordine, [riga($riga, '1', 'damaged')])['return_id'];
        Returns::register($ordine, [riga($riga, '1', 'damaged')]);
        Returns::cancel($uno);
        $righe = Returns::lines($ordine);

        return count($righe) === 1 && $righe[0]['order_item_id'] === $riga
            && $righe[0]['ordered'] === 3.0 && $righe[0]['returned'] === 1.0 && $righe[0]['max'] === 2.0
            && $righe[0]['name'] === 'Crema da prova';
    });
});

summary();
