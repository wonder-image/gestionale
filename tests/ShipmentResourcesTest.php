<?php
/** php tests/ShipmentResourcesTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Backend\Contracts\HomeWidget;
use Wonder\Plugin\Gestionale\Backend\Widgets\LowStockWidget;
use Wonder\Plugin\Gestionale\Backend\Widgets\ShipmentsToCheckWidget;
use Wonder\Plugin\Gestionale\Backend\Widgets\ShippedNotDeliveredWidget;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Shipping\Shipment;
use Wonder\Plugin\Gestionale\Resources\Shipping\ShipmentResource;
use Wonder\Plugin\Gestionale\Support\Orders\OrderActions;
use Wonder\Plugin\Gestionale\Support\Shipping\ShipmentAlerts;

/** Un ordine impegnato, come lo legge `OrderActions`. */
$ordine = static fn (string $evasione = 'unfulfilled', string $tipo = 'shipping', string $stato = 'confirmed'): array => [
    'status' => $stato,
    'fulfillment_status' => $evasione,
    'fulfillment_type' => $tipo,
];
$conSpedizioni = ['shipping' => true];

check('la pagina Spedizioni sta nella sezione Vendite, dietro la funzionalità, solo per l\'admin', function () {
    $menu = ShipmentResource::navigationSchema()->toArray();

    return ShipmentResource::$feature === 'shipping'
        && ShipmentResource::$model === Shipment::class
        && ShipmentResource::path() === 'app/gestionale/spedizioni'
        && ($menu['section_key'] ?? '') === 'vendite'
        && ($menu['authority'] ?? []) === ['admin', 'administrator']
        && str_starts_with(ShipmentResource::$docsPage, 'spedizioni/');
});

check('con la funzionalità spenta le pagine non ci sono e il menu le nasconde', function () {
    // Senza database la funzionalità risulta spenta.
    $pagine = ShipmentResource::pageSchema()->toArray()['pages'] ?? [];

    return ShipmentResource::featureActive() === false
        && ($menu = ShipmentResource::navigationSchema()->toArray())['enabled'] === false
        && !in_array(true, $pagine, true);
});

check('l\'elenco si legge e basta: la scheda c\'è, aggiungi modifica ed elimina no', function () {
    $stato = new ReflectionProperty(Gestionale::class, 'features');
    $prima = $stato->getValue();
    $stato->setValue(null, ['shipping' => true]);

    try {
        $pagine = array_keys(array_filter((array) (ShipmentResource::pageSchema()->toArray()['pages'] ?? [])));

        return in_array('list', $pagine, true) && in_array('view', $pagine, true)
            && array_intersect(['add', 'create', 'edit', 'delete'], $pagine) === [];
    } finally {
        $stato->setValue(null, $prima);
    }
});

check('le colonne dell\'elenco sono quelle dichiarate', function () {
    $colonne = array_map(static fn ($colonna): string => (string) $colonna->name, ShipmentResource::tableSchema());

    return $colonne === ['code', 'order_id', 'type', 'status', 'carrier_id', 'tracking_number', 'shipped_at', 'actions'];
});

check('l\'elenco si filtra per stato, tipo e corriere, e per situazione', function () {
    $filtri = array_map(
        static fn (array $filtro): string => (string) $filtro['column'],
        (array) (ShipmentResource::tableLayoutSchema()->toArray()['custom_filters'] ?? [])
    );

    return $filtri === ['status', 'type', 'carrier_id', 'situazione'];
});

check('il filtro Situazione ha le due condizioni dei riquadri della bacheca', function () {
    foreach ((array) (ShipmentResource::tableLayoutSchema()->toArray()['custom_filters'] ?? []) as $filtro) {
        if ($filtro['column'] !== 'situazione') {
            continue;
        }

        return array_keys($filtro['array']) === ['', 'da_controllare', 'non_consegnate']
            && is_callable($filtro['where'])
            && str_contains($filtro['where'](['da_controllare']), 'exception')
            && str_contains($filtro['where'](['da_controllare']), 'failed_attempt')
            && str_contains($filtro['where'](['non_consegnate']), 'shipped_at')
            && $filtro['where']([]) === '';
    }

    return false;
});

check('le sei azioni nuove hanno le loro parole', fn () =>
    OrderActions::label('create_shipment') === 'Crea spedizione'
    && OrderActions::label('ship') === 'Segna spedita'
    && OrderActions::label('deliver') === 'Segna consegnata'
    && OrderActions::label('cancel_shipment') === 'Annulla spedizione'
    && OrderActions::label('ready_for_pickup') === 'Pronto per il ritiro'
    && OrderActions::label('picked_up') === 'Ritirato'
);

check('con le spedizioni accese un ordine da spedire offre «Crea spedizione» e non «Segna evaso»', function () use ($ordine, $conSpedizioni) {
    return OrderActions::available($ordine(), $conSpedizioni) === ['create_shipment', 'cancel']
        && OrderActions::available($ordine('partially_fulfilled'), $conSpedizioni) === ['create_shipment', 'cancel']
        && OrderActions::available($ordine('fulfilled'), $conSpedizioni) === ['cancel'];
});

check('con le spedizioni spente resta «Segna evaso», come prima', function () use ($ordine) {
    return OrderActions::available($ordine(), ['shipping' => false]) === ['fulfill', 'cancel']
        && OrderActions::available($ordine(), []) === ['fulfill', 'cancel']
        && !in_array('create_shipment', OrderActions::available($ordine(), []), true);
});

check('un ritiro offre «Pronto per il ritiro» e poi «Ritirato»', function () use ($ordine, $conSpedizioni) {
    return OrderActions::available($ordine('unfulfilled', 'pickup'), $conSpedizioni) === ['ready_for_pickup', 'cancel']
        && OrderActions::available($ordine('ready_for_pickup', 'pickup'), $conSpedizioni) === ['picked_up', 'cancel']
        && OrderActions::available($ordine('fulfilled', 'pickup'), $conSpedizioni) === ['cancel'];
});

check('un ordine senza spedizione né ritiro si evade a mano anche con le spedizioni accese', function () use ($ordine, $conSpedizioni) {
    return OrderActions::available($ordine('unfulfilled', 'none'), $conSpedizioni) === ['fulfill', 'cancel'];
});

check('un ordine non impegnato non offre spedizioni', function () use ($ordine, $conSpedizioni) {
    return OrderActions::available($ordine('unfulfilled', 'shipping', 'pending'), $conSpedizioni) === ['confirm', 'cancel']
        && OrderActions::available($ordine('unfulfilled', 'shipping', 'cancelled'), $conSpedizioni) === [];
});

check('le azioni di una spedizione seguono il suo stato', function () {
    $di = static fn (string $tipo, string $stato): array => OrderActions::shipmentActions(['type' => $tipo, 'status' => $stato]);

    return $di('delivery', 'pending') === ['ship', 'cancel_shipment']
        && $di('delivery', 'label_created') === ['ship', 'cancel_shipment']
        && $di('delivery', 'in_transit') === ['deliver']
        && $di('delivery', 'out_for_delivery') === ['deliver']
        && $di('delivery', 'failed_attempt') === ['deliver']
        && $di('delivery', 'exception') === ['deliver']
        && $di('delivery', 'delivered') === []
        && $di('delivery', 'cancelled') === []
        && $di('pickup', 'pending') === ['ready_for_pickup', 'cancel_shipment']
        && $di('pickup', 'ready_for_pickup') === ['picked_up', 'cancel_shipment']
        && $di('pickup', 'picked_up') === [];
});

check('il tracking si legge come testo anche se porta HTML, e il link è cliccabile e sicuro', function () {
    $con = ShipmentResource::trackingHtml(['tracking_number' => '<b>AB</b>', 'tracking_url' => 'https://t.example.it/?c=%3Cb%3EAB']);
    $senza = ShipmentResource::trackingHtml(['tracking_number' => '<i>9</i>', 'tracking_url' => '']);
    $vuoto = ShipmentResource::trackingHtml(['tracking_number' => '', 'tracking_url' => '']);
    $falso = ShipmentResource::trackingHtml(['tracking_number' => 'X', 'tracking_url' => 'javascript:alert(1)']);

    return str_contains($con, '&lt;b&gt;AB&lt;/b&gt;') && !str_contains($con, '<b>')
        && str_contains($con, 'href="https://t.example.it/?c=%3Cb%3EAB"')
        && str_contains($con, 'rel="noopener')
        && str_contains($con, 'target="_blank"')
        && str_contains($senza, '&lt;i&gt;9&lt;/i&gt;') && !str_contains($senza, '<a ')
        && $vuoto === '—'
        && !str_contains($falso, '<a ');
});

check('i due riquadri della bacheca sono per l\'admin e stanno dopo «Sotto scorta»', function () {
    $widgets = (require __DIR__ . '/../config/module.php')['backend']['home_widgets'];
    $dopo = array_search(LowStockWidget::class, $widgets, true);

    return is_subclass_of(ShipmentsToCheckWidget::class, HomeWidget::class)
        && is_subclass_of(ShippedNotDeliveredWidget::class, HomeWidget::class)
        && (new ShipmentsToCheckWidget)->authorities() === ['admin', 'administrator']
        && (new ShippedNotDeliveredWidget)->authorities() === ['admin', 'administrator']
        && (new ShipmentsToCheckWidget)->order() > (new LowStockWidget)->order()
        && (new ShippedNotDeliveredWidget)->order() > (new ShipmentsToCheckWidget)->order()
        && array_search(ShipmentsToCheckWidget::class, $widgets, true) === $dopo + 1
        && array_search(ShippedNotDeliveredWidget::class, $widgets, true) === $dopo + 2;
});

check('a zero i riquadri non disegnano niente; con dei casi dicono quanti e aprono l\'elenco filtrato', function () {
    $uno = ShipmentsToCheckWidget::markup(1);
    $tre = ShipmentsToCheckWidget::markup(3);
    $vecchie = ShippedNotDeliveredWidget::markup(2);

    return ShipmentsToCheckWidget::markup(0) === ''
        && ShippedNotDeliveredWidget::markup(0) === ''
        && str_contains($uno, '1 spedizione')
        && str_contains($tre, '3 spedizioni')
        && str_contains($tre, 'gst_shipments__situazione=da_controllare')
        && str_contains($vecchie, '2 spedizioni')
        && str_contains($vecchie, (string) ShipmentAlerts::STALE_DAYS.' giorni')
        && str_contains($vecchie, 'gst_shipments__situazione=non_consegnate');
});

check('con la funzionalità spenta i riquadri non leggono niente e non disegnano niente', function () {
    // Senza database: se provassero a contare, lancerebbero.
    return (new ShipmentsToCheckWidget)->render() === '' && (new ShippedNotDeliveredWidget)->render() === '';
});

check('il numero di giorni è sette, costante del modulo', fn () => ShipmentAlerts::STALE_DAYS === 7);

check('l\'elenco si cerca anche per numero d\'ordine, oltre che per codice e tracking', function () {
    $campi = (array) (ShipmentResource::tableLayoutSchema()->toArray()['search_fields'] ?? []);
    $relazione = null;

    foreach ($campi as $campo) {
        if (is_array($campo)) {
            $relazione = $campo;
        }
    }

    return in_array('code', $campi, true) && in_array('tracking_number', $campi, true)
        && is_array($relazione)
        && ($relazione['local_key'] ?? '') === 'order_id' && ($relazione['foreign_key'] ?? '') === 'id'
        && ($relazione['columns'] ?? []) === ['order_number'];
});

check('il menu ⋯ della riga porta a «Cambia stato» e «Tracking e corriere» della scheda', function () {
    foreach (ShipmentResource::tableSchema() as $colonna) {
        if ((string) $colonna->name !== 'actions') {
            continue;
        }

        $azioni = (array) $colonna->getSchema('actions');
        $stato = (array) ($azioni['stato'] ?? []);
        $tracking = (array) ($azioni['tracking'] ?? []);

        return ($stato['label'] ?? '') === 'Cambia stato'
            && ($tracking['label'] ?? '') === 'Tracking e corriere'
            && str_contains((string) ($stato['href'] ?? ''), '{id}') && str_contains((string) ($stato['href'] ?? ''), 'apri=modifica')
            && str_contains((string) ($tracking['href'] ?? ''), '{id}') && str_contains((string) ($tracking['href'] ?? ''), 'apri=modifica')
            && ($stato['filter']['row']['status'] ?? []) === ['pending', 'label_created', 'in_transit', 'out_for_delivery', 'failed_attempt', 'exception', 'ready_for_pickup']
            && ($tracking['filter']['row']['type'] ?? '') === 'delivery'
            && !in_array('cancelled', (array) ($tracking['filter']['row']['status'] ?? []), true);
    }

    return false;
});

check('modificare la spedizione vale finché ha ancora qualcosa da dire: non per le chiuse o annullate', function () {
    $puo = static fn (string $tipo, string $stato): bool => OrderActions::canEditShipment(['type' => $tipo, 'status' => $stato]);

    return $puo('delivery', 'pending') && $puo('delivery', 'in_transit') && $puo('delivery', 'delivered')
        && !$puo('delivery', 'cancelled') && !$puo('delivery', 'returned')
        && $puo('pickup', 'pending') && $puo('pickup', 'ready_for_pickup')
        && !$puo('pickup', 'picked_up') && !$puo('pickup', 'cancelled');
});

summary();
