<?php
/** php tests/OrderResourceTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderResource;

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
    $colonne === ['order_number', 'ordered_at', 'customer', 'total', 'status', 'payment_status', 'fulfillment_status']
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
        && str_contains($html, '10,00 €') && str_contains($html, 'a@b.it');
});

/** Il testo di un componente: titolo di riquadro o HTML del contenuto. */
$testoDi = static function (object $componente): string {
    $r = new ReflectionProperty($componente, 'text');

    return (string) $r->getValue($componente);
};

check('la scheda ha sette riquadri, nell\'ordine della spec', function () use ($testoDi) {
    $layout = OrderResource::showLayoutSchema(['id' => 0, 'order_number' => '2025/001']);
    $titoli = [];

    foreach ($layout->components as $card) {
        $titoli[] = $testoDi($card->components[0]);
    }

    return $titoli === ['Ordine 2025/001', 'Righe', 'Riepilogo IVA', 'Totali', 'Pagamenti', 'Resi', 'Storico'];
});

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

check('la scheda ha la pagina «view» e il pulsante Elenco', function () use ($pagine) {
    $item = OrderResource::actionsFor(['id' => 1]);

    return !empty($pagine['pages']['view']) && ($item[0]['label'] ?? '') === 'Elenco';
});

summary();
