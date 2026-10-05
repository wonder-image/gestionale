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
use Wonder\Elements\Components\Modal;
use Wonder\Elements\Components\RichText;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Resources\Stock\StockAdjustmentResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;
use Wonder\Plugin\Gestionale\Support\Payments\Ledger;
use Wonder\Plugin\Gestionale\Support\Payments\PaymentStatus;

/**
 * «Registra pagamento»: il denaro arrivato a mano — il bonifico guardato
 * sull'estratto conto, i contanti al banco — scritto sull'ordine.
 *
 * Come `StockAdjustmentResource` è una pagina-form: la riga da pagare arriva
 * dall'indirizzo (`?ordine=`), non c'è un elenco da mostrare. Il lavoro vero
 * lo fa `Ledger::register()`: qui si leggono i campi, si controlla il residuo
 * e si scrive chi ha premuto.
 */
final class OrderPaymentResource extends NavigationOnlyResource
{
    /** L'id della finestra che la scheda dell'ordine apre dal pulsante. */
    public const MODAL_ID = 'wi-ordine-pagamento';

    /** L'aiuto accanto al titolo, uguale nella pagina e nella finestra. */
    private const HELP = 'L\'importo è quanto resta da incassare: scrivine meno per un acconto. La data è quella dell\'arrivo del denaro, non di oggi, se lo registri in ritardo. Il riferimento è il numero di CRO o di operazione, facoltativo.';

    public static function path(): string
    {
        return 'app/gestionale/ordine-pagamento';
    }

    public static function icon(): string
    {
        return 'bi-cash-coin';
    }

    public static function titleLabel(): string
    {
        return 'Registra pagamento';
    }

    public static function isFormPage(): bool
    {
        return true;
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

    /**
     * La finestra di «Registra pagamento» della scheda dell'ordine: gli stessi
     * quattro campi della pagina, e posta alla stessa rotta. Il metodo
     * dell'ordine è quello scelto, l'importo è il residuo, la data è oggi.
     *
     * @param array<string, mixed> $order
     * @param list<array<string, mixed>> $payments i pagamenti dell'ordine, per il residuo
     * @param array<int, string> $methods nome del metodo per id
     */
    public static function modal(array $order, array $payments, array $methods, string $back): string
    {
        $totale = (float) ($order['total'] ?? 0);
        $residuo = static::balanceOf($order, $payments);
        $predefinito = (int) ($order['payment_method_id'] ?? 0);
        $scelto = isset($methods[$predefinito]) ? $predefinito : (int) (array_key_first($methods) ?? 0);
        $riepilogo = '<b>Totale</b> '.OrderSheet::esc(OrderSheet::money($totale))
            .' · <b>Già incassato</b> '.OrderSheet::esc(OrderSheet::money(round($totale - $residuo, 2)))
            .' · <b>Residuo</b> '.OrderSheet::esc(OrderSheet::money($residuo));

        return Modal::make(trim('Registra pagamento: ordine '.trim((string) ($order['order_number'] ?? ''))))
            ->id(static::MODAL_ID)
            ->help(static::HELP)
            ->form(static::submitUrl(), hidden: ['order_id' => (int) ($order['id'] ?? 0), 'back' => $back])
            ->columns(12)
            ->components([
                RichText::make($riepilogo)->class('small mb-0')->columnSpan(12),
                FormField::key('amount')->text()->label('Importo (€)')->value(static::moneyField($residuo))
                    ->attribute('inputmode="decimal"')->required()->columnSpan(6),
                FormField::key('paid_at')->dateInput()->label('Data')->value(date('d/m/Y'))->required()->columnSpan(6),
                FormField::key('payment_method_id')->select($methods)->label('Metodo')->value((string) $scelto)->columnSpan(6),
                FormField::key('reference')->text()->label('Riferimento')->columnSpan(6),
            ])
            ->cancel('Indietro')
            ->submit('Registra', 'success')
            ->render('bootstrap');
    }

    /** I metodi di pagamento attivi, per id: la scelta della finestra. @return array<int, string> */
    public static function activeMethods(): array
    {
        $metodi = [];

        foreach (static::rowsOf(PaymentMethod::class, ['active' => 'true'], 'position') as $metodo) {
            $metodi[(int) $metodo['id']] = (string) ($metodo['name'] ?? '');
        }

        return $metodi;
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()
            ->only([])
            ->titles(['form' => 'Registra pagamento'])
            ->subtitles(['form' => 'Scrivi il denaro arrivato su questo ordine: resta tra i pagamenti e ne aggiorna lo stato.']);
    }

    /** Le pagine-form leggono `edit` (apertura) e `update` (invio): le azioni toccano denaro e magazzino, come l'elenco. */
    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->backend(['edit', 'update'], ['admin', 'administrator']);
    }

    public static function navigationSchema(): NavigationSchema
    {
        // Fuori dal menu: ci si arriva dal pulsante della scheda dell'ordine.
        return NavigationSchema::for(static::class)
            ->inSection('vendite')
            ->title('Registra pagamento')
            ->authority(['admin', 'administrator'])
            ->enabled(false);
    }

    public static function formSchema(): array
    {
        $ordine = static::currentOrder();
        $metodi = static::activeMethods();
        $predefinito = (int) ($ordine['payment_method_id'] ?? 0);

        return [
            FormField::key('amount')->text()->label('Importo (€)')->value(static::moneyField(static::balanceOf($ordine)))->required(),
            FormField::key('payment_method_id')
                ->select($metodi)
                ->value((string) (isset($metodi[$predefinito]) ? $predefinito : (array_key_first($metodi) ?? '')))
                ->label('Metodo'),
            FormField::key('paid_at')->dateInput()->value(date('d/m/Y'))->label('Data')->required(),
            FormField::key('reference')->text()->label('Riferimento'),
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
                    SectionTitle::make(static::titleFor($ordine))
                        ->tooltip(static::HELP)
                        ->columnSpan(12),
                    RichText::make(static::summaryLine($ordine))->columnSpan(12),
                    static::getInput('amount')->columnSpan(3),
                    static::getInput('payment_method_id')->columnSpan(3),
                    static::getInput('paid_at')->columnSpan(3),
                    static::getInput('reference')->columnSpan(3),
                    static::getInput('order_id')->columnSpan(12),
                    static::getInput('back')->columnSpan(12),
                ])->columns(12)->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ])->columns(12);
    }

    /**
     * Registra l'incasso e dice com'è andata.
     *
     * Il rifiuto è l'esito, non un'eccezione: la frase arriva al commerciante
     * sulla stessa pagina. L'incasso passa da `Ledger`, con `source = user` e il
     * proprio `user_id`, perché lo storico dica chi l'ha scritto. Il gateway è
     * quello del metodo scelto: un pagamento già aperto con lo stesso gateway
     * si chiude invece di farne un secondo.
     *
     * @param array<string, mixed> $values amount, payment_method_id, paid_at, reference
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

        try {
            return static::register($ordine, $values, $userId);
        } catch (UserError $error) {
            return ['ok' => false, 'message' => $error->getMessage()];
        }
    }

    /**
     * L'importo scritto da una persona: `12,50`, `12.50`, `1.250`, `1.250,50`, `40 €`.
     *
     * @return float|null null se non è un importo maggiore di zero
     */
    public static function amountFrom(mixed $value): ?float
    {
        $text = trim(str_replace(['€', ' ', "\u{a0}"], '', (string) ($value ?? '')));

        if ($text === '') {
            return null;
        }

        if (str_contains($text, ',')) {
            $text = str_replace(',', '.', str_replace('.', '', $text));
        } elseif (preg_match('/^[1-9]\d{0,2}(\.\d{3})+$/', $text) === 1) {
            // `1.250` all'italiana sono milleduecentocinquanta, non uno e venticinque.
            $text = str_replace('.', '', $text);
        }

        if (preg_match('/^\d+(\.\d+)?$/', $text) !== 1) {
            return null;
        }

        $amount = round((float) $text, 2);

        return $amount > 0 ? $amount : null;
    }

    /**
     * La data del selettore (`d/m/Y`) nel formato del libro (`Y-m-d`). Ciò che
     * non si riconosce passa com'è: lo rifiuta `Ledger`, con la sua frase.
     */
    public static function dateFrom(mixed $value): string
    {
        $text = trim((string) ($value ?? ''));

        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $text, $m) === 1) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }

        return $text;
    }

    /**
     * Quanto resta da incassare: il totale meno l'incassato, al netto dei
     * rimborsi. Un pagamento ancora in attesa non conta: non è denaro arrivato.
     *
     * @param array<string, mixed> $order
     * @param list<array<string, mixed>>|null $payments le righe; se mancano si leggono
     */
    public static function balanceOf(array $order, ?array $payments = null): float
    {
        $payments ??= static::rowsOf(Payment::class, ['order_id' => (int) ($order['id'] ?? 0)], 'id');
        $sums = PaymentStatus::sums($payments);

        return max(0.0, round((float) ($order['total'] ?? 0) - ($sums['paid'] - $sums['refunded']), 2));
    }

    /**
     * Salva dalla pagina: i rifiuti tornano sulla pagina con la frase, il
     * successo alla scheda.
     */
    public static function submitFormPage(array $values): string
    {
        $orderId = (int) ($values['order_id'] ?? 0);
        $back = StockAdjustmentResource::backUrlFrom($values['back'] ?? '');
        $user = LegacyGlobals::get('USER');
        $result = static::run($orderId, $values, is_object($user) ? (int) ($user->id ?? 0) : 0);

        if (headers_sent()) {
            return $result['message'];
        }

        if ($result['ok']) {
            FlashAlert::saved($result['message']);
            header('Location: '.OrderResource::detailUrl($orderId, $back !== '' ? $back : null));
        } else {
            FlashAlert::custom('Attenzione', $result['message'], 'warning');
            header('Location: '.static::urlFor($orderId, $back));
        }

        exit();
    }

    /**
     * @param array<string, mixed> $ordine
     * @param array<string, mixed> $values
     * @return array{ok: bool, message: string}
     */
    private static function register(array $ordine, array $values, int $userId): array
    {
        $orderId = (int) $ordine['id'];

        if ((string) $ordine['status'] === 'cancelled') {
            throw UserError::make('payment.order_closed');
        }

        $amount = static::amountFrom($values['amount'] ?? null);

        if ($amount === null) {
            throw UserError::make('payment.zero_amount');
        }

        $residuo = static::balanceOf($ordine);

        if ($residuo <= 0.004) {
            return ['ok' => false, 'message' => 'Quest\'ordine è già saldato: non c\'è niente da incassare.'];
        }

        if ($amount > $residuo + 0.004) {
            throw UserError::make('payment.over_balance', ['residuo' => OrderSheet::money($residuo)]);
        }

        $metodo = PaymentMethod::findById((int) ($values['payment_method_id'] ?? 0));
        $metodo = is_array($metodo) && $metodo !== [] ? $metodo : [];

        $esito = Ledger::register([
            'order_id' => $orderId,
            'amount' => $amount,
            'customer_id' => (int) ($ordine['customer_id'] ?? 0),
            'payment_method_id' => (int) ($metodo['id'] ?? $ordine['payment_method_id'] ?? 0),
            'currency' => (string) ($ordine['currency'] ?? 'EUR'),
            'provider' => (string) ($metodo['provider'] ?? 'manual'),
            'provider_reference' => trim((string) ($values['reference'] ?? '')),
            'paid_at' => static::dateFrom($values['paid_at'] ?? ''),
            'source' => 'user',
            'user_id' => $userId,
        ]);

        $resta = round($residuo - $amount, 2);
        $message = 'Registrato l\'incasso di '.OrderSheet::money($amount).' sull\'ordine '.trim((string) ($ordine['order_number'] ?? '')).'.';
        $message = rtrim(str_replace('  ', ' ', $message));
        $message .= $esito['payment_status'] === 'paid'
            ? ' L\'ordine risulta saldato.'
            : ' Restano da incassare '.OrderSheet::money($resta).'.';

        return ['ok' => true, 'message' => $message];
    }

    /** @return array<string, mixed> */
    private static function currentOrder(): array
    {
        $id = (int) ($_GET['ordine'] ?? $_POST['order_id'] ?? 0);
        $ordine = $id > 0 ? Order::findById($id) : null;

        return is_array($ordine) && $ordine !== [] ? $ordine : [];
    }

    /** @param array<string, mixed> $ordine */
    private static function titleFor(array $ordine): string
    {
        return $ordine === [] ? 'Registra pagamento' : 'Registra pagamento: ordine '.trim((string) ($ordine['order_number'] ?? ''));
    }

    /** La riga che dice a che punto è l'ordine, sopra le caselle. */
    private static function summaryLine(array $ordine): string
    {
        if ($ordine === []) {
            return '<span class="text-danger">Apri questa pagina dal pulsante «Registra pagamento» di un ordine.</span>';
        }

        $totale = (float) ($ordine['total'] ?? 0);
        $residuo = static::balanceOf($ordine);

        return '<b>Totale</b> '.OrderSheet::esc(OrderSheet::money($totale))
            .' · <b>Già incassato</b> '.OrderSheet::esc(OrderSheet::money(round($totale - $residuo, 2)))
            .' · <b>Residuo</b> '.OrderSheet::esc(OrderSheet::money($residuo));
    }

    /**
     * Le righe vive di un Model; senza database, vuoto.
     *
     * @param class-string<\Wonder\App\Model> $modelClass
     * @param array<string, mixed> $where
     * @return list<array<string, mixed>>
     */
    private static function rowsOf(string $modelClass, array $where = [], ?string $order = null): array
    {
        try {
            $rows = $modelClass::find(array_merge(['deleted' => 'false'], $where), null, $order, $order === null ? null : 'ASC');
        } catch (Throwable) {
            return [];
        }

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }

    /** L'importo nella casella: con la virgola, come lo si scrive. */
    private static function moneyField(float $value): string
    {
        return number_format($value, 2, ',', '');
    }
}
