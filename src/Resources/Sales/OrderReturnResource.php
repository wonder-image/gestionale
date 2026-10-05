<?php

namespace Wonder\Plugin\Gestionale\Resources\Sales;

use Throwable;
use Wonder\App\LegacyGlobals;
use Wonder\App\Resources\Support\NavigationOnlyResource;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\Backend\Support\FlashAlert;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\RichText;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Sales\SalesReturn;
use Wonder\Plugin\Gestionale\Models\Sales\SalesReturnItem;
use Wonder\Plugin\Gestionale\Resources\Stock\StockAdjustmentResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Customizations;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;
use Wonder\Plugin\Gestionale\Support\Returns\ReturnRules;
use Wonder\Plugin\Gestionale\Support\Returns\Returns;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;

/**
 * «Registra reso»: la merce che un cliente ha riportato, scritta sull'ordine.
 *
 * Come `OrderPaymentResource` è una pagina-form: l'ordine arriva
 * dall'indirizzo (`?ordine=`). La stessa rotta riceve anche il «Chiudi» e
 * l'«Annulla» dei resi, che la tabella dei resi posta con un `action`: così
 * c'è un solo punto che parla con `Returns`. Il rimborso del denaro non passa
 * di qui: resta al gateway.
 */
final class OrderReturnResource extends NavigationOnlyResource
{
    /** Come le altre Resource del gestionale: la pagina vale con la funzionalità accesa. */
    public static string $feature = 'returns';

    public static function path(): string
    {
        return 'app/gestionale/ordine-reso';
    }

    public static function icon(): string
    {
        return 'bi-arrow-return-left';
    }

    public static function titleLabel(): string
    {
        return 'Registra reso';
    }

    public static function isFormPage(): bool
    {
        return true;
    }

    public static function featureActive(): bool
    {
        return Gestionale::feature(static::$feature);
    }

    /** Il link che apre la pagina su un ordine, con la strada del ritorno. */
    public static function urlFor(int $orderId, string $back = ''): string
    {
        $url = static::submitUrl().'?ordine='.$orderId;

        return $back === '' ? $url : $url.'&torna='.rawurlencode($back);
    }

    /** Dove si posta: la stessa rotta della pagina, in POST. */
    public static function submitUrl(): string
    {
        $base = '/backend/'.static::path();

        if (function_exists('__r')) {
            try {
                $named = (string) __r('backend.resource.'.static::slug().'.form');
                $base = $named !== '' ? $named : $base;
            } catch (Throwable) {
                // Rotta non registrata (test, comandi): resta il percorso.
            }
        }

        return $base;
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()
            ->only([])
            ->titles(['form' => 'Registra reso'])
            ->subtitles(['form' => 'Scrivi la merce che il cliente ha riportato: ogni riga resa può rientrare a magazzino.']);
    }

    /** Le pagine-form leggono `edit` (apertura) e `update` (invio): il reso tocca il magazzino, come la rettifica. */
    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->backend(['edit', 'update'], ['admin', 'administrator']);
    }

    public static function navigationSchema(): NavigationSchema
    {
        // Fuori dal menu: ci si arriva dal pulsante della scheda dell'ordine.
        return NavigationSchema::for(static::class)
            ->inSection('vendite')
            ->title('Registra reso')
            ->authority(['admin', 'administrator'])
            ->enabled(false);
    }

    public static function formSchema(): array
    {
        $ordine = static::currentOrder();

        return [
            FormField::key('internal_note')->text()->label('Nota interna'),
            FormField::key('order_id')->hidden()->value((string) ($ordine['id'] ?? 0)),
            FormField::key('back')->hidden()->value(StockAdjustmentResource::backUrlFrom($_GET['torna'] ?? '')),
        ];
    }

    public static function formLayoutSchema(): ?Form
    {
        $ordine = static::currentOrder();

        return (new Form)->components([
            (new Container)->components([
                (new Card)->components([
                    SectionTitle::make($ordine === [] ? 'Registra reso' : 'Registra reso: ordine '.trim((string) ($ordine['order_number'] ?? '')))
                        ->tooltip('Scrivi la quantità solo sulle righe che il cliente ha reso. «Rientra a magazzino» rimette i pezzi in giacenza nella sede scelta: parte spento per merce danneggiata o difettosa, che di solito non si può rivendere. Il rimborso non parte da qui: si fa dal pagamento.')
                        ->columnSpan(12),
                    RichText::make(static::pageHtml((int) ($ordine['id'] ?? 0)))->columnSpan(12),
                    static::getInput('internal_note')->columnSpan(12),
                    static::getInput('order_id')->columnSpan(12),
                    static::getInput('back')->columnSpan(12),
                ])->columns(12)->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ])->columns(12);
    }

    /** Tutto ciò che sta sopra la nota: l'ordine, la tabella delle righe, la sede. */
    public static function pageHtml(int $orderId): string
    {
        $ordine = $orderId > 0 ? Order::findById($orderId) : null;

        if (!is_array($ordine) || $ordine === []) {
            return '<span class="text-danger">Apri questa pagina dal pulsante «Registra reso» di un ordine.</span>';
        }

        if (!ReturnRules::eligibleOrder($ordine, ['returns' => Gestionale::feature('returns')])) {
            return '<span class="text-danger">Su quest\'ordine non si può registrare un reso: serve un ordine confermato, in lavorazione o completato, con i resi attivi.</span>';
        }

        return static::linesHtml(Returns::lines($orderId))
            .static::locationField(Locations::shown(), Locations::mainId());
    }

    /**
     * La tabella delle righe prodotto: ordinato, già reso e i tre campi da
     * riempire. La spunta del ricarico segue il motivo scelto; quella che si
     * tocca a mano resta com'è fino al cambio successivo del motivo.
     *
     * @param list<array{order_item_id: int, name: string, ordered: float, returned: float, max: float}> $lines
     */
    public static function linesHtml(array $lines): string
    {
        if ($lines === []) {
            return '<p class="text-muted mb-0">Nessun prodotto da rendere su questo ordine.</p>';
        }

        $sparito = true;
        $righe = '';

        foreach ($lines as $line) {
            $id = (int) $line['order_item_id'];
            $max = (float) $line['max'];
            $nome = OrderSheet::esc((string) $line['name']);
            $celle = '<td>'.$nome.static::customizationLines($line).'</td><td class="text-end">'.static::pieces((float) $line['ordered']).'</td><td class="text-end">'.static::pieces((float) $line['returned']).'</td>';

            if ($max <= 0) {
                $righe .= '<tr class="text-muted">'.$celle.'<td colspan="3"><span class="fst-italic">Già reso per intero</span></td></tr>'
                    .static::childrenRows($id, (float) $line['ordered'], (array) ($line['children'] ?? []), 0.0);

                continue;
            }

            $sparito = false;
            $figlie = (array) ($line['children'] ?? []);
            $righe .= '<tr>'.$celle
                .'<td><input class="form-control form-control-sm" type="number" step="any" min="0" max="'.static::pieces($max, '.').'" name="lines['.$id.'][quantity]" placeholder="0" aria-label="Quantità resa di '.$nome.'"></td>'
                .'<td><select class="form-select form-select-sm" name="lines['.$id.'][reason]" aria-label="Motivo del reso di '.$nome.'" '
                .($figlie === []
                    ? 'onchange="var c=this.closest(\'tr\').querySelector(\'input[type=checkbox]\');c.checked=this.selectedOptions[0].dataset.restock===\'1\'">'
                    : 'data-wi-children="'.$id.'" onchange="var r=this.selectedOptions[0].dataset.restock===\'1\';document.querySelectorAll(\'input[data-wi-child-of=&quot;'.$id.'&quot;]\').forEach(function(c){c.checked=r})">')
                .static::reasonOptions().'</select></td>'
                .($figlie === []
                    ? '<td class="text-center"><input type="hidden" name="lines['.$id.'][restock]" value="">'
                        .'<input class="form-check-input" type="checkbox" name="lines['.$id.'][restock]" value="on"'.(ReturnRules::defaultRestock(ReturnRules::DEFAULT_REASON) ? ' checked' : '').' aria-label="Rientra a magazzino: '.$nome.'"></td></tr>'
                    : '<td class="text-muted small text-center">per componente</td></tr>')
                .static::childrenRows($id, (float) $line['ordered'], $figlie, $max);
        }

        $avviso = $sparito ? '<p class="text-muted mb-2">Niente da rendere: ogni prodotto è già stato reso per intero.</p>' : '';

        return $avviso.'<div class="table-responsive"><table class="table table-sm align-middle mb-3"><thead><tr>'
            .'<th>Prodotto</th><th class="text-end">Ordinati</th><th class="text-end">Già resi</th>'
            .'<th>Quantità</th><th>Motivo</th><th class="text-center">Rientra a magazzino</th>'
            .'</tr></thead><tbody>'.$righe.'</tbody></table></div>';
    }

    /**
     * Le righe dei componenti sotto una confezione: quanti ne contiene l'ordine,
     * quanti sono già resi e la spunta «rientra» di ciascuno. Il motivo scelto
     * sulla riga della confezione le accende o le spegne tutte.
     *
     * @param list<array{order_item_id: int, name: string, per_unit: float, returned: float, restock_default: bool}> $children
     */
    private static function childrenRows(int $motherId, float $ordered, array $children, float $max): string
    {
        $html = '';

        foreach ($children as $child) {
            $childId = (int) $child['order_item_id'];
            $nome = OrderSheet::esc((string) $child['name']);
            $celle = '<td class="ps-4 text-muted small">&#8627; '.$nome.'</td><td class="text-end small">'.static::pieces((float) $child['per_unit'] * $ordered)
                .'</td><td class="text-end small">'.static::pieces((float) $child['returned']).'</td>';

            if ($max <= 0) {
                $html .= '<tr class="text-muted">'.$celle.'<td colspan="3"></td></tr>';

                continue;
            }

            $name = 'lines['.$motherId.'][children_restock]['.$childId.']';
            $html .= '<tr>'.$celle.'<td colspan="2"></td><td class="text-center"><input type="hidden" name="'.$name.'" value="">'
                .'<input class="form-check-input" type="checkbox" name="'.$name.'" value="on" data-wi-child-of="'.$motherId.'"'
                .(!empty($child['restock_default']) ? ' checked' : '').' aria-label="Rientra a magazzino: '.$nome.'"></td></tr>';
        }

        return $html;
    }

    /**
     * Le caselle dei componenti come arrivano dal modulo: la spunta assente o
     * vuota vuol dire «non rientra». Chiavi che non sono un id si scartano.
     *
     * @return array<int, bool>
     */
    public static function childrenRestockFrom(mixed $boxes): array
    {
        $restock = [];

        foreach (is_array($boxes) ? $boxes : [] as $childId => $value) {
            if ((int) $childId > 0) {
                $restock[(int) $childId] = (string) $value !== '';
            }
        }

        return $restock;
    }

    /** Le personalizzazioni della riga sotto il nome, escapate una volta. */
    private static function customizationLines(array $line): string
    {
        $html = '';

        foreach (Customizations::lines($line) as $riga) {
            $html .= '<div class="text-muted small">'.OrderSheet::esc($riga).'</div>';
        }

        return $html;
    }

    /**
     * La scelta della sede: solo se ce n'è più d'una. Con una sede sola il reso
     * rientra lì e il campo non compare (come per le rettifiche).
     *
     * @param list<array{id: int, label: string}> $locations
     */
    public static function locationField(array $locations, int $selected = 0): string
    {
        if (count($locations) < 2) {
            return '';
        }

        $opzioni = [];

        foreach ($locations as $location) {
            $opzioni[(int) $location['id']] = (string) $location['label'];
        }

        return '<div class="mb-3">'.FormField::key('location_id')
            ->select($opzioni)
            ->label('Sede in cui rientra la merce')
            ->value((string) ($selected > 0 ? $selected : array_key_first($opzioni)))
            ->render('bootstrap').'</div>';
    }

    /**
     * Registra il reso e dice com'è andata.
     *
     * Il rifiuto è l'esito, non un'eccezione: la frase arriva sulla stessa
     * pagina. Una quantità vuota è una riga non resa; la spunta assente dal
     * modulo vuol dire «non rientra», la chiave mancante «come dice il motivo».
     *
     * @param array<string, mixed> $values lines[order_item_id][quantity|reason|restock|children_restock[id]], location_id, internal_note
     * @return array{ok: bool, message: string}
     */
    public static function run(int $orderId, array $values, int $userId): array
    {
        $ordine = $orderId > 0 ? Order::findById($orderId) : null;

        if (!is_array($ordine) || $ordine === []
            || (string) ($ordine['deleted'] ?? 'false') === 'true'
            || (string) ($ordine['stage'] ?? '') !== 'order') {
            return ['ok' => false, 'message' => 'Ordine non trovato.'];
        }

        $righe = [];

        foreach ((array) ($values['lines'] ?? []) as $itemId => $campi) {
            if (!is_array($campi)) {
                continue;
            }

            $righe[] = [
                'order_item_id' => (int) $itemId,
                'quantity' => $campi['quantity'] ?? '',
                'reason' => (string) ($campi['reason'] ?? ''),
                'restock' => array_key_exists('restock', $campi) ? (string) $campi['restock'] !== '' : null,
                'children_restock' => static::childrenRestockFrom($campi['children_restock'] ?? []),
            ];
        }

        try {
            $esito = Returns::register($orderId, $righe, [
                'location_id' => (int) ($values['location_id'] ?? 0),
                'internal_note' => (string) ($values['internal_note'] ?? ''),
                'source' => 'user',
                'user_id' => $userId,
            ]);
        } catch (UserError $error) {
            return ['ok' => false, 'message' => $error->getMessage()];
        }

        $totale = 0.0;
        $rientrati = 0.0;
        $voci = static::itemsOf($esito['return_id']);
        $madri = [];

        foreach ($voci as $voce) {
            $riga = OrderItem::findById((int) ($voce['order_item_id'] ?? 0));
            $madri[(int) (is_array($riga) ? ($riga['parent_item_id'] ?? 0) : 0)] = true;
        }

        // Di una confezione contano i pezzi dei componenti, non la riga che li raccoglie.
        foreach ($voci as $voce) {
            if (isset($madri[(int) $voce['order_item_id']])) {
                continue;
            }

            $totale += (float) $voce['quantity'];
            $rientrati += (string) $voce['restock'] === 'true' ? (float) $voce['quantity'] : 0.0;
        }

        return ['ok' => true, 'message' => 'Registrato il reso '.$esito['number'].' di '.static::piecesText($totale)
            .': '.static::piecesText($rientrati, true).' a magazzino.'];
    }

    /**
     * Chiude o annulla un reso già registrato.
     *
     * @param string $action `complete` o `cancel`
     * @return array{ok: bool, message: string}
     */
    public static function runStatus(string $action, int $returnId, int $userId): array
    {
        if (!in_array($action, ['complete', 'cancel'], true)) {
            return ['ok' => false, 'message' => 'Azione non valida.'];
        }

        $reso = $returnId > 0 ? SalesReturn::findById($returnId) : null;
        $numero = is_array($reso) ? trim((string) ($reso['number'] ?? '')) : '';
        $options = ['source' => 'user', 'user_id' => $userId];

        try {
            $action === 'complete' ? Returns::complete($returnId, $options) : Returns::cancel($returnId, $options);
        } catch (UserError $error) {
            return ['ok' => false, 'message' => $error->getMessage()];
        }

        return ['ok' => true, 'message' => 'Reso '.$numero.($action === 'complete' ? ' chiuso.' : ' annullato.')];
    }

    /**
     * Salva dalla pagina, o esegue «Chiudi»/«Annulla» della tabella: i rifiuti
     * tornano con la frase, il successo alla scheda.
     */
    public static function submitFormPage(array $values): string
    {
        $orderId = (int) ($values['order_id'] ?? 0);
        $back = StockAdjustmentResource::backUrlFrom($values['back'] ?? '');
        $user = LegacyGlobals::get('USER');
        $userId = is_object($user) ? (int) ($user->id ?? 0) : 0;
        $status = (string) ($values['action'] ?? '') !== '';
        $result = $status
            ? static::runStatus((string) $values['action'], (int) ($values['return_id'] ?? 0), $userId)
            : static::run($orderId, $values, $userId);

        if (headers_sent()) {
            return $result['message'];
        }

        if ($result['ok']) {
            FlashAlert::saved($result['message']);
        } else {
            FlashAlert::custom('Attenzione', $result['message'], 'warning');
        }

        $detail = OrderResource::detailUrl($orderId, $back !== '' ? $back : null);
        header('Location: '.($result['ok'] || $status ? $detail : static::urlFor($orderId, $back)));

        exit();
    }

    /** Il numero scritto bene: intero se è intero, con la virgola altrimenti. */
    private static function pieces(float $value, string $separator = ','): string
    {
        return rtrim(rtrim(number_format($value, 3, $separator, ''), '0'), $separator);
    }

    private static function piecesText(float $value, bool $participle = false): string
    {
        $numero = static::pieces($value);
        $uno = abs($value - 1.0) < 0.0005;

        return $participle
            ? $numero.($uno ? ' rientrato' : ' rientrati')
            : $numero.($uno ? ' pezzo' : ' pezzi');
    }

    /** @return list<array<string, mixed>> */
    private static function itemsOf(int $returnId): array
    {
        try {
            $rows = SalesReturnItem::find(['sales_return_id' => $returnId, 'deleted' => 'false']);
        } catch (Throwable) {
            return [];
        }

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }

    private static function reasonOptions(): string
    {
        $html = '';

        foreach (ReturnRules::REASON_LABELS as $reason => $label) {
            $html .= '<option value="'.$reason.'" data-restock="'.(ReturnRules::defaultRestock($reason) ? '1' : '0').'"'
                .($reason === ReturnRules::DEFAULT_REASON ? ' selected' : '').'>'.OrderSheet::esc($label).'</option>';
        }

        return $html;
    }

    /** @return array<string, mixed> */
    private static function currentOrder(): array
    {
        $id = (int) ($_GET['ordine'] ?? $_POST['order_id'] ?? 0);
        $ordine = $id > 0 ? Order::findById($id) : null;

        return is_array($ordine) && $ordine !== [] ? $ordine : [];
    }
}
