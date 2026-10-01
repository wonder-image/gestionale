<?php
/** php tests/OrderResourceTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderActionResource;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderPaymentResource;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderResource;
use Wonder\Plugin\Gestionale\Support\Stock\MovementPeriod;

$pagine = OrderResource::pageSchema()->toArray();
$colonne = [];

foreach (OrderResource::tableSchema() as $colonna) {
    $colonne[] = (string) $colonna->name;
}

$filtro = static function (string $column): array {
    $schema = OrderResource::tableLayoutSchema()->toArray();

    foreach ((array) ($schema['custom_filters'] ?? []) as $f) {
        if (($f['column'] ?? '') === $column) {
            return array_keys((array) ($f['array'] ?? []));
        }
    }

    return [];
};

check('l\'elenco degli ordini ha il suo indirizzo e il suo model', fn () =>
    OrderResource::path() === 'app/gestionale/ordini' && OrderResource::$model === Order::class
);

check('gli ordini stanno nella funzionalità «orders»', fn () => OrderResource::$feature === 'orders');

check('gli ordini si leggono e basta: niente aggiungi, modifica o elimina', function () use ($pagine) {
    $attive = array_keys(array_filter((array) ($pagine['pages'] ?? [])));

    return !in_array('add', $attive, true) && !in_array('edit', $attive, true) && !in_array('delete', $attive, true);
});

check('la voce sta nella sezione Vendite, riservata all\'amministratore', function () {
    $nav = OrderResource::navigationSchema()->toArray();

    return ($nav['section_key'] ?? '') === 'vendite' && ($nav['authority'] ?? []) === ['admin', 'administrator'];
});

check('i permessi dell\'elenco sono di admin e administrator', function () {
    $json = (string) json_encode(OrderResource::permissionSchema()->toArray());

    return str_contains($json, 'admin') && str_contains($json, 'administrator');
});

check('l\'API è spenta', fn () => (bool) (OrderResource::apiSchema()->toArray()['enabled'] ?? true) === false);

check('le colonne sono quelle dell\'elenco', fn () =>
    $colonne === ['order_number', 'customer', 'total', 'ordered_at', 'status', 'payment_status', 'fulfillment_status', 'actions']
);

check('l\'elenco mostra solo gli ordini veri, non i carrelli', function () {
    $condizione = (string) (OrderResource::querySchema()['condition'] ?? '');

    return str_contains($condizione, "stage = 'order'") && str_contains($condizione, "deleted = 'false'");
});

check('i filtri usano gli stati del Model', fn () =>
    array_diff($filtro('payment_status'), ['', ...Order::PAYMENT_STATUSES]) === []
    && count($filtro('payment_status')) === count(Order::PAYMENT_STATUSES) + 1
    && array_diff($filtro('fulfillment_status'), ['', ...Order::FULFILLMENT_STATUSES]) === []
    && array_diff($filtro('status'), ['', ...Order::STATUSES]) === []
    && $filtro('status') !== []
);

check('il filtro Periodo dell\'elenco offre gli stessi periodi dei movimenti e usa la data dell\'ordine', function () {
    foreach ((array) (OrderResource::tableLayoutSchema()->toArray()['custom_filters'] ?? []) as $f) {
        if (($f['column'] ?? '') === 'periodo') {
            return array_keys((array) $f['array']) === ['', ...array_keys(MovementPeriod::OPTIONS)]
                && str_contains(($f['where'])(['oggi']), '`ordered_at` >= ')
                && ($f['where'])(['']) === ''
                && ($f['where'])(["oggi'; DROP TABLE x; --"]) === '';
        }
    }

    return false;
});

check('il cliente si riconosce: ragione sociale, poi nome e cognome, poi email', fn () =>
    OrderResource::customerName(['billing_business_name' => 'Acme Srl', 'billing_name' => 'Anna']) === 'Acme Srl'
    && OrderResource::customerName(['billing_name' => 'Anna', 'billing_surname' => 'Verdi']) === 'Anna Verdi'
    && OrderResource::customerName(['email' => 'a@b.it']) === 'a@b.it'
    && OrderResource::customerName([]) === '—'
);

check('importi e date all\'italiana', fn () =>
    OrderResource::money('1234.5') === '1.234,50 €'
    && OrderResource::date('2025-10-15 10:30:00') === '15/10/2025 10:30'
    && OrderResource::date('') === '—'
);

check('le celle si disegnano davvero, con le etichette', function () {
    $riga = ['order_number' => '2025/001', 'ordered_at' => '2025-10-15 10:30:00', 'total' => '10.00',
        'status' => 'pending', 'payment_status' => 'paid', 'fulfillment_status' => 'unfulfilled', 'email' => 'a@b.it'];
    $html = '';

    foreach (OrderResource::tableSchema() as $colonna) {
        $f = $colonna->getSchema('formatter');
        $html .= is_callable($f) ? (string) $f($riga) : '';
    }

    return str_contains($html, 'In attesa') && str_contains($html, 'Pagato') && str_contains($html, 'Da evadere')
        && str_contains($html, 'a@b.it');
});

check('il totale è una colonna importo e la data una colonna data', function () {
    $tipi = [];

    foreach (OrderResource::tableSchema() as $colonna) {
        $tipi[(string) $colonna->name] = $colonna->toArray()['type'] ?? null;
    }

    return $tipi['total'] === 'money' && $tipi['ordered_at'] === 'date' && $tipi['order_number'] === 'text';
});

/** Il testo di un componente: titolo di riquadro o HTML del contenuto. */
$testoDi = static function (object $componente): string {
    $r = new ReflectionProperty($componente, 'text');

    return (string) $r->getValue($componente);
};

check('la scheda: intestazione, righe, riepilogo IVA, totali, pagamenti, resi (se attivi) e storico', function () use ($testoDi) {
    $layout = OrderResource::showLayoutSchema(['id' => 0, 'order_number' => '2025/001']);
    $titoli = [];

    foreach ($layout->components as $c) {
        $titoli[] = $c instanceof Wonder\Elements\Components\Accordion
            ? $testoDi($c)
            : ($c->components[0] instanceof Wonder\Elements\Components\SectionTitle ? $testoDi($c->components[0]) : 'Intestazione');
    }

    $attesi = ['Intestazione', 'Righe', 'Riepilogo IVA', 'Totali', 'Pagamenti'];

    if (Wonder\Plugin\Gestionale\Gestionale::feature('returns')) {
        $attesi[] = 'Resi';
    }

    $attesi[] = 'Storico';

    return $titoli === $attesi;
});

check('righe, pagamenti, resi e storico sono accordion; solo le righe partono aperte', function () use ($testoDi) {
    $layout = OrderResource::showLayoutSchema(['id' => 0]);
    $aperti = [];

    foreach ($layout->components as $c) {
        if ($c instanceof Wonder\Elements\Components\Accordion) {
            $aperti[$testoDi($c)] = (bool) ($c->getSchema('expanded') ?? false);
        }
    }

    return ($aperti['Righe'] ?? false) === true && ($aperti['Pagamenti'] ?? true) === false && ($aperti['Storico'] ?? true) === false;
});

check('la scheda non ripete il titolo «Ordine» nell\'intestazione', function () use ($testoDi) {
    $layout = OrderResource::showLayoutSchema(['id' => 0, 'order_number' => '2025/001']);
    $primo = $layout->components[0]->components[0];

    return !($primo instanceof Wonder\Elements\Components\SectionTitle);
});

check('il cliente è un link alla sua scheda solo se l\'ordine ne ha uno', fn () =>
    OrderResource::customerUrl(['customer_id' => 0]) === ''
    && str_contains(OrderResource::customerUrl(['customer_id' => 7]), '/7/edit')
);

check('la scheda è in sola lettura: nessun campo da compilare', function () use ($testoDi) {
    $layout = OrderResource::showLayoutSchema(['id' => 0]);

    foreach ($layout->components as $card) {
        foreach ($card->components as $c) {
            if (str_contains(strtolower($c::class), 'input') || str_contains(strtolower($c::class), 'formfield')) {
                return false;
            }

            if ($c instanceof Wonder\Elements\Components\RichText && preg_match('/<(input|textarea|select|form)\b/i', $testoDi($c)) === 1) {
                return false;
            }
        }
    }

    return true;
});

check('la scheda ha la pagina «view» e nessun pulsante Elenco: per tornare c\'è la chevron', function () use ($pagine) {
    return !empty($pagine['pages']['view']) && OrderResource::actionsFor(['id' => 1]) === [];
});

check('il titolo della scheda è «Ordine» e il numero, e non resta vuoto senza numero', fn () =>
    OrderResource::pageTitle(['order_number' => '2025/001']) === 'Ordine 2025/001'
    && OrderResource::pageTitle(['order_number' => '  ']) === 'Ordine'
    && OrderResource::pageTitle([]) === 'Ordine'
);

check('un ordine in attesa offre Conferma e Annulla, nei pulsanti', function () {
    $pulsanti = OrderResource::actionsFor(['id' => 9, 'status' => 'pending', 'fulfillment_status' => 'unfulfilled', 'payment_status' => 'paid']);
    $nomi = array_map(static fn (array $p): string => (string) $p['label'], $pulsanti);

    return $nomi === ['Conferma', 'Annulla']
        && str_contains((string) $pulsanti[0]['onclick'], OrderResource::actionModalId('confirm'))
        && str_contains((string) $pulsanti[1]['onclick'], OrderResource::actionModalId('cancel'));
});

check('un ordine confermato offre Segna evaso e Annulla', function () {
    $pulsanti = OrderResource::actionsFor(['id' => 9, 'status' => 'confirmed', 'fulfillment_status' => 'unfulfilled', 'payment_status' => 'paid']);

    return array_map(static fn (array $p): string => (string) $p['label'], $pulsanti) === ['Segna evaso', 'Annulla'];
});

check('un ordine da incassare offre Registra pagamento, prima di Annulla, e lo apre in una finestra', function () {
    $ordine = ['id' => 9, 'status' => 'confirmed', 'fulfillment_status' => 'unfulfilled', 'payment_status' => 'unpaid'];
    $pulsanti = OrderResource::actionsFor($ordine);
    $nomi = array_map(static fn (array $p): string => (string) $p['label'], $pulsanti);

    return $nomi === ['Segna evaso', 'Registra pagamento', 'Annulla']
        && str_contains((string) $pulsanti[1]['onclick'], OrderPaymentResource::MODAL_ID)
        && ($pulsanti[1]['href'] ?? '') === '#';
});

check('la finestra del pagamento posta a Registra pagamento con ordine e ritorno, e propone il residuo', function () {
    $html = OrderPaymentResource::modal(
        ['id' => 9, 'status' => 'confirmed', 'payment_status' => 'partially_paid', 'total' => '100.00', 'order_number' => '2026/0009', 'payment_method_id' => 2],
        [['type' => 'payment', 'status' => 'paid', 'amount' => '40.00']],
        [1 => 'Bonifico', 2 => 'Contanti'],
        '/backend/ordini/'
    );

    return str_contains($html, 'id="'.OrderPaymentResource::MODAL_ID.'"')
        && str_contains($html, 'method="post"')
        && str_contains($html, 'action="'.htmlspecialchars(OrderPaymentResource::submitUrl(), ENT_QUOTES).'"')
        && str_contains($html, 'name="order_id" value="9"')
        && str_contains($html, 'name="back" value="/backend/ordini/"')
        && str_contains($html, 'name="amount"') && str_contains($html, 'value="60,00"')
        && str_contains($html, '<option value="2" selected>Contanti</option>')
        && str_contains($html, '<option value="1">Bonifico</option>')
        && str_contains($html, 'name="paid_at"') && str_contains($html, 'value="'.date('Y-m-d').'"')
        && str_contains($html, 'name="reference"')
        && str_contains($html, '2026/0009');
});

check('nella finestra i campi obbligatori sono marcati per il backend e il testo del cliente è escapato', function () {
    $html = OrderPaymentResource::modal(['id' => 9, 'total' => '10.00', 'order_number' => '<i>x</i>'], [], [1 => '<b>M</b>'], '');

    return substr_count($html, 'data-wi-check="true"') >= 2
        && !str_contains($html, '<i>x</i>') && !str_contains($html, '<b>M</b>');
});

check('la finestra del pagamento si disegna solo se l\'ordine si può incassare', function () {
    $da = OrderResource::actionModals(['id' => 9, 'status' => 'confirmed', 'fulfillment_status' => 'fulfilled', 'payment_status' => 'unpaid', 'total' => '10.00'], [], [], '');
    $saldato = OrderResource::actionModals(['id' => 9, 'status' => 'confirmed', 'fulfillment_status' => 'fulfilled', 'payment_status' => 'paid', 'total' => '10.00'], [], [], '');
    $annullato = OrderResource::actionModals(['id' => 9, 'status' => 'cancelled', 'payment_status' => 'unpaid'], [], [], '');

    return str_contains($da, OrderPaymentResource::MODAL_ID)
        && !str_contains($saldato, OrderPaymentResource::MODAL_ID)
        && $annullato === '';
});

check('un ordine saldato o annullato non offre Registra pagamento', function () {
    foreach ([['confirmed', 'paid'], ['cancelled', 'unpaid'], ['completed', 'partially_paid']] as [$stato, $pagamento]) {
        foreach (OrderResource::actionsFor(['id' => 9, 'status' => $stato, 'fulfillment_status' => 'fulfilled', 'payment_status' => $pagamento]) as $p) {
            if ($p['label'] === 'Registra pagamento') {
                return false;
            }
        }
    }

    return true;
});

check('un ordine annullato o chiuso non ha pulsanti', function () {
    foreach (['cancelled', 'completed'] as $stato) {
        if (OrderResource::actionsFor(['id' => 9, 'status' => $stato, 'fulfillment_status' => 'fulfilled']) !== []) {
            return false;
        }
    }

    return true;
});

check('le finestre postano all\'azione con ordine, azione e ritorno, e dicono cosa faranno', function () {
    $html = OrderResource::actionModals(
        ['id' => 9, 'status' => 'pending', 'fulfillment_status' => 'unfulfilled', 'order_number' => '2026/0009'],
        [['type' => 'product', 'quantity' => '2.000']],
        [],
        '/backend/ordini/'
    );

    return str_contains($html, 'method="post"')
        && str_contains($html, 'action="'.htmlspecialchars(Wonder\Plugin\Gestionale\Resources\Sales\OrderActionResource::submitUrl(), ENT_QUOTES).'"')
        && str_contains($html, 'name="order_id" value="9"')
        && str_contains($html, 'name="action" value="confirm"')
        && str_contains($html, 'name="action" value="cancel"')
        && !str_contains($html, 'value="fulfill"')
        && str_contains($html, 'name="torna" value="/backend/ordini/"')
        && str_contains($html, 'scarica 2 pezzi')
        && str_contains($html, 'libera 2 pezzi prenotati');
});

check('la finestra di Annulla ricorda il denaro già incassato', function () {
    $html = OrderResource::actionModals(
        ['id' => 9, 'status' => 'confirmed', 'fulfillment_status' => 'unfulfilled', 'order_number' => 'X'],
        [['type' => 'product', 'quantity' => '1.000']],
        [['amount' => '40.00', 'type' => 'payment', 'status' => 'paid'], ['amount' => '10.00', 'type' => 'refund', 'status' => 'paid']],
        ''
    );

    return str_contains($html, 'non si rimborsa da qui') && str_contains($html, '30,00');
});

check('un ordine senza azioni non porta finestre', fn () =>
    OrderResource::actionModals(['id' => 9, 'status' => 'cancelled', 'fulfillment_status' => 'unfulfilled'], [], [], '') === ''
);

/** I permessi di un'area backend, come li legge il registro delle rotte. */
$permessi = static fn (string $resource): array => (array) ($resource::permissionSchema()->toArray()['backend'] ?? []);

check('la scheda dell\'ordine è riservata come l\'elenco: admin e administrator', fn () =>
    ($permessi(OrderResource::class)['view'] ?? []) === ['admin', 'administrator']
);

check('le azioni e il pagamento (pagine-form: edit e update) sono di admin e administrator', function () use ($permessi) {
    foreach ([OrderActionResource::class, OrderPaymentResource::class] as $resource) {
        foreach (['edit', 'update'] as $azione) {
            if (($permessi($resource)[$azione] ?? []) !== ['admin', 'administrator']) {
                return false;
            }
        }
    }

    return true;
});

check('un importo senza virgola con i punti delle migliaia vale migliaia: 1.250 sono milleduecentocinquanta', fn () =>
    OrderPaymentResource::amountFrom('1.250') === 1250.0
    && OrderPaymentResource::amountFrom('1.250.000') === 1250000.0
    && OrderPaymentResource::amountFrom('1.250,50') === 1250.5
    && OrderPaymentResource::amountFrom('12.50') === 12.5
    && OrderPaymentResource::amountFrom('0.500') === 0.5
    && OrderPaymentResource::amountFrom('12,50') === 12.5
);

summary();
