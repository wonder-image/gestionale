<?php
/** php tests/integrazione/ReturnsTest.php */

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';
require __DIR__.'/supporto/dns-fixture.php';

use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Sales\SalesReturn;
use Wonder\Plugin\Gestionale\Models\Sales\SalesReturnItem;
use Wonder\Plugin\Gestionale\Models\Sales\SalesReturnStatusLog;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Support\Catalog\Customizations;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Orders\Lifecycle;
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

/**
 * L'ordine con una confezione da $venduti pezzi (due componenti: uno da 1 pezzo
 * per confezione, uno da 2) e un articolo semplice.
 *
 * @return array{order:int, mother:int, children:list<array{item:int, product:int, per:float}>, simple:int}
 */
function ordineConConfezione(float $venduti = 3): array
{
    $prodotto = articoloConGiacenza(50, 'TST-RESO-M'.substr((string) microtime(true), -6));
    $ordine = ordineDiProva(100.0);
    Order::update(['status' => 'confirmed', 'email' => 'cliente@example.com'], $ordine);
    $madre = (int) (OrderItem::create([
        'order_id' => $ordine, 'type' => 'product', 'product_id' => $prodotto, 'position' => 1,
        'name' => 'Confezione regalo', 'quantity' => number_format($venduti, 3, '.', ''),
        'unit_price' => '25.00', 'line_total' => number_format($venduti * 25, 2, '.', ''),
    ])->insert_id ?? 0);
    $figlie = [];

    foreach ([1 => 1.0, 2 => 2.0] as $posizione => $perConfezione) {
        $componente = articoloConGiacenza(50, 'TST-RESO-F'.$posizione.substr((string) microtime(true), -5));
        $riga = (int) (OrderItem::create([
            'order_id' => $ordine, 'type' => 'product', 'product_id' => $componente, 'position' => 1 + $posizione,
            'parent_item_id' => $madre, 'name' => 'Componente '.$posizione.' <b>',
            'quantity' => number_format($perConfezione * $venduti, 3, '.', ''), 'unit_price' => '0.00', 'line_total' => '0.00',
        ])->insert_id ?? 0);
        $figlie[] = ['item' => $riga, 'product' => $componente, 'per' => $perConfezione];
    }

    $semplice = (int) (OrderItem::create([
        'order_id' => $ordine, 'type' => 'product', 'product_id' => articoloConGiacenza(10, 'TST-RESO-S'.substr((string) microtime(true), -5)),
        'position' => 4, 'name' => 'Articolo semplice', 'quantity' => '1.000', 'unit_price' => '25.00', 'line_total' => '25.00',
    ])->insert_id ?? 0);

    return ['order' => $ordine, 'mother' => $madre, 'children' => $figlie, 'simple' => $semplice];
}

check('le righe rendibili riportano la personalizzazione decodificata', function () {
    return prova(static function (): bool {
        [$ordine, $riga] = ordineVenduto();
        $campi = [['customization_id' => 1, 'label' => 'Incisione', 'value' => 'Café & co', 'option_id' => 0, 'surcharge' => '5.00']];
        OrderItem::update(['customization' => Customizations::encode($campi)], $riga);

        $righe = Returns::lines($ordine);

        return count($righe) === 1 && $righe[0]['customization'] === Customizations::decode(Customizations::encode($campi))
            && $righe[0]['customization'][0]['value'] === 'Café & co';
    });
});

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

check('leggere quanto è reso e le righe di un ordine non chiede una transazione', function () {
    // La pagina «Registra reso» legge senza scrivere: fuori da Transaction::run
    // il blocco delle righe (FOR UPDATE) non c'è e non deve servire.
    return !Transaction::active() && Returns::returned(0) === 0.0 && Returns::lines(0) === [];
});

check('annullare un ordine con un reso fa rientrare solo la merce non ancora resa', function () {
    return conReso(static function (): bool {
        [$ordine, $riga, $prodotto] = ordineVenduto(3);
        Returns::register($ordine, [riga($riga, '2')]);
        $dopoReso = Levels::of($prodotto)['quantity'];
        Lifecycle::cancel($ordine, ['source' => 'user']);

        return Levels::of($prodotto)['quantity'] === $dopoReso + 1;
    });
});

check('annullare un ordine con un reso di merce rotta non rimette a scaffale i pezzi resi', function () {
    return conReso(static function (): bool {
        [$ordine, $riga, $prodotto] = ordineVenduto(3);
        Returns::register($ordine, [riga($riga, '2', 'damaged')]);
        $dopoReso = Levels::of($prodotto)['quantity'];
        Lifecycle::cancel($ordine, ['source' => 'user']);

        return Levels::of($prodotto)['quantity'] === $dopoReso + 1;
    });
});

check('le righe rendibili sono di primo livello e la confezione porta i suoi componenti', function () {
    return prova(static function (): bool {
        $c = ordineConConfezione(3);
        $righe = Returns::lines($c['order']);
        $figlie = $righe[0]['children'] ?? [];

        return count($righe) === 2
            && $righe[0]['order_item_id'] === $c['mother'] && $righe[1]['order_item_id'] === $c['simple']
            && ($righe[1]['children'] ?? null) === []
            && count($figlie) === 2
            && $figlie[0]['order_item_id'] === $c['children'][0]['item'] && abs($figlie[0]['per_unit'] - 1.0) < 0.0001
            && $figlie[1]['order_item_id'] === $c['children'][1]['item'] && abs($figlie[1]['per_unit'] - 2.0) < 0.0001
            && $figlie[0]['returned'] === 0.0 && $figlie[0]['restock_default'] === true;
    });
});

check('il reso di una confezione scrive madre e figlie e rimette a scaffale i componenti', function () {
    return conReso(static function (): bool {
        $c = ordineConConfezione(3);
        $prima = array_map(static fn (array $f): float => Levels::of($f['product'])['quantity'], $c['children']);
        $esito = Returns::register($c['order'], [riga($c['mother'], '1')]);
        $voci = righe(SalesReturnItem::class, 'sales_return_id = '.$esito['return_id']);
        $perRiga = [];

        foreach ($voci as $voce) {
            $perRiga[(int) $voce['order_item_id']] = $voce;
        }

        return count($voci) === 3
            && abs((float) $perRiga[$c['mother']]['quantity'] - 1.0) < 0.0001 && (string) $perRiga[$c['mother']]['restock'] === 'false'
            && abs((float) $perRiga[$c['children'][0]['item']]['quantity'] - 1.0) < 0.0001
            && abs((float) $perRiga[$c['children'][1]['item']]['quantity'] - 2.0) < 0.0001
            && (string) $perRiga[$c['children'][1]['item']]['restock'] === 'true'
            && Levels::of($c['children'][0]['product'])['quantity'] === $prima[0] + 1
            && Levels::of($c['children'][1]['product'])['quantity'] === $prima[1] + 2
            && $esito['restocked'] === 2 && $esito['kept'] === 0
            && righe(StockMovement::class, "product_id = {$c['children'][0]['product']} AND type = 'return'") !== [];
    });
});

check('con children_restock un componente può restare fuori dallo scaffale', function () {
    return conReso(static function (): bool {
        $c = ordineConConfezione(3);
        $prima = array_map(static fn (array $f): float => Levels::of($f['product'])['quantity'], $c['children']);
        $esito = Returns::register($c['order'], [
            riga($c['mother'], '1') + ['children_restock' => [$c['children'][1]['item'] => false, $c['children'][0]['item'] => null]],
        ]);

        return Levels::of($c['children'][0]['product'])['quantity'] === $prima[0] + 1
            && Levels::of($c['children'][1]['product'])['quantity'] === $prima[1]
            && $esito['restocked'] === 1 && $esito['kept'] === 1;
    });
});

check('un reso di confezione per merce difettosa non fa rientrare nessun componente', function () {
    return conReso(static function (): bool {
        $c = ordineConConfezione(3);
        $prima = array_map(static fn (array $f): float => Levels::of($f['product'])['quantity'], $c['children']);
        $esito = Returns::register($c['order'], [riga($c['mother'], '2', 'defective')]);

        return Levels::of($c['children'][0]['product'])['quantity'] === $prima[0]
            && Levels::of($c['children'][1]['product'])['quantity'] === $prima[1]
            && $esito['restocked'] === 0 && $esito['kept'] === 2;
    });
});

check('un componente chiesto da solo non si rende', function () {
    return conReso(static function (): bool {
        $c = ordineConConfezione(3);

        try {
            Returns::register($c['order'], [riga($c['children'][0]['item'], '1')]);
            $chiave = '';
        } catch (UserError $errore) {
            $chiave = $errore->key();
        }

        return $chiave === 'return.line_not_returnable' && righe(SalesReturn::class, 'order_id = '.$c['order']) === [];
    });
});

check('il secondo reso di confezioni vede solo il residuo, madre e figlie', function () {
    return conReso(static function (): bool {
        $c = ordineConConfezione(3);
        Returns::register($c['order'], [riga($c['mother'], '1')]);
        $messaggio = rifiuto(static fn () => Returns::register($c['order'], [riga($c['mother'], '3')]));
        $righe = Returns::lines($c['order']);

        return $messaggio !== null && str_contains($messaggio, 'al massimo 2')
            && $righe[0]['max'] === 2.0 && $righe[0]['returned'] === 1.0
            && $righe[0]['children'][1]['returned'] === 2.0;
    });
});

check('due righe per la stessa confezione nella stessa richiesta si fondono', function () {
    return conReso(static function (): bool {
        $c = ordineConConfezione(3);
        $esito = Returns::register($c['order'], [riga($c['mother'], '1'), riga($c['mother'], '1')]);
        $voci = righe(SalesReturnItem::class, 'sales_return_id = '.$esito['return_id']);
        $quantita = [];

        foreach ($voci as $voce) {
            $quantita[(int) $voce['order_item_id']] = (float) $voce['quantity'];
        }

        return count($voci) === 3 && abs($quantita[$c['mother']] - 2.0) < 0.0001
            && abs($quantita[$c['children'][1]['item']] - 4.0) < 0.0001;
    });
});

check('la pagina dice i pezzi dei componenti di una confezione resa, non quelli della riga madre', function () {
    return conReso(static function (): bool {
        $c = ordineConConfezione(3);
        $esito = \Wonder\Plugin\Gestionale\Resources\Sales\OrderReturnResource::run($c['order'], [
            'lines' => [
                $c['mother'] => [
                    'quantity' => '1', 'reason' => 'changed_mind',
                    'children_restock' => [$c['children'][0]['item'] => 'on', $c['children'][1]['item'] => ''],
                ],
            ],
        ], 1);

        return $esito['ok'] && str_contains($esito['message'], 'di 3 pezzi') && str_contains($esito['message'], '1 rientrato');
    });
});

check('annullare l\'ordine dopo il reso di una confezione rimette quantità meno reso per ogni componente', function () {
    return conReso(static function (): bool {
        $c = ordineConConfezione(3);

        Returns::register($c['order'], [
            riga($c['mother'], '1') + ['children_restock' => [$c['children'][1]['item'] => false]],
        ]);
        $dopo = array_map(static fn (array $f): float => Levels::of($f['product'])['quantity'], $c['children']);
        Lifecycle::cancel($c['order'], ['source' => 'user']);

        return Levels::of($c['children'][0]['product'])['quantity'] === $dopo[0] + 2
            && Levels::of($c['children'][1]['product'])['quantity'] === $dopo[1] + 4;
    });
});

summary();
