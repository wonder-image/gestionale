<?php
/** php tests/integrazione/ReturnBackendTest.php */

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Sales\SalesReturn;
use Wonder\Plugin\Gestionale\Models\System\Feature;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderResource;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderReturnResource;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderReturnTableResource;
use Wonder\Plugin\Gestionale\Resources\Stock\StockAdjustmentResource;
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

/** @return array{0:int,1:int,2:int} ordine, riga, prodotto */
function ordineVenduto(float $venduti = 3, string $stato = 'confirmed'): array
{
    $prodotto = articoloConGiacenza(10, 'TST-RB-'.substr((string) microtime(true), -6));
    $ordine = ordineDiProva(75.0);
    Order::update(['status' => $stato], $ordine);
    $riga = OrderItem::create([
        'order_id' => $ordine, 'type' => 'product', 'product_id' => $prodotto, 'position' => 1,
        'name' => 'Crema da prova', 'quantity' => number_format($venduti, 3, '.', ''),
        'unit_price' => '25.00', 'line_total' => number_format($venduti * 25, 2, '.', ''),
    ]);

    return [$ordine, (int) ($riga->insert_id ?? 0), $prodotto];
}

function moduloReso(int $riga, string $quantita, string $motivo = 'changed_mind', string $ricarico = 'on'): array
{
    return ['lines' => [$riga => ['quantity' => $quantita, 'reason' => $motivo, 'restock' => $ricarico]]];
}

function resiDi(int $ordine): array
{
    $r = SalesReturn::find(['order_id' => $ordine, 'deleted' => 'false']);

    return !is_array($r) || $r === [] ? [] : (isset($r['id']) ? [$r] : array_values($r));
}

check('registra un reso e dice quanti pezzi sono rientrati', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'returns']);
        [$ordine, $riga] = ordineVenduto(3);
        $esito = OrderReturnResource::run($ordine, moduloReso($riga, '2'), 1);
        $resi = resiDi($ordine);

        return $esito['ok'] === true && count($resi) === 1
            && str_contains($esito['message'], 'Registrato il reso '.$resi[0]['number'])
            && str_contains($esito['message'], '2 pezzi') && str_contains($esito['message'], '2 rientrati a magazzino');
    });
});

check('un reso rotto non rientra, e il messaggio lo dice', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'returns']);
        [$ordine, $riga] = ordineVenduto(3);
        $esito = OrderReturnResource::run($ordine, moduloReso($riga, '1', 'damaged', ''), 1);

        return $esito['ok'] === true && str_contains($esito['message'], '1 pezzo') && str_contains($esito['message'], '0 rientrati');
    });
});

check('la spunta assente nel modulo vuol dire «non rientra»; senza il campo vale il motivo', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'returns']);
        [$ordine, $riga] = ordineVenduto(3);
        $senza = OrderReturnResource::run($ordine, ['lines' => [$riga => ['quantity' => '1', 'reason' => 'changed_mind']]], 1);

        return $senza['ok'] === true && str_contains($senza['message'], '1 rientrato');
    });
});

check('le quantità vuote non fanno un reso: la frase è quella di «nessuna riga»', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'returns']);
        [$ordine, $riga] = ordineVenduto(3);
        $esito = OrderReturnResource::run($ordine, moduloReso($riga, ''), 1);

        return $esito['ok'] === false && $esito['message'] !== '' && resiDi($ordine) === [];
    });
});

check('oltre il massimo il reso è rifiutato con la frase, e niente è scritto', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'returns']);
        [$ordine, $riga] = ordineVenduto(3);
        $esito = OrderReturnResource::run($ordine, moduloReso($riga, '4'), 1);

        return $esito['ok'] === false && str_contains($esito['message'], 'Crema da prova') && str_contains($esito['message'], '3')
            && resiDi($ordine) === [];
    });
});

check('lo stesso modulo inviato due volte: il secondo è rifiutato', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'returns']);
        [$ordine, $riga] = ordineVenduto(3);
        $modulo = moduloReso($riga, '3');
        $primo = OrderReturnResource::run($ordine, $modulo, 1);
        $secondo = OrderReturnResource::run($ordine, $modulo, 1);

        return $primo['ok'] === true && $secondo['ok'] === false && count(resiDi($ordine)) === 1;
    });
});

check('con la funzionalità spenta non si registra niente', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders']);
        sqlModify(Feature::$table, ['enabled' => 'false'], 'feature_key', 'returns');
        Gestionale::reset();
        [$ordine, $riga] = ordineVenduto(3);
        $esito = OrderReturnResource::run($ordine, moduloReso($riga, '1'), 1);

        return $esito['ok'] === false && resiDi($ordine) === [];
    });
});

check('un ordine che non c\'è o un carrello: «Ordine non trovato»', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'returns']);
        $carrello = Order::create(['stage' => 'cart', 'status' => 'draft', 'payment_status' => 'unpaid', 'total' => '5.00']);

        return OrderReturnResource::run(0, [], 1) === ['ok' => false, 'message' => 'Ordine non trovato.']
            && OrderReturnResource::run(999999999, [], 1)['message'] === 'Ordine non trovato.'
            && OrderReturnResource::run((int) ($carrello->insert_id ?? 0), [], 1)['message'] === 'Ordine non trovato.';
    });
});

check('«Chiudi» e «Annulla» passano da Returns, con la loro frase', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'returns']);
        [$ordine, $riga] = ordineVenduto(3);
        OrderReturnResource::run($ordine, moduloReso($riga, '1', 'damaged', ''), 1);
        OrderReturnResource::run($ordine, moduloReso($riga, '1', 'changed_mind', 'on'), 1);
        [$senzaRicarico, $conRicarico] = resiDi($ordine);

        $rientrato = OrderReturnResource::runStatus('cancel', (int) $conRicarico['id'], 1);
        $annullato = OrderReturnResource::runStatus('cancel', (int) $senzaRicarico['id'], 1);
        $chiuso = OrderReturnResource::runStatus('complete', (int) $conRicarico['id'], 1);
        $ancora = OrderReturnResource::runStatus('complete', (int) $conRicarico['id'], 1);
        $strano = OrderReturnResource::runStatus('delete', (int) $conRicarico['id'], 1);

        return $rientrato['ok'] === false && $annullato['ok'] === true && $chiuso['ok'] === true
            && $ancora['ok'] === false && $strano['ok'] === false
            && SalesReturn::findById((int) $senzaRicarico['id'])['status'] === 'cancelled'
            && SalesReturn::findById((int) $conRicarico['id'])['status'] === 'completed';
    });
});

check('il ritorno fuori dal backend è scartato', function () {
    return StockAdjustmentResource::backUrlFrom('https://altro.sito/') === ''
        && StockAdjustmentResource::backUrlFrom('//altro.sito/') === '';
});

check('la scheda offre «Registra reso» solo con la funzionalità accesa e prima di «Annulla»', function () {
    return prova(static function (): bool {
        [$ordine] = ordineVenduto(3);
        $etichette = static fn (): array => array_map(
            static fn (array $p): string => (string) $p['label'],
            OrderResource::actionsFor((array) Order::findById($ordine))
        );

        accendiFunzionalita(['orders', 'returns']);
        $acceso = $etichette();
        sqlModify(Feature::$table, ['enabled' => 'false'], 'feature_key', 'returns');
        Gestionale::reset();

        $voce = array_search('Registra reso', $acceso, true);

        return $voce !== false && $voce < array_search('Annulla', $acceso, true)
            && !in_array('Registra reso', $etichette(), true);
    });
});

check('il pulsante apre la pagina dell\'ordine e porta con sé il ritorno all\'elenco, non alla scheda', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'returns']);
        [$ordine] = ordineVenduto(3);
        $href = static function () use ($ordine): string {
            foreach (OrderResource::actionsFor((array) Order::findById($ordine)) as $p) {
                if ($p['label'] === 'Registra reso') {
                    return (string) $p['href'];
                }
            }

            return '';
        };

        unset($_GET['torna']);
        $senza = $href();
        $_GET['torna'] = '/backend/ordini/?page=2';
        $con = $href();
        unset($_GET['torna']);

        // Il ritorno è l'elenco: la scheda lo aggiunge da sé alla fine, e un
        // ritorno che fosse già la scheda si annida a ogni azione.
        return str_contains($senza, '?ordine='.$ordine) && !str_contains($senza, 'torna=') && !str_contains($senza, '#')
            && str_contains($con, '&torna='.rawurlencode('/backend/ordini/?page=2'))
            && !str_contains($con, rawurlencode(OrderResource::detailUrl($ordine)));
    });
});

check('Chiudi e Annulla dalla tabella tornano all\'elenco, non a una scheda dentro la scheda', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'returns']);
        [$ordine, $riga] = ordineVenduto(3);
        OrderReturnResource::run($ordine, moduloReso($riga, '1', 'changed_mind', ''), 1);
        $reso = resiDi($ordine)[0];
        $azioni = array_values(array_filter(
            OrderReturnTableResource::tableSchema(),
            static fn ($c): bool => (string) $c->name === 'actions'
        ))[0]->schema['formatter'];
        $_GET['torna'] = '/backend/ordini/?page=2';
        $html = $azioni($reso);
        unset($_GET['torna']);

        return str_contains($html, 'name="back" value="/backend/ordini/?page=2"')
            && !str_contains($html, OrderResource::detailUrl($ordine));
    });
});

check('la tabella dei resi dice le righe rese, con i pezzi', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'returns']);
        [$ordine, $riga] = ordineVenduto(3);
        OrderReturnResource::run($ordine, moduloReso($riga, '2'), 1);
        $reso = resiDi($ordine)[0];
        $righe = array_values(array_filter(
            OrderReturnTableResource::tableSchema(),
            static fn ($c): bool => (string) $c->name === 'lines'
        ))[0]->schema['formatter'];
        $testo = $righe($reso);

        return str_contains($testo, 'Crema da prova') && str_contains($testo, '2');
    });
});

check('la pagina mostra le righe dell\'ordine con quanto si può ancora rendere', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'returns']);
        [$ordine, $riga] = ordineVenduto(3);
        OrderReturnResource::run($ordine, moduloReso($riga, '1'), 1);
        $_GET['ordine'] = (string) $ordine;
        $html = OrderReturnResource::pageHtml($ordine);
        unset($_GET['ordine']);

        return str_contains($html, 'Crema da prova') && str_contains($html, 'name="lines['.$riga.'][quantity]"') && str_contains($html, 'max="2"');
    });
});

summary();
