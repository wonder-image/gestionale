<?php

namespace Wonder\Plugin\Gestionale\Resources\Sales;

use Throwable;
use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\App\ResourceSchema\TableLayoutSchema;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\RichText;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Sales\OrderStatusLog;
use Wonder\Plugin\Gestionale\Models\Sales\OrderTaxSummary;
use Wonder\Plugin\Gestionale\Models\Sales\SalesReturn;
use Wonder\Plugin\Gestionale\Resources\Stock\StockAdjustmentResource;
use Wonder\Plugin\Gestionale\Support\Orders\OrderActions;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;
use Wonder\Plugin\Gestionale\Support\Payments\PaymentStatus;
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
            TableColumn::key('order_number')->text()->size('little')->sortable(),
            TableColumn::key('ordered_at')
                ->text()
                ->size('little')
                ->sortable()
                ->formatter(static fn (array $row): string => static::escape(static::date((string) ($row['ordered_at'] ?? '')))),
            TableColumn::key('customer')
                ->text()
                ->formatter(static fn (array $row): string => static::escape(static::customerName($row))),
            TableColumn::key('total')
                ->text()
                ->size('little')
                ->sortable()
                ->formatter(static fn (array $row): string => '<span class="d-block text-end" style="font-variant-numeric: tabular-nums">'
                    .static::escape(static::money($row['total'] ?? 0)).'</span>'),
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
            ->filterCustom('Evasione', 'fulfillment_status', static::options('fulfillment', Order::FULFILLMENT_STATUSES));
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
     * I pulsanti in testa alla scheda: il ritorno all'elenco e le azioni che
     * valgono per lo stato dell'ordine. Ogni azione apre la sua finestra di
     * conferma, che `actionModals()` disegna in fondo alla scheda.
     *
     * @return list<array<string, string>>
     */
    public static function actionsFor(array $order): array
    {
        $pulsanti = [[
            'label' => 'Elenco',
            'icon' => 'bi-list-ul',
            'class' => 'btn-outline-secondary btn-sm',
            'href' => static::listUrl(),
        ]];

        foreach (OrderActions::available($order, ['returns' => Gestionale::feature('returns')]) as $azione) {
            $pulsanti[] = [
                'label' => OrderActions::label($azione),
                'icon' => OrderActions::icon($azione),
                'class' => OrderActions::buttonClass($azione).' btn-sm',
                'href' => '#',
                'onclick' => 'window.bootstrap.Modal.getOrCreateInstance(document.getElementById('
                    .json_encode(static::actionModalId($azione)).')).show(); return false;',
            ];
        }

        return $pulsanti;
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
            $back
        );
    }

    /**
     * Una finestra per azione disponibile. La form posta a `OrderActionResource`
     * con l'ordine, l'azione e la strada del ritorno.
     *
     * @param list<array<string, mixed>> $items
     * @param list<array<string, mixed>> $payments
     */
    public static function actionModals(array $order, array $items, array $payments, string $back): string
    {
        $azioni = OrderActions::available($order, ['returns' => Gestionale::feature('returns')]);

        if ($azioni === []) {
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
        return PermissionSchema::for(static::class)->backend(['list'], ['admin', 'administrator']);
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
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
     * La scheda dell'ordine, sola lettura: sette riquadri nell'ordine della
     * spec — intestazione, righe, riepilogo IVA, totali, pagamenti, resi,
     * storico. Le righe si leggono qui; il disegno sta in `OrderSheet`.
     */
    public static function showLayoutSchema(array $order): Container
    {
        $id = (int) ($order['id'] ?? 0);
        $per = ['order_id' => $id];
        $metodi = [];

        foreach (static::rowsOf(PaymentMethod::class) as $metodo) {
            $metodi[(int) $metodo['id']] = (string) ($metodo['name'] ?? '');
        }

        $riquadro = static fn (string $titolo, string $html, ?string $aiuto = null): Card => (new Card)->components([
            $aiuto === null ? SectionTitle::make($titolo)->columnSpan(12) : SectionTitle::make($titolo)->tooltip($aiuto)->columnSpan(12),
            RichText::make($html)->tag('div')->columnSpan(12),
        ])->columns(12)->columnSpan(12);

        return (new Container)->components([
            $riquadro('Ordine '.(string) ($order['order_number'] ?? ''), static::headerHtml($order, $metodi)),
            $riquadro('Righe', OrderSheet::items(static::rowsOf(OrderItem::class, $per, 'position'))),
            $riquadro('Riepilogo IVA', OrderSheet::taxSummary(static::rowsOf(OrderTaxSummary::class, $per)),
                'L\'imposta si calcola sul totale imponibile di ogni aliquota, non riga per riga.'),
            $riquadro('Totali', OrderSheet::totals($order)),
            $riquadro('Pagamenti', OrderSheet::payments(static::rowsOf(Payment::class, $per, 'id'), $metodi)),
            $riquadro('Resi', OrderSheet::returns(Gestionale::feature('returns') ? static::rowsOf(SalesReturn::class, $per, 'id') : [])),
            $riquadro('Storico', OrderSheet::history(static::rowsOf(OrderStatusLog::class, $per, 'id', 'DESC'))),
        ])->columns(12);
    }

    /**
     * L'intestazione: numero, data, canale, i tre stati, il cliente, gli
     * indirizzi, il metodo di pagamento e le note.
     *
     * @param array<int, string> $metodi
     */
    protected static function headerHtml(array $order, array $metodi): string
    {
        $dato = static fn (string $etichetta, string $html): string => '<div class="col-12 col-md-4 mb-3">'
            .'<div class="small text-muted">'.static::escape($etichetta).'</div><div>'.($html !== '' ? $html : '<span class="text-muted">—</span>').'</div></div>';
        $testo = static fn (string $v): string => trim($v) !== '' ? static::escape($v) : '';
        $nota = static fn (string $v): string => trim($v) !== '' ? nl2br(static::escape($v)) : '';
        $canali = ['online' => 'Online', 'office' => 'Ufficio', 'pos' => 'Cassa'];
        $canale = (string) ($order['channel'] ?? '');

        return '<div class="row">'
            .$dato('Numero', $testo((string) ($order['order_number'] ?? '')))
            .$dato('Data', static::escape(static::date((string) ($order['ordered_at'] ?? ''))))
            .$dato('Canale', static::escape($canali[$canale] ?? $canale))
            .$dato('Ordine', StatusLabels::badge('order', (string) ($order['status'] ?? '')))
            .$dato('Pagamento', StatusLabels::badge('payment', (string) ($order['payment_status'] ?? '')))
            .$dato('Evasione', StatusLabels::badge('fulfillment', (string) ($order['fulfillment_status'] ?? '')))
            .$dato('Cliente', static::escape(static::customerName($order)))
            .$dato('Email', $testo((string) ($order['email'] ?? '')))
            .$dato('Telefono', $testo((string) ($order['phone'] ?? '')))
            .$dato('Fatturazione', OrderSheet::address($order, 'billing'))
            .$dato('Spedizione', OrderSheet::address($order, 'shipping'))
            .$dato('Metodo di pagamento', $testo($metodi[(int) ($order['payment_method_id'] ?? 0)] ?? ''))
            .$dato('Nota del cliente', $nota((string) ($order['customer_note'] ?? '')))
            .$dato('Nota interna', $nota((string) ($order['internal_note'] ?? '')))
            .$dato('Nota sul documento', $nota((string) ($order['document_note'] ?? '')))
            .'</div>';
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
