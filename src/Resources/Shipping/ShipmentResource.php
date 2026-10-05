<?php

namespace Wonder\Plugin\Gestionale\Resources\Shipping;

use Throwable;
use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\App\ResourceSchema\TableLayoutSchema;
use Wonder\Elements\Components\Accordion;
use Wonder\Elements\Components\Button;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\DataItem;
use Wonder\Elements\Components\Modal;
use Wonder\Elements\Components\RichText;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Shipping\Carrier;
use Wonder\Plugin\Gestionale\Models\Shipping\Shipment;
use Wonder\Plugin\Gestionale\Models\Shipping\ShipmentItem;
use Wonder\Plugin\Gestionale\Models\Shipping\ShipmentStatusLog;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderActionResource;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderResource;
use Wonder\Plugin\Gestionale\Resources\Stock\StockAdjustmentResource;
use Wonder\Plugin\Gestionale\Support\Orders\OrderActions;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;
use Wonder\Plugin\Gestionale\Support\Orders\StatusLabels;
use Wonder\Plugin\Gestionale\Support\Shipping\Carriers;
use Wonder\Plugin\Gestionale\Support\Shipping\ShipmentAlerts;
use Wonder\Plugin\Gestionale\Support\Shipping\Shipments;

/**
 * «Spedizioni»: tutti i pacchi (e i ritiri) di tutti gli ordini, in sola
 * consultazione.
 *
 * Una spedizione non si scrive da qui: nasce e cambia con le azioni
 * dell'ordine e della sua scheda, che passano da `Shipments`. Qui si guarda
 * cosa è partito, cosa è fermo e si segue il pacco dal link del corriere. I
 * riquadri della bacheca aprono questo elenco già filtrato.
 */
final class ShipmentResource extends GestionaleResource
{
    public static string $model = Shipment::class;
    public static string $feature = 'shipping';
    public static string $orderColumn = 'id';
    public static string $orderDirection = 'DESC';
    public static string $docsPage = 'spedizioni/spedizioni-spedire';

    public static function path(): string
    {
        return 'app/gestionale/spedizioni';
    }

    public static function icon(): string
    {
        return 'bi-box-seam';
    }

    public static function titleLabel(): string
    {
        return 'Spedizioni';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'spedizione',
            'plural_label' => 'spedizioni',
            'last' => 'ultime',
            'all' => 'tutte',
            'article' => 'le',
            'this' => 'questa',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'code' => 'Codice',
            'order_id' => 'Ordine',
            'type' => 'Tipo',
            'status' => 'Stato',
            'carrier_id' => 'Corriere',
            'tracking_number' => 'Tracking',
            'shipped_at' => 'Partita il',
        ];
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('code')->text()->size('little')->link('view'),
            TableColumn::key('order_id')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::orderLink((int) ($row['order_id'] ?? 0))),
            TableColumn::key('type')
                ->text()
                ->size('little')
                ->hiddenDevice('mobile')
                ->formatter(static fn (array $row): string => static::escape(static::typeLabel((string) ($row['type'] ?? '')))),
            TableColumn::key('status')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => StatusLabels::badge('shipment', (string) ($row['status'] ?? ''))),
            TableColumn::key('carrier_id')
                ->text()
                ->hiddenDevice('mobile')
                ->formatter(static fn (array $row): string => static::escape(static::carrierName((int) ($row['carrier_id'] ?? 0)))),
            TableColumn::key('tracking_number')
                ->text()
                ->hiddenDevice('mobile')
                ->formatter(static fn (array $row): string => static::trackingHtml($row)),
            TableColumn::key('shipped_at')
                ->text()
                ->size('medium')
                ->hiddenDevice('mobile')
                ->formatter(static fn (array $row): string => static::escape(OrderSheet::date((string) ($row['shipped_at'] ?? '')))),
            TableColumn::key('actions')->button()->actions(['view']),
        ];
    }

    public static function tableLayoutSchema(): TableLayoutSchema
    {
        $stati = ['' => 'Tutti'];

        foreach (Shipment::statuses() as $stato) {
            $stati[$stato] = StatusLabels::shipment($stato)['label'];
        }

        return TableLayoutSchema::for(static::class)
            ->title('Spedizioni')
            ->results()
            ->hideButtonAdd()
            ->filterSearch()
            ->searchFields(['code', 'tracking_number'])
            ->filterCustom('Stato', 'status', $stati)
            ->filterCustom('Tipo', 'type', ['' => 'Tutti', 'delivery' => 'Consegna', 'pickup' => 'Ritiro'])
            ->filterCustom('Corriere', 'carrier_id', ['' => 'Tutti'] + static::carrierOptions())
            ->filterQuery(
                'Situazione',
                'situazione',
                ['' => 'Tutte', 'da_controllare' => 'Da controllare', 'non_consegnate' => 'In viaggio da troppo'],
                static fn (array $values): string => match ($values[0] ?? '') {
                    'da_controllare' => ShipmentAlerts::toCheckCondition(),
                    'non_consegnate' => ShipmentAlerts::notDeliveredCondition(),
                    default => '',
                }
            );
    }

    public static function pageSchema(): PageSchema
    {
        return static::withFeature(parent::pageSchema()
            ->only(['list'])
            ->enable(['view'])
            ->titles(['list' => 'Spedizioni', 'view' => 'Spedizione'])
            ->subtitles(['list' => 'I pacchi e i ritiri di tutti gli ordini. Si consultano qui; ogni cambio passa dalle azioni dell\'ordine o della scheda.'])
            ->view('show', Gestionale::viewPath('pages/shipment-show.php')));
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->backend(['list', 'view'], ['admin', 'administrator']);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return parent::navigationSchema()
            ->inSection('spedizioni')
            ->title('Spedizioni')
            ->order(5)
            ->authority(['admin', 'administrator']);
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
    }

    /** Solo le righe vive: una spedizione cancellata non si elenca. @return array<string, mixed> */
    public static function querySchema(): array
    {
        $schema = parent::querySchema();
        $schema['condition'] = "deleted = 'false'";

        return $schema;
    }

    /** L'indirizzo dell'elenco, dalla rotta con il nome; il percorso è il ripiego. */
    public static function listUrl(): string
    {
        return static::namedUrl('list', '/backend/'.static::path().'/');
    }

    /** L'elenco già filtrato: la «situazione» è quella dei riquadri della bacheca. */
    public static function filteredUrl(string $situazione): string
    {
        return static::listUrl().'?'.Shipment::$table.'__situazione='.rawurlencode($situazione);
    }

    /** L'indirizzo della scheda di una spedizione. */
    public static function detailUrl(int $id, ?string $back = null): string
    {
        $url = static::namedUrl('view', '/backend/'.static::path().'/'.$id.'/', ['id' => $id]);

        return $back === null || $back === '' ? $url : $url.'?torna='.rawurlencode($back);
    }

    /** @param array<string, mixed> $params */
    private static function namedUrl(string $action, string $fallback, array $params = []): string
    {
        if (!function_exists('__r')) {
            return $fallback;
        }

        try {
            $named = (string) __r('backend.resource.'.static::slug().'.'.$action, $params);
        } catch (Throwable) {
            return $fallback;
        }

        return $named !== '' ? $named : $fallback;
    }

    public static function typeLabel(string $type): string
    {
        return $type === 'pickup' ? 'Ritiro' : 'Consegna';
    }

    /** Il nome del corriere; vuoto se non c'è. */
    public static function carrierName(int $id): string
    {
        static $names = [];

        if ($id <= 0) {
            return '';
        }

        if (!array_key_exists($id, $names)) {
            try {
                $row = Carrier::findById($id);
                $names[$id] = is_array($row) ? html_entity_decode((string) ($row['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
            } catch (Throwable) {
                $names[$id] = '';
            }
        }

        return $names[$id];
    }

    /** @return array<int, string> */
    private static function carrierOptions(): array
    {
        try {
            $options = Carriers::options();
        } catch (Throwable) {
            $options = [];
        }

        return array_map(static fn (string $name): string => html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $options);
    }

    /** Il numero d'ordine, link alla scheda. */
    private static function orderLink(int $orderId): string
    {
        if ($orderId <= 0) {
            return '—';
        }

        try {
            $order = Order::findById($orderId);
        } catch (Throwable) {
            $order = null;
        }

        $number = is_array($order) ? trim((string) ($order['order_number'] ?? '')) : '';
        $label = static::escape($number !== '' ? $number : '#'.$orderId);

        return '<a href="'.static::escape(OrderResource::detailUrl($orderId)).'">'.$label.'</a>';
    }

    /**
     * Il tracking: sempre testo, mai markup; un link che si apre in un'altra
     * scheda solo se l'indirizzo è davvero `http(s)`. Senza numero, un trattino.
     *
     * @param array<string, mixed> $shipment
     */
    public static function trackingHtml(array $shipment): string
    {
        $numero = trim(html_entity_decode((string) ($shipment['tracking_number'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($numero === '') {
            return '—';
        }

        $testo = static::escape($numero);
        $url = trim(html_entity_decode((string) ($shipment['tracking_url'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return $testo;
        }

        return '<a href="'.static::escape($url).'" target="_blank" rel="noopener noreferrer">'.$testo.'</a>';
    }

    // ── Azioni di una spedizione ─────────────────────────────────────────

    public static function shipModalId(int $shipmentId): string
    {
        return 'wi-spedizione-spedisci-'.$shipmentId;
    }

    /**
     * I pulsanti di una spedizione secondo il suo stato: «Segna spedita» apre
     * la sua finestra (corriere e tracking), gli altri sono piccoli moduli che
     * postano a `OrderActionResource`, l'unica porta delle azioni.
     *
     * @param array<string, mixed> $shipment
     */
    public static function actionsHtml(array $shipment, string $back = ''): string
    {
        $id = (int) ($shipment['id'] ?? 0);
        $html = [];

        foreach (OrderActions::shipmentActions($shipment) as $azione) {
            if ($azione === OrderActions::SHIP) {
                $html[] = '<button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#'
                    .static::escape(static::shipModalId($id)).'">'.static::escape(OrderActions::label($azione)).'</button>';

                continue;
            }

            $pulsante = Button::post(OrderActionResource::submitUrl(), OrderActions::label($azione))
                ->hidden([
                    'order_id' => (int) ($shipment['order_id'] ?? 0),
                    'shipment_id' => $id,
                    'action' => $azione,
                    'torna' => $back,
                ])
                ->variant(OrderActions::variant($azione))
                ->outline()
                ->size('sm')
                ->schema('inline', true)
                ->formAttributes(['class' => 'd-inline']);

            if ($azione === OrderActions::CANCEL_SHIPMENT) {
                $pulsante = $pulsante->confirm(
                    'Annulli questa spedizione? Le quantità tornano da spedire.',
                    title: 'Annulla spedizione',
                    ok: 'Annulla spedizione',
                    variant: 'danger'
                );
            }

            $html[] = $pulsante->render('bootstrap');
        }

        return implode(' ', $html);
    }

    /**
     * Le finestre di «Segna spedita» di queste spedizioni: una ciascuna, con
     * il corriere e il tracking da scrivere.
     *
     * @param list<array<string, mixed>> $shipments
     */
    public static function modals(array $shipments, string $back = ''): string
    {
        $html = '';
        $corrieri = null;

        foreach ($shipments as $shipment) {
            if (!in_array(OrderActions::SHIP, OrderActions::shipmentActions($shipment), true)) {
                continue;
            }

            $corrieri ??= static::carrierOptions();
            $id = (int) ($shipment['id'] ?? 0);

            $html .= Modal::make(trim('Segna spedita: '.(string) ($shipment['code'] ?? '')))
                ->id(static::shipModalId($id))
                ->form(OrderActionResource::submitUrl(), hidden: [
                    'order_id' => (int) ($shipment['order_id'] ?? 0),
                    'shipment_id' => $id,
                    'action' => OrderActions::SHIP,
                    'torna' => $back,
                ])
                ->columns(12)
                ->components([
                    FormField::key('carrier_id')->select(['0' => 'Scegli il corriere'] + $corrieri)
                        ->label('Corriere')->value((string) ((int) ($shipment['carrier_id'] ?? 0)))->columnSpan(6),
                    FormField::key('tracking_number')->text()->label('Tracking')
                        ->value((string) ($shipment['tracking_number'] ?? ''))->columnSpan(6),
                ])
                ->cancel('Indietro')
                ->submit(OrderActions::label(OrderActions::SHIP), 'primary')
                ->render('bootstrap');
        }

        return $html;
    }

    /**
     * La finestra di «Crea spedizione»: una quantità per riga ancora da
     * spedire (già piena), il corriere, il tracking e cosa fare dopo.
     *
     * @param array<string, mixed> $order
     */
    public static function createModal(array $order, string $back = ''): string
    {
        $orderId = (int) ($order['id'] ?? 0);
        $campi = [];

        foreach (Shipments::remaining($orderId) as $itemId => $quantita) {
            if ($quantita <= 0.0005) {
                continue;
            }

            $riga = OrderItem::findById((int) $itemId);
            $nome = html_entity_decode(is_array($riga) ? (string) ($riga['name'] ?? '') : '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $campi[] = FormField::key('qty_'.(int) $itemId)->text()
                ->label($nome.' (da spedire: '.OrderSheet::number($quantita).')')
                ->value(OrderSheet::number($quantita))
                ->attribute('inputmode="decimal"')
                ->columnSpan(12);
        }

        if ($campi === []) {
            return '';
        }

        return Modal::make(trim('Crea spedizione: ordine '.html_entity_decode(trim((string) ($order['order_number'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8')))
            ->id(OrderResource::actionModalId(OrderActions::CREATE_SHIPMENT))
            ->form(OrderActionResource::submitUrl(), hidden: [
                'order_id' => $orderId,
                'action' => OrderActions::CREATE_SHIPMENT,
                'torna' => $back,
            ])
            ->columns(12)
            ->components([
                ...$campi,
                FormField::key('carrier_id')->select(['0' => 'Scegli il corriere'] + static::carrierOptions())
                    ->label('Corriere')->columnSpan(6),
                FormField::key('tracking_number')->text()->label('Tracking')->columnSpan(6),
                FormField::key('after')->select(['' => 'Lasciala in attesa', 'ship' => 'Segna subito spedita'])
                    ->label('Dopo averla creata')->columnSpan(12),
            ])
            ->cancel('Indietro')
            ->submit(OrderActions::label(OrderActions::CREATE_SHIPMENT), 'primary')
            ->render('bootstrap');
    }

    /** Le finestre della scheda di una spedizione. @param array<string, mixed> $shipment */
    public static function modalsFor(array $shipment): string
    {
        return static::modals([$shipment], StockAdjustmentResource::backUrlFrom($_GET['torna'] ?? ''));
    }

    // ── La scheda ────────────────────────────────────────────────────────

    /**
     * La scheda di una spedizione: l'intestazione, i pulsanti, le righe e lo
     * storico degli stati.
     *
     * @param array<string, mixed> $shipment
     */
    public static function showLayoutSchema(array $shipment): Container
    {
        $id = (int) ($shipment['id'] ?? 0);
        $back = StockAdjustmentResource::backUrlFrom($_GET['torna'] ?? '');
        $dato = static fn (string $etichetta, string $valore, bool $html = false): DataItem => DataItem::make($etichetta, $valore)
            ->html($html)
            ->columnSpan(['default' => 12, 'sm' => 4]);
        $azioni = static::actionsHtml($shipment, $back);

        $intestazione = [
            $dato('Codice', (string) ($shipment['code'] ?? '')),
            $dato('Ordine', static::orderLink((int) ($shipment['order_id'] ?? 0)), true),
            $dato('Tipo', static::typeLabel((string) ($shipment['type'] ?? ''))),
            $dato('Stato', StatusLabels::badge('shipment', (string) ($shipment['status'] ?? '')), true),
            $dato('Corriere', static::escape(static::carrierName((int) ($shipment['carrier_id'] ?? 0))) ?: '—', true),
            $dato('Tracking', static::trackingHtml($shipment), true),
            $dato('Partita il', OrderSheet::date((string) ($shipment['shipped_at'] ?? ''))),
            $dato('Consegnata il', OrderSheet::date((string) ($shipment['delivered_at'] ?? ''))),
            $dato('Nota', nl2br(static::escape(html_entity_decode((string) ($shipment['note'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?: '—', true),
        ];

        if ($azioni !== '') {
            $intestazione[] = RichText::make($azioni)->tag('div')->columnSpan(12);
        }

        return (new Container)->columns(12)->components([
            (new Card)->components($intestazione)->columns(12)->columnSpan(12),
            Accordion::make('Righe')->expanded()->components([RichText::make(static::linesHtml($id))->tag('div')->columnSpan(12)])->columnSpan(12),
            Accordion::make('Storico')->components([RichText::make(static::historyHtml($id))->tag('div')->columnSpan(12)])->columnSpan(12),
        ]);
    }

    /** Cosa c'è nel pacco: nome della riga e quantità. */
    private static function linesHtml(int $shipmentId): string
    {
        $righe = '';

        foreach (static::rowsOf(ShipmentItem::class, ['shipment_id' => $shipmentId], 'id') as $item) {
            $riga = OrderItem::findById((int) ($item['order_item_id'] ?? 0));
            $nome = is_array($riga) ? (string) ($riga['name'] ?? '') : '';
            $righe .= '<tr><td>'.static::escapeStored($nome).'</td><td class="text-end">'
                .static::escape(OrderSheet::number((float) ($item['quantity'] ?? 0))).'</td></tr>';
        }

        if ($righe === '') {
            return '<p class="text-muted mb-0">Nessuna riga in questa spedizione.</p>';
        }

        return '<table class="table table-sm mb-0"><thead><tr><th>Articolo</th><th class="text-end">Quantità</th></tr></thead><tbody>'
            .$righe.'</tbody></table>';
    }

    /** Gli stati attraversati, dal più recente. */
    private static function historyHtml(int $shipmentId): string
    {
        $righe = '';
        $log = static::rowsOf(ShipmentStatusLog::class, ['shipment_id' => $shipmentId], 'id', 'DESC');

        foreach ($log as $voce) {
            $da = (string) ($voce['from_value'] ?? '');
            $a = (string) ($voce['to_value'] ?? '');
            $utente = (int) ($voce['user_id'] ?? 0) > 0 ? '#'.(int) $voce['user_id'] : '—';
            $righe .= '<tr><td>'.static::escape(OrderSheet::date((string) ($voce['creation'] ?? ''))).'</td>'
                .'<td>'.static::escape($da === '' ? '—' : StatusLabels::shipment($da)['label']).'</td>'
                .'<td>'.static::escape(StatusLabels::shipment($a)['label']).'</td>'
                .'<td>'.static::escape((string) ($voce['source'] ?? '')).'</td>'
                .'<td>'.static::escape($utente).'</td></tr>';
        }

        if ($righe === '') {
            return '<p class="text-muted mb-0">Nessun cambio registrato.</p>';
        }

        return '<table class="table table-sm mb-0"><thead><tr><th>Data</th><th>Da</th><th>A</th><th>Origine</th><th>Utente</th></tr></thead><tbody>'
            .$righe.'</tbody></table>';
    }
}
