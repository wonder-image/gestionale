<?php

namespace Wonder\Plugin\Gestionale\Resources\Sales;

use DateTimeImmutable;
use Throwable;
use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\App\ResourceSchema\TableLayoutSchema;
use Wonder\Elements\Components\Accordion;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\DataItem;
use Wonder\Elements\Components\RichText;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Models\Sales\OrderTaxSummary;
use Wonder\Plugin\Gestionale\Resources\Contacts\CustomerResource;
use Wonder\Plugin\Gestionale\Resources\Stock\StockAdjustmentResource;
use Wonder\Plugin\Gestionale\Support\Orders\OrderActions;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;
use Wonder\Plugin\Gestionale\Support\Payments\PaymentStatus;
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
            TableColumn::key('order_number')->text()->size('little')->sortable()->link('view'),
            TableColumn::key('customer')
                ->text()
                ->formatter(static fn (array $row): string => static::escape(static::customerName($row))),
            TableColumn::key('total')->price()->size('little')->sortable(),
            TableColumn::key('ordered_at')
                ->date()
                ->sortable()
                ->size('medium'),
            TableColumn::key('status')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => StatusLabels::badge('order', (string) ($row['status'] ?? ''))),
            TableColumn::key('payment_status')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => StatusLabels::badge('payment', (string) ($row['payment_status'] ?? ''))),
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
            ->filterCustom('Ordine', 'status', static::options('order', Order::LIVE_STATUSES))
            ->filterCustom('Pagamento', 'payment_status', static::options('payment', Order::PAYMENT_STATUSES))
            ->filterCustom('Evasione', 'fulfillment_status', static::options('fulfillment', Order::FULFILLMENT_STATUSES))
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
        $schema['condition'] = "deleted = 'false' AND stage = 'order'";

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
            ->actions('view', static fn (array $item): array => static::actionsFor($item));
    }

    /**
     * I pulsanti in testa alla scheda: le azioni che valgono per lo stato
     * dell'ordine. Il ritorno all'elenco è la chevron del titolo. Ogni azione
     * apre la sua finestra di conferma, che `actionModals()` disegna in fondo
     * alla scheda.
     *
     * @return list<array<string, string>>
     */
    public static function actionsFor(array $order): array
    {
        $pulsanti = [];

        $azioni = OrderActions::available($order, ['returns' => Gestionale::feature('returns')]);
        $incassa = OrderActions::canRegisterPayment($order);
        $rende = OrderActions::canRegisterReturn($order, ['returns' => Gestionale::feature('returns')]);

        foreach ($azioni as $azione) {
            // «Registra pagamento» e «Registra reso» stanno prima di Annulla.
            if ($azione === OrderActions::CANCEL) {
                $incassa && $pulsanti[] = static::registerPaymentButton($order);
                $rende && $pulsanti[] = static::registerReturnButton($order);
                $incassa = $rende = false;
            }

            $pulsanti[] = [
                'label' => OrderActions::label($azione),
                'icon' => OrderActions::icon($azione),
                'class' => OrderActions::buttonClass($azione).' btn-sm',
                'href' => '#',
                'onclick' => 'window.bootstrap.Modal.getOrCreateInstance(document.getElementById('
                    .json_encode(static::actionModalId($azione)).')).show(); return false;',
            ];
        }

        $incassa && $pulsanti[] = static::registerPaymentButton($order);
        $rende && $pulsanti[] = static::registerReturnButton($order);

        return $pulsanti;
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
            'onclick' => 'window.bootstrap.Modal.getOrCreateInstance(document.getElementById('
                .json_encode(OrderPaymentResource::MODAL_ID).')).show(); return false;',
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

        return static::actionModals(
            $order,
            static::rowsOf(OrderItem::class, $per, 'position'),
            static::rowsOf(Payment::class, $per, 'id'),
            $back,
            OrderPaymentResource::activeMethods()
        );
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
        $azioni = OrderActions::available($order, ['returns' => Gestionale::feature('returns')]);
        $incassa = OrderActions::canRegisterPayment($order);

        if ($azioni === [] && !$incassa) {
            return '';
        }

        $sums = PaymentStatus::sums($payments);
        $order['paid_total'] = max(0.0, round($sums['paid'] - $sums['refunded'], 2));
        $url = static::escape(OrderActionResource::submitUrl());
        $html = '';

        foreach ($azioni as $azione) {
            $id = static::actionModalId($azione);
            $titolo = OrderActions::label($azione).' l\'ordine '.trim((string) ($order['order_number'] ?? ''));

            $html .= '<div class="modal fade" id="'.static::escape($id).'" tabindex="-1" aria-hidden="true">'
                .'<div class="modal-dialog modal-dialog-centered"><div class="modal-content">'
                .'<form method="post" action="'.$url.'">'
                .'<div class="modal-header"><h5 class="modal-title">'.static::escape(trim($titolo)).'</h5>'
                .'<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button></div>'
                .'<div class="modal-body"><p class="mb-0">'.static::escape(OrderActions::summary($azione, $order, $items)).'</p></div>'
                .'<div class="modal-footer">'
                .'<input type="hidden" name="order_id" value="'.(int) ($order['id'] ?? 0).'">'
                .'<input type="hidden" name="action" value="'.static::escape($azione).'">'
                .'<input type="hidden" name="torna" value="'.static::escape($back).'">'
                .'<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Indietro</button>'
                .'<button type="submit" class="btn '.static::escape(OrderActions::buttonClass($azione)).'">'
                .static::escape(OrderActions::label($azione)).'</button>'
                .'</div></form></div></div></div>';
        }

        if ($incassa) {
            $html .= OrderPaymentResource::modal($order, $payments, $methods, $back);
        }

        return $html;
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->section('vendite', 'Vendite', 'bi-receipt', 350, ['admin', 'administrator'])
            ->title('Ordini')
            ->order(10)
            ->authority(['admin', 'administrator'])
            ->enabled(static::featureActive());
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->backend(['list', 'view'], ['admin', 'administrator']);
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
                (new Card)->components(static::headerItems($order, $metodi))->columns(12)->columnSpan(12),
            ]),
            (new Container)->columnSpan(['default' => 12, 'lg' => 4])->columns(12)->components([
                $riquadro('Totali', OrderSheet::totals($order)),
                $riquadro('Riepilogo IVA', OrderSheet::taxSummary(static::rowsOf(OrderTaxSummary::class, ['order_id' => $id])),
                    'L\'imposta si calcola sul totale imponibile di ogni aliquota, non riga per riga.'),
            ]),
            $tabella('Righe', OrderItemTableResource::embed($id), true),
            $tabella('Pagamenti', OrderPaymentTableResource::embed($id)),
        ];

        if (Gestionale::feature('returns')) {
            $componenti[] = $tabella('Resi', OrderReturnTableResource::embed($id));
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

    /**
     * L'intestazione: numero, data, canale, i tre stati, il cliente, gli
     * indirizzi, il metodo di pagamento e le note, un `DataItem` ciascuno.
     *
     * @param array<int, string> $metodi
     * @return list<DataItem>
     */
    protected static function headerItems(array $order, array $metodi): array
    {
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
            .' onclick="window.bootstrap.Modal.getOrCreateInstance(document.getElementById('.static::escape((string) json_encode(OrderNoteResource::MODAL_ID)).')).show(); return false;">'
            .'<i class="bi bi-pencil"></i></a>';
        $nota = static fn (string $v): string => trim($v) !== '' ? nl2br(static::escape($v)) : '';
        $canali = ['online' => 'Online', 'office' => 'Ufficio', 'pos' => 'Cassa'];
        $canale = (string) ($order['channel'] ?? '');

        return [
            $dato('Numero', (string) ($order['order_number'] ?? '')),
            $dato('Data', static::date((string) ($order['ordered_at'] ?? ''))),
            $dato('Canale', $canali[$canale] ?? $canale),
            $dato('Ordine', StatusLabels::badge('order', (string) ($order['status'] ?? '')), true),
            $dato('Pagamento', StatusLabels::badge('payment', (string) ($order['payment_status'] ?? '')), true),
            $dato('Evasione', StatusLabels::badge('fulfillment', (string) ($order['fulfillment_status'] ?? '')), true),
            $dato('Cliente', static::customerLink($order), true),
            $dato('Email', (string) ($order['email'] ?? '')),
            $dato('Telefono', (string) ($order['phone'] ?? '')),
            $dato('Fatturazione', OrderSheet::address($order, 'billing'), true),
            $dato('Spedizione', OrderSheet::address($order, 'shipping'), true),
            $dato('Metodo di pagamento', $metodi[(int) ($order['payment_method_id'] ?? 0)] ?? ''),
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
