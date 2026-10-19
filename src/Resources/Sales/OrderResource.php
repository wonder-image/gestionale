<?php

namespace Wonder\Plugin\Gestionale\Resources\Sales;

use DateTimeImmutable;
use Throwable;
use Wonder\App\Permission\Access;
use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\App\ResourceSchema\TableLayoutSchema;
use Wonder\Elements\Components\Accordion;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\DataItem;
use Wonder\Elements\Components\Modal;
use Wonder\Elements\Components\RichText;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Models\Promotions\CouponRedemption;
use Wonder\Plugin\Gestionale\Models\Sales\OrderTaxSummary;
use Wonder\Plugin\Gestionale\Models\Shipping\Shipment;
use Wonder\Plugin\Gestionale\Resources\Shipping\ShipmentResource;
use Wonder\Plugin\Gestionale\Resources\Contacts\CustomerResource;
use Wonder\Plugin\Gestionale\Resources\Stock\StockAdjustmentResource;
use Wonder\Plugin\Gestionale\Support\Backend\HeaderActions;
use Wonder\Plugin\Gestionale\Support\Documents\OrderProforma;
use Wonder\Plugin\Gestionale\Support\Orders\OrderActions;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;
use Wonder\Plugin\Gestionale\Support\Payments\OnlinePayments;
use Wonder\Plugin\Gestionale\Support\Payments\PaymentStatus;
use Wonder\Plugin\Gestionale\Support\Sales\DeliveryNotes;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeOrders;
use Wonder\Plugin\Gestionale\Support\Stock\MovementPeriod;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Orders\StatusLabels;

/**
 * "Ordini": gli ordini veri, in sola consultazione.
 *
 * Un ordine non si scrive a mano da qui — nasce dal checkout e cambia con le
 * azioni della sua scheda, che passano da `Lifecycle` e `Ledger`. L'elenco
 * mostra solo le righe con `stage = 'order'`: i carrelli stanno nella stessa
 * tabella ma non sono ordini finché non passano dal checkout.
 */
final class OrderResource extends GestionaleResource
{
    public static string $model = Order::class;
    public static string $feature = 'orders';
    public static string $orderColumn = 'ordered_at';
    public static string $orderDirection = 'DESC';

    public static function path(): string
    {
        return 'app/gestionale/ordini';
    }

    public static function icon(): string
    {
        return 'bi-receipt';
    }

    public static function titleLabel(): string
    {
        return 'Ordini';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'ordine',
            'plural_label' => 'ordini',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'gli',
            'this' => 'questo',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'order_number' => 'Numero',
            // Le due colonne di icone non hanno titolo: la parola è nel tooltip, e restano strette.
            'channel' => '',
            'fulfillment_type' => '',
            'ordered_at' => 'Data',
            'customer' => 'Cliente',
            'total' => 'Totale',
            'status' => 'Ordine',
            'payment_status' => 'Pagamento',
            'fulfillment_status' => 'Evasione',
        ];
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('order_number')
                ->text()
                ->size('little')
                ->sortable()
                ->link('view')
                // Le bozze da ufficio di prima del numero unico ne sono senza.
                ->formatter(static fn (array $row): string => trim((string) ($row['order_number'] ?? '')) !== ''
                    ? static::escape((string) $row['order_number'])
                    : '<span class="text-body-secondary">Bozza</span>'),
            // Da dove arriva e come si consegna: un'icona, la parola nel tooltip.
            TableColumn::key('channel')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::channelCell((string) ($row['channel'] ?? ''))),
            TableColumn::key('fulfillment_type')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::fulfillmentCell((string) ($row['fulfillment_type'] ?? ''))),
            TableColumn::key('customer')
                ->text()
                ->formatter(static fn (array $row): string => static::escape(static::customerName($row)))
                ->hiddenDevice('mobile'),
            TableColumn::key('total')->price()->size('little')->sortable(),
            TableColumn::key('ordered_at')
                ->date()
                ->sortable()
                ->size('medium')
                ->hiddenDevice('mobile'),
            TableColumn::key('status')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => StatusLabels::badge('order', (string) ($row['status'] ?? ''))),
            TableColumn::key('payment_status')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::paymentBadge(
                    (string) ($row['payment_status'] ?? ''),
                    OnlinePayments::inTest((int) ($row['id'] ?? 0))
                )),
            TableColumn::key('fulfillment_status')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => StatusLabels::badge('fulfillment', (string) ($row['fulfillment_status'] ?? ''))),
            TableColumn::key('actions')->button()->actions(['view']),
        ];
    }

    public static function tableLayoutSchema(): TableLayoutSchema
    {
        return TableLayoutSchema::for(static::class)
            ->title('Ordini')
            ->results()
            ->hideButtonAdd()
            ->filterSearch()
            ->searchFields(['order_number', 'billing_name', 'billing_surname', 'billing_business_name', 'email'])
            ->filterCustom('Ordine', 'status', static::options('order', Gestionale::feature('office_sales') ? ['draft', ...Order::LIVE_STATUSES] : Order::LIVE_STATUSES))
            ->filterCustom('Pagamento', 'payment_status', static::options('payment', Order::PAYMENT_STATUSES))
            ->filterCustom('Evasione', 'fulfillment_status', static::options('fulfillment', Order::FULFILLMENT_STATUSES))
            // Gli ordini abbandonati al pagamento stanno fuori dall'elenco:
            // si guardano solo chiedendoli.
            ->filterQuery(
                'Pagamenti',
                'payment_view',
                ['active' => 'Ordini', 'failed' => 'Pagamenti non riusciti'],
                static fn (array $values): string => ($values[0] ?? '') === 'failed' ? "status = 'abandoned'" : "status <> 'abandoned'",
                value: 'active'
            )
            ->filterQuery(
                'Periodo',
                'periodo',
                MovementPeriod::filterOptions(),
                static fn (array $values): string => MovementPeriod::sql((string) ($values[0] ?? ''), 'ordered_at', new DateTimeImmutable('now'))
            );
    }

    /**
     * Gli ordini, non i carrelli e non i preventivi.
     *
     * @return array<string, mixed>
     */
    public static function querySchema(): array
    {
        $schema = parent::querySchema();
        // «In pagamento» non è ancora un ordine per il commerciante.
        $schema['condition'] = "deleted = 'false' AND stage = 'order' AND status <> 'awaiting_payment'";

        return $schema;
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()
            ->only(['list'])
            ->enable(['view'])
            ->titles(['list' => 'Ordini', 'view' => 'Ordine'])
            ->subtitles(['list' => 'Gli ordini dei clienti, dal checkout alla consegna. Si consultano qui; ogni cambio passa dalle azioni della scheda.'])
            ->view('show', Gestionale::viewPath('pages/order-show.php'))
            ->actions('view', static fn (array $item): array => static::pageActions($item));
    }

    /**
     * Le azioni sull'ordine, nell'ordine dei pulsanti: prima quelle che lo
     * portano avanti, poi quelle del menu «Altro». Il ritorno all'elenco è la
     * chevron del titolo. Ogni azione apre la sua finestra di conferma, che
     * `actionModals()` disegna in fondo alla scheda.
     *
     * @return list<array<string, string>>
     */
    public static function actionsFor(array $order): array
    {
        return [...static::primaryActions($order), ...static::otherActions($order)];
    }

    /**
     * Le azioni che cambiano lo stato dell'ordine, una per pulsante, sempre a
     * vista: Conferma, l'evasione (Segna evaso, Crea spedizione, Pronto per il
     * ritiro, Ritirato) e Registra pagamento.
     *
     * @return list<array<string, string>>
     */
    public static function primaryActions(array $order): array
    {
        // La bozza da ufficio si apre nella sua pagina: lì si conferma o si elimina (G9).
        if (static::isOfficeDraft($order)) {
            return [[
                'label' => 'Modifica bozza',
                'icon' => 'bi-pencil-square',
                'class' => 'btn-primary btn-sm',
                'href' => OfficeOrderResource::editUrl((int) ($order['id'] ?? 0)),
            ]];
        }

        $pulsanti = [];

        foreach (OrderActions::available($order, static::actionFeatures()) as $azione) {
            if (!in_array($azione, self::NOT_PRIMARY, true)) {
                $pulsanti[] = static::modalButton($azione);
            }
        }

        if (OrderActions::canRegisterPayment($order)) {
            $pulsanti[] = static::registerPaymentButton($order);
        }

        return $pulsanti;
    }

    /**
     * Le azioni del menu «Altro»: Registra reso e Annulla. Annullare le righe
     * sta nella tabella delle righe, una per una.
     *
     * @return list<array<string, string>>
     */
    public static function otherActions(array $order): array
    {
        if (static::isOfficeDraft($order)) {
            return [];
        }

        $pulsanti = [];

        if (OrderActions::canRegisterReturn($order, ['returns' => Gestionale::feature('returns')])) {
            $pulsanti[] = static::registerReturnButton($order);
        }

        if (in_array(OrderActions::CANCEL, OrderActions::available($order, static::actionFeatures()), true)) {
            $pulsanti[] = static::modalButton(OrderActions::CANCEL);
        }

        return $pulsanti;
    }

    /** Le azioni che non sono pulsanti a vista: Annulla sta in «Altro», il DDT in «Documenti», Annulla righe nella tabella. */
    private const NOT_PRIMARY = [OrderActions::CANCEL, OrderActions::CANCEL_LINES, OrderActions::CREATE_DELIVERY_NOTE];

    /**
     * In testa alla scheda: il menu «Documenti», i pulsanti che portano avanti
     * l'ordine e il menu «Altro». Un documento solo resta un pulsante.
     *
     * @return list<array<string, mixed>>
     */
    public static function pageActions(array $order): array
    {
        return [
            ...HeaderActions::group(static::documentButtons($order), 'Documenti', 'bi-file-earmark-pdf'),
            ...static::primaryActions($order),
            ...HeaderActions::group(static::otherActions($order), 'Altro', 'bi-three-dots-vertical', true),
        ];
    }

    /**
     * I documenti dell'ordine: il proforma (che è anche la conferma d'ordine
     * da mandare al cliente), dalla bozza in poi, e «Crea DDT» quando resta
     * qualcosa da mettere in un DDT.
     *
     * @return list<array<string, string>>
     */
    public static function documentButtons(array $order): array
    {
        $id = (int) ($order['id'] ?? 0);
        $pulsanti = [];

        if (OrderProforma::availableInBackend($order)) {
            $pulsanti[] = [
                'label' => 'Proforma',
                'icon' => 'bi-file-earmark-text',
                'class' => 'btn-outline-secondary btn-sm',
                'href' => static::proformaUrl($id),
                'target' => '_blank',
            ];
        }

        if (!static::isOfficeDraft($order)
            && in_array(OrderActions::CREATE_DELIVERY_NOTE, OrderActions::available($order, static::actionFeatures()), true)
            && DeliveryNotes::proposal($id) !== []) {
            $pulsanti[] = static::modalButton(OrderActions::CREATE_DELIVERY_NOTE);
        }

        return $pulsanti;
    }

    /** La bozza di un ordine da ufficio: si lavora nella sua pagina. */
    private static function isOfficeDraft(array $order): bool
    {
        return (string) ($order['channel'] ?? '') === 'office' && (string) ($order['status'] ?? '') === 'draft';
    }

    /** Il pulsante di un'azione: apre la sua finestra di conferma. @return array<string, string> */
    private static function modalButton(string $azione): array
    {
        return [
            'label' => OrderActions::label($azione),
            'icon' => OrderActions::icon($azione),
            'class' => OrderActions::buttonClass($azione).' btn-sm',
            'href' => '#',
            'onclick' => static::openModal(static::actionModalId($azione)),
        ];
    }

    /** Il clic che apre una finestra di Bootstrap. */
    public static function openModal(string $id): string
    {
        return 'window.bootstrap.Modal.getOrCreateInstance(document.getElementById('.json_encode($id).')).show(); return false;';
    }

    /** Le icone del canale: [icona, parola]. */
    public const CHANNEL_ICONS = [
        'online' => ['bi-globe2', 'Online'],
        'office' => ['bi-building', 'Ufficio'],
        'pos' => ['bi-shop', 'Cassa'],
        'b2b_portal' => ['bi-briefcase', 'Portale B2B'],
    ];

    /** Le icone della consegna: [icona, parola]. */
    public const FULFILLMENT_ICONS = [
        'shipping' => ['bi-truck', 'Spedizione'],
        'pickup' => ['bi-bag-check', 'Ritiro in negozio'],
        'none' => ['bi-dash-circle', 'Niente da consegnare'],
    ];

    /** Il canale dell'ordine: un'icona con la parola nel tooltip. */
    public static function channelCell(string $channel): string
    {
        return static::iconCell(self::CHANNEL_ICONS[$channel] ?? null);
    }

    /** Spedizione o ritiro: un'icona con la parola nel tooltip. */
    public static function fulfillmentCell(string $type): string
    {
        return static::iconCell(self::FULFILLMENT_ICONS[$type] ?? null);
    }

    /** @param array{0: string, 1: string}|null $icon */
    private static function iconCell(?array $icon): string
    {
        if ($icon === null) {
            return '<span class="text-body-secondary">—</span>';
        }

        return '<i class="bi '.$icon[0].' text-muted" data-bs-toggle="tooltip" data-bs-title="'.static::escape($icon[1]).'" aria-label="'.static::escape($icon[1]).'"></i>';
    }

    /** Il proforma in PDF dell'ordine. */
    public static function proformaUrl(int $id): string
    {
        return static::routeUrl('proforma', '/backend/'.static::path().'/'.$id.'/proforma/', ['id' => $id]);
    }

    /** *Proforma* della scheda: la rotta del PDF. */
    public static function registerBackendRoutes(string $rootApp, string $slug): void
    {
        \Wonder\Http\Route::get('/{id}/proforma/', Gestionale::handlerPath('backend/sales/order-proforma-pdf.php'), [
            'resource' => $slug,
            'resource_action' => 'proforma',
        ])->name('proforma')
            ->permit(Access::roles(Gestionale::area('vendite'), 'view'))
            ->where('id', '[0-9]+');
    }

    /** Le funzionalità che cambiano le azioni offerte. @return array<string, bool> */
    private static function actionFeatures(): array
    {
        return ['returns' => Gestionale::feature('returns'), 'shipping' => Gestionale::feature('shipping'), 'delivery_notes' => Gestionale::feature('delivery_notes')];
    }

    /** «Registra reso» porta a una pagina con le righe da compilare: porta con sé il ritorno all'elenco, e la scheda lo rimette da sé. @return array<string, string> */
    private static function registerReturnButton(array $order): array
    {
        $id = (int) ($order['id'] ?? 0);
        $torna = StockAdjustmentResource::backUrlFrom($_GET['torna'] ?? '');

        return [
            'label' => 'Registra reso',
            'icon' => 'bi-arrow-return-left',
            'class' => 'btn-outline-secondary btn-sm',
            'href' => OrderReturnResource::urlFor($id, $torna),
        ];
    }

    /** @return array<string, string> */
    private static function registerPaymentButton(array $order): array
    {
        return [
            'label' => 'Registra pagamento',
            'icon' => 'bi-cash-coin',
            'class' => 'btn-outline-success btn-sm',
            'href' => '#',
            'onclick' => static::openModal(OrderPaymentResource::MODAL_ID),
        ];
    }

    /** L'id della finestra di conferma di un'azione. */
    public static function actionModalId(string $action): string
    {
        return 'wi-ordine-azione-'.$action;
    }

    /**
     * Le finestre di conferma, lette dal database: le righe e i pagamenti
     * servono a dire cosa farà l'azione prima che si prema.
     */
    public static function actionModalsFor(array $order): string
    {
        $per = ['order_id' => (int) ($order['id'] ?? 0)];
        $back = StockAdjustmentResource::backUrlFrom($_GET['torna'] ?? '');

        $html = static::actionModals(
            $order,
            static::rowsOf(OrderItem::class, $per, 'position'),
            static::rowsOf(Payment::class, $per, 'id'),
            $back,
            OrderPaymentResource::activeMethods()
        );

        // Le azioni delle spedizioni hanno una finestra per spedizione, dal menu ⋯ della tabella.
        if (Gestionale::feature('shipping')) {
            $html .= ShipmentResource::modals(static::rowsOf(Shipment::class, $per, 'id'), $back);
        }

        // «Chiudi» e «Annulla» dei resi ricevuti, dal menu ⋯ della tabella dei resi.
        if (Gestionale::feature('returns')) {
            $html .= OrderReturnTableResource::modals((int) ($order['id'] ?? 0), $back);
        }

        return $html;
    }

    /**
     * Una finestra per azione disponibile. La form posta a `OrderActionResource`
     * con l'ordine, l'azione e la strada del ritorno. Se l'ordine si può
     * incassare c'è anche la finestra di «Registra pagamento», che posta alla
     * sua pagina.
     *
     * @param list<array<string, mixed>> $items
     * @param list<array<string, mixed>> $payments
     * @param array<int, string> $methods metodi di pagamento attivi, per id
     */
    public static function actionModals(array $order, array $items, array $payments, string $back, array $methods = []): string
    {
        $azioni = OrderActions::available($order, static::actionFeatures());
        $incassa = OrderActions::canRegisterPayment($order);

        if ($azioni === [] && !$incassa) {
            return '';
        }

        $sums = PaymentStatus::sums($payments);
        $order['paid_total'] = max(0.0, round($sums['paid'] - $sums['refunded'], 2));
        $html = '';

        foreach ($azioni as $azione) {
            if ($azione === OrderActions::CREATE_SHIPMENT) {
                $html .= ShipmentResource::createModal($order, $back);

                continue;
            }

            if ($azione === OrderActions::CANCEL_LINES) {
                $html .= static::cancelLineModals($order, $items, $back);

                continue;
            }

            $html .= Modal::make(trim(OrderActions::label($azione).' l\'ordine '.trim((string) ($order['order_number'] ?? ''))))
                ->id(static::actionModalId($azione))
                ->form(OrderActionResource::submitUrl(), hidden: [
                    'order_id' => (int) ($order['id'] ?? 0),
                    'action' => $azione,
                    'torna' => $back,
                ])
                ->components([
                    RichText::make(static::escape(OrderActions::summary($azione, $order, $items)))->class('mb-0'),
                ])
                ->cancel('Indietro')
                ->submit(OrderActions::label($azione), OrderActions::variant($azione))
                ->render('bootstrap');
        }

        if ($incassa) {
            $html .= OrderPaymentResource::modal($order, $payments, $methods, $back);
            $html .= OrderPaymentResource::installmentModals($order, $payments, $methods, $back);
        }

        return $html;
    }

    /**
     * Quanto si può annullare di ogni riga di un ordine da ufficio confermato
     * (G9 §2.5).
     *
     * @return array<int, float>
     */
    public static function cancellable(array $order): array
    {
        if ((string) ($order['channel'] ?? '') !== 'office') {
            return [];
        }

        return OfficeOrders::cancellableQuantities($order, static::rowsOf(OrderItem::class, ['order_id' => (int) ($order['id'] ?? 0)], 'position'));
    }

    /** L'id della finestra «Annulla riga» di una riga. */
    public static function cancelLineModalId(int $itemId): string
    {
        return 'wi-ordine-annulla-riga-'.$itemId;
    }

    /**
     * «Annulla riga»: una finestra per ogni riga ancora annullabile, che si
     * apre dal menu ⋯ della riga. La quantità parte da tutto quello che si può
     * annullare; scrivine meno per annullarne una parte. La merce rientra in
     * magazzino, totali e rate si rifanno.
     *
     * @param list<array<string, mixed>> $items
     */
    public static function cancelLineModals(array $order, array $items, string $back): string
    {
        $names = array_column($items, 'name', 'id');
        $numero = html_entity_decode(trim((string) ($order['order_number'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = '';

        foreach (OfficeOrders::cancellableQuantities($order, $items) as $itemId => $quantita) {
            if ($quantita <= 0) {
                continue;
            }

            $nome = html_entity_decode((string) ($names[$itemId] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $html .= Modal::make(trim('Annulla riga: '.$nome))
                ->id(static::cancelLineModalId((int) $itemId))
                ->help('Si annulla solo quello che non è ancora partito: la merce torna in magazzino, i totali e le rate si rifanno. Annullando tutte le righe si annulla l\'ordine.')
                ->form(OrderActionResource::submitUrl(), hidden: [
                    'order_id' => (int) ($order['id'] ?? 0),
                    'action' => OrderActions::CANCEL_LINES,
                    'torna' => $back,
                ])
                ->columns(12)
                ->components([
                    RichText::make(static::escape('Ordine '.$numero))->class('small text-body-secondary mb-0')->columnSpan(12),
                    FormField::key('qty_'.(int) $itemId)->text()
                        ->label('Quantità da annullare (al massimo '.OrderSheet::number($quantita).')')
                        ->value(OrderSheet::number($quantita))
                        ->attribute('inputmode="decimal"')
                        ->required()
                        ->columnSpan(12),
                ])
                ->cancel('Indietro')
                ->submit('Annulla riga', 'danger')
                ->render('bootstrap');
        }

        return $html;
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->section('vendite', 'Vendite', 'bi-receipt', 300)
            ->title('Ordini')
            ->order(10)
            ->access(Gestionale::area('vendite'))
            ->enabled(static::featureActive());
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->access(Gestionale::area('vendite'), ['list', 'view']);
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
    }

    /** Il titolo della scheda: «Ordine 2025/001», o solo «Ordine» se il numero manca. */
    public static function pageTitle(array $order): string
    {
        return trim('Ordine '.trim((string) ($order['order_number'] ?? '')));
    }

    /** Sotto il titolo: «Creato il 14/10/2026 10:30 · Online». */
    public static function pageSubtitle(array $order): string
    {
        $quando = (string) ($order['ordered_at'] ?? '');
        $quando = $quando === '' || str_starts_with($quando, '0000') ? (string) ($order['creation'] ?? '') : $quando;
        $canale = (string) ($order['channel'] ?? '');
        $parti = [];

        if ($quando !== '' && !str_starts_with($quando, '0000')) {
            $parti[] = 'Creato il '.static::date($quando);
        }

        if (isset(self::CHANNEL_ICONS[$canale])) {
            $parti[] = self::CHANNEL_ICONS[$canale][1];
        }

        return implode(' · ', $parti);
    }

    /** Come arriva la merce, per la riga degli stati: «Spedizione», «Ritiro» o «Consegna». */
    public static function fulfillmentLabel(string $type): string
    {
        return match ($type) {
            'pickup' => 'Ritiro',
            'none' => 'Consegna',
            default => 'Spedizione',
        };
    }

    /** L'indirizzo dell'elenco, dalla rotta con il nome; il percorso è il ripiego. */
    public static function listUrl(): string
    {
        return static::routeUrl('list', '/backend/'.static::path().'/');
    }

    /** L'indirizzo della scheda di un ordine, con il ritorno facoltativo. */
    public static function detailUrl(int $id, ?string $back = null): string
    {
        $url = static::routeUrl('view', '/backend/'.static::path().'/'.$id.'/', ['id' => $id]);

        return $back === null || $back === '' ? $url : $url.'?torna='.rawurlencode($back);
    }

    /**
     * L'indirizzo della scheda con un segnaposto al posto dell'id, per i menu di
     * riga di altre tabelle (`{reference_id}`).
     */
    public static function detailUrlPattern(string $placeholder): string
    {
        $url = static::routeUrl('view', '/backend/'.static::path().'/__ROW_ID__/', ['id' => '__ROW_ID__']);

        return str_replace('__ROW_ID__', $placeholder, $url);
    }

    /** Il nome del cliente: un link alla sua scheda se l'ordine ne ha una, altrimenti solo il nome. */
    protected static function customerLink(array $order): string
    {
        $nome = static::escape(static::customerName($order));
        $url = static::customerUrl($order);

        return $url === '' ? $nome : '<a href="'.static::escape($url).'">'.$nome.'</a>';
    }

    /**
     * Il nome con cui l'ordine si riconosce: ragione sociale, poi nome e
     * cognome della fatturazione, poi l'email. Mai vuoto.
     */
    public static function customerName(array $row): string
    {
        $business = trim((string) ($row['billing_business_name'] ?? ''));

        if ($business !== '') {
            return $business;
        }

        $name = trim(trim((string) ($row['billing_name'] ?? '')).' '.trim((string) ($row['billing_surname'] ?? '')));

        if ($name !== '') {
            return $name;
        }

        $email = trim((string) ($row['email'] ?? ''));

        return $email !== '' ? $email : '—';
    }

    /** Un importo all'italiana, con l'euro: `1.234,50 €`. */
    public static function money(mixed $value): string
    {
        return OrderSheet::money($value);
    }

    /** `15/10/2025 10:30`; vuota o zero diventano un trattino. */
    public static function date(string $value): string
    {
        return OrderSheet::date($value);
    }

    /**
     * La scheda dell'ordine: l'intestazione, il riepilogo IVA e i totali in
     * riquadri; righe, pagamenti, resi e storico in accordion con la loro
     * tabella. Il titolo della pagina è già «Ordine <numero>», quindi
     * l'intestazione non lo ripete. Il disegno dei totali sta in `OrderSheet`.
     */
    public static function showLayoutSchema(array $order): Container
    {
        $id = (int) ($order['id'] ?? 0);
        $metodi = [];

        foreach (static::rowsOf(PaymentMethod::class) as $metodo) {
            $metodi[(int) $metodo['id']] = (string) ($metodo['name'] ?? '');
        }

        $riquadro = static fn (string $titolo, string $html, ?string $aiuto = null): Card => (new Card)->components([
            $aiuto === null ? SectionTitle::make($titolo)->columnSpan(12) : SectionTitle::make($titolo)->tooltip($aiuto)->columnSpan(12),
            RichText::make($html)->tag('div')->columnSpan(12),
        ])->columns(12)->columnSpan(12);

        $tabella = static fn (string $titolo, string $html, bool $aperto = false): Accordion => $aperto
            ? Accordion::make($titolo)->expanded()->components([RichText::make($html)->tag('div')->columnSpan(12)])->columnSpan(12)
            : Accordion::make($titolo)->components([RichText::make($html)->tag('div')->columnSpan(12)])->columnSpan(12);

        // In alto a sinistra l'intestazione; a destra, uno sopra l'altro, i totali
        // e il riepilogo IVA. Sotto, le tabelle a tutta larghezza.
        $componenti = [
            (new Container)->columnSpan(['default' => 12, 'lg' => 8])->columns(12)->components([
                (new Card)->components(static::headerItems($order, $metodi, static::rowsOf(Payment::class, ['order_id' => $id], 'id')))->columns(12)->columnSpan(12),
            ]),
            (new Container)->columnSpan(['default' => 12, 'lg' => 4])->columns(12)->components([
                $riquadro('Totali', OrderSheet::totals($order, static::rowsOf(CouponRedemption::class, ['order_id' => $id])[0] ?? null)),
                $riquadro('Riepilogo IVA', OrderSheet::taxSummary(static::rowsOf(OrderTaxSummary::class, ['order_id' => $id])),
                    'L\'imposta si calcola sul totale imponibile di ogni aliquota, non riga per riga.'),
            ]),
            $tabella('Righe', OrderItemTableResource::embed($id), true),
            $tabella('Pagamenti', OrderPaymentTableResource::embed($id)),
        ];

        if (Gestionale::feature('returns')) {
            $componenti[] = $tabella('Resi', OrderReturnTableResource::embed($id));
        }

        if (Gestionale::feature('shipping')) {
            $componenti[] = $tabella('Spedizioni', OrderShipmentTableResource::embed($id), true);
        }

        $componenti[] = $tabella('Storico', OrderHistoryTableResource::embed($id));

        return (new Container)->components($componenti)->columns(12);
    }

    /** L'indirizzo della scheda del cliente di un ordine; vuoto se l'ordine non ha un cliente registrato. */
    public static function customerUrl(array $order): string
    {
        $id = (int) ($order['customer_id'] ?? 0);

        return $id > 0 ? CustomerResource::viewUrl($id) : '';
    }

    /** Il badge dello stato del pagamento, con «Prova» se i soldi non sono veri. */
    public static function paymentBadge(string $status, bool $test): string
    {
        return StatusLabels::badge('payment', $status)
            .($test ? '<span class="badge bg-warning text-dark ms-1">Prova</span>' : '');
    }

    /**
     * L'intestazione: i tre stati, il cliente, gli indirizzi, il metodo di
     * pagamento (e chi l'ha gestito) e le note, un `DataItem` ciascuno. Numero,
     * data e canale stanno nel titolo e nel sottotitolo della pagina.
     *
     * @param array<int, string> $metodi
     * @param list<array<string, mixed>> $payments i pagamenti dell'ordine
     * @return list<DataItem>
     */
    protected static function headerItems(array $order, array $metodi, array $payments = []): array
    {
        // L'ultimo incasso passato da un gateway dice il metodo scelto (Klarna…) e chi l'ha gestito.
        $online = [];

        foreach ($payments as $payment) {
            if ((string) ($payment['type'] ?? 'payment') !== 'refund' && OrderPaymentTableResource::providerName((string) ($payment['provider'] ?? '')) !== '') {
                $online = $payment;
            }
        }

        // Testo semplice, o markup già escapato da noi con `html()`.
        $dato = static fn (string $etichetta, string $valore, bool $html = false, string $azione = ''): DataItem => ($azione === ''
            ? DataItem::make($etichetta, $valore)
            : DataItem::make($etichetta, $valore)->action($azione))
            ->html($html)
            ->columnSpan(['default' => 12, 'sm' => 4]);
        // La matita accanto a una nota apre la finestra delle note.
        $bloccate = OrderNoteResource::isLocked($order);
        $lucchetto = ' <i class="bi bi-lock text-muted ms-1" title="'.static::escape(OrderNoteResource::LOCKED_TEXT).'" aria-label="'.static::escape(OrderNoteResource::LOCKED_TEXT).'"></i>';
        $matita = static fn (string $nota): string => $bloccate ? $lucchetto : ' <a href="#" class="text-muted ms-1" title="Modifica la '.static::escape(strtolower($nota)).'" aria-label="Modifica la '.static::escape(strtolower($nota)).'"'
            .' role="button" data-bs-toggle="modal" data-bs-target="#'.OrderNoteResource::MODAL_ID.'">'
            .'<i class="bi bi-pencil"></i></a>';
        $nota = static fn (string $v): string => trim($v) !== '' ? nl2br(static::escape($v)) : '';
        $consegna = (string) ($order['fulfillment_type'] ?? 'shipping');

        // Numero, data e canale stanno nel titolo e sotto: la prima riga sono gli stati.
        return [
            $dato('Ordine', StatusLabels::badge('order', (string) ($order['status'] ?? '')), true),
            $dato('Pagamento', static::paymentBadge((string) ($order['payment_status'] ?? ''), OnlinePayments::inTest((int) ($order['id'] ?? 0))), true),
            $dato(static::fulfillmentLabel($consegna), StatusLabels::fulfillmentBadge($consegna, (string) ($order['fulfillment_status'] ?? '')), true),
            $dato('Cliente', static::customerLink($order), true),
            $dato('Email', (string) ($order['email'] ?? '')),
            $dato('Telefono', (string) ($order['phone'] ?? '')),
            $dato('Fatturazione', OrderSheet::address($order, 'billing'), true),
            $dato('Indirizzo di spedizione', OrderSheet::address($order, 'shipping'), true),
            $dato('Metodo di pagamento', PaymentMethod::choiceLabel((string) ($online['provider_method'] ?? ''), $metodi[(int) ($order['payment_method_id'] ?? 0)] ?? '')),
            ...($online === [] ? [] : [$dato('Gestito da', OrderPaymentTableResource::providerName((string) $online['provider']))]),
            $dato('Nota del cliente', $nota((string) ($order['customer_note'] ?? '')), true),
            $dato('Nota interna', $nota((string) ($order['internal_note'] ?? '')), true, $matita('Nota interna')),
            $dato('Nota sul documento', $nota((string) ($order['document_note'] ?? '')), true, $matita('Nota sul documento')),
        ];
    }

    /**
     * Le opzioni di un filtro: le chiavi sono quelle del Model, le parole
     * quelle di `StatusLabels`.
     *
     * @param list<string> $values
     * @return array<string, string>
     */
    private static function options(string $kind, array $values): array
    {
        $options = ['' => 'Tutti'];

        foreach ($values as $value) {
            $options[$value] = match ($kind) {
                'order' => StatusLabels::order($value)['label'],
                'payment' => StatusLabels::payment($value)['label'],
                default => StatusLabels::fulfillment($value)['label'],
            };
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function routeUrl(string $action, string $fallback, array $params = []): string
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
}
