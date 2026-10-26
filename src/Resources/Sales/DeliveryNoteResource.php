<?php

namespace Wonder\Plugin\Gestionale\Resources\Sales;

use Throwable;
use Wonder\App\LegacyGlobals;
use Wonder\App\Permission\Access;
use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\Input;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\App\ResourceSchema\TableLayoutSchema;
use Wonder\Backend\Support\FlashAlert;
use Wonder\Elements\Components\Badge;
use Wonder\Elements\Components\Button;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\DataItem;
use Wonder\Elements\Components\RichText;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\Http\Csrf;
use Wonder\Http\Route;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Sales\DeliveryNote;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Resources\System\SettingResource;
use Wonder\Plugin\Gestionale\Support\Documents\DeliveryNotePdf;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;
use Wonder\Plugin\Gestionale\Support\Sales\DeliveryNotes;

/**
 * *Vendite → DDT* (G9 §4.2): l'elenco dei documenti di trasporto e la loro
 * pagina, bozza da completare o emesso da consultare. Un DDT nasce dalla
 * scheda dell'ordine (*Crea DDT*), mai da qui.
 *
 * Il POST passa da `mutateRequestValues()` e non torna al core: lo scrive
 * `DeliveryNotes`.
 */
final class DeliveryNoteResource extends GestionaleResource
{
    public static string $feature = 'delivery_notes';
    public static string $model = DeliveryNote::class;
    public static string $docsPage = 'vendite/vendite-ddt';

    /** Gli stati, come si leggono. */
    public const STATUS_LABELS = [
        'draft' => ['Bozza', 'secondary'],
        'issued' => ['Emesso', 'success'],
        'cancelled' => ['Annullato', 'danger'],
    ];

    /** @var array<int, array<string, mixed>> */
    private static array $orders = [];

    public static function path(): string
    {
        return 'app/gestionale/ddt';
    }

    public static function icon(): string
    {
        return 'bi-file-earmark-text';
    }

    public static function titleLabel(): string
    {
        return 'DDT';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'DDT',
            'plural_label' => 'DDT',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'i',
            'this' => 'questo',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'number' => 'Numero',
            'date' => 'Data',
            'customer' => 'Cliente',
            'order_id' => 'Ordine',
            'status' => 'Stato',
        ];
    }

    public static function formSchema(): array
    {
        return [];
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('number')
                ->text()
                ->size('little')
                ->link('edit')
                ->formatter(static fn (array $row): string => trim((string) ($row['number'] ?? '')) !== ''
                    ? static::escape((string) $row['number'])
                    : '<span class="text-body-secondary">Bozza</span>'),
            TableColumn::key('date')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(substr(OrderSheet::date((string) ($row['date'] ?? '')), 0, 10))),
            TableColumn::key('customer')
                ->text()
                ->formatter(static fn (array $row): string => static::escape(static::customerOf($row))),
            TableColumn::key('order_id')
                ->text()
                ->size('little')
                ->hiddenDevice('mobile')
                ->formatter(static fn (array $row): string => static::orderLink((int) ($row['order_id'] ?? 0))),
            TableColumn::key('status')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::badge((string) ($row['status'] ?? ''))),
            TableColumn::key('actions')->button()->actions(['edit']),
        ];
    }

    public static function tableLayoutSchema(): TableLayoutSchema
    {
        $stati = ['' => 'Tutti'];

        foreach (self::STATUS_LABELS as $key => [$label]) {
            $stati[$key] = $label;
        }

        return TableLayoutSchema::for(static::class)
            ->title('DDT')
            ->results()
            ->hideButtonAdd()
            ->filterSearch()
            ->searchFields(['number', 'billing_name', 'billing_surname', 'billing_business_name'])
            ->filterCustom('Stato', 'status', $stati);
    }

    /** @return array<string, mixed> */
    public static function querySchema(): array
    {
        $schema = parent::querySchema();
        $schema['condition'] = "deleted = 'false'";
        $schema['order'] = ['column' => 'creation', 'direction' => 'DESC'];

        return $schema;
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()
            ->only(['list', 'edit', 'update'])
            ->titles(['list' => 'DDT', 'edit' => 'DDT'])
            ->subtitles(['list' => 'I documenti di trasporto: nascono dalla scheda di un ordine confermato con «Crea DDT». Non muovono il magazzino.'])
            ->view('form', Gestionale::viewPath('pages/delivery-note-form.php'));
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->inSection('vendite')
            ->title('DDT')
            ->order(25)
            ->access(Gestionale::area('vendite'))
            ->enabled(static::featureActive());
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->access(Gestionale::area('vendite'), ['list', 'edit', 'update']);
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
    }

    /** Le righe e l'ordine per la pagina. */
    public static function mutateFormValues(array $values, string $mode, string $context = 'backend'): array
    {
        $id = (int) ($values['id'] ?? 0);
        $values['lines'] = $id > 0 ? DeliveryNotes::items($id) : [];
        $values['order'] = static::orderOf((int) ($values['order_id'] ?? 0));

        return $values;
    }

    /**
     * La pagina del DDT con i componenti del core: documento, luogo di
     * consegna, trasporto, righe e pulsanti, in `Card` con `FormField`. Da
     * emesso o annullato i campi sono spenti. I pulsanti postano `intent`;
     * quelli che non tornano indietro chiedono conferma con la lib.
     *
     * @param array<string, mixed> $values i valori di `mutateFormValues()`
     */
    public static function formLayoutFor(array $values): Form
    {
        $status = (string) ($values['status'] ?? 'draft');
        $draft = $status === 'draft';
        $v = static fn (string $key): string => html_entity_decode((string) ($values[$key] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $campo = static function (Input $field, int $span) use ($draft): Input {
            $field->columnSpan(['default' => 12, 'md' => $span]);

            return $draft ? $field : $field->disabled();
        };
        $riquadro = static fn (string $titolo, string $aiuto, array $componenti): Card => (new Card)->components([
            $aiuto === '' ? SectionTitle::make($titolo)->columnSpan(12) : SectionTitle::make($titolo)->tooltip($aiuto)->columnSpan(12),
            ...$componenti,
        ])->columns(12)->columnSpan(12);
        $numero = static fn (mixed $value): string => (float) $value > 0 ? OrderSheet::number($value) : '';
        $partenza = substr(str_replace(' ', 'T', (string) ($values['transport_started_at'] ?? '')), 0, 16);
        $order = (array) ($values['order'] ?? []);
        $dato = static fn (string $etichetta, string $valore, bool $html = false): DataItem => DataItem::make($etichetta, $valore)
            ->html($html)
            ->columnSpan(['default' => 6, 'md' => 4]);

        return (new Form)->components([
            $riquadro('Documento', 'Il DDT accompagna la merce: non muove il magazzino, che è già uscito alla conferma dell\'ordine. Emesso, prende numero e data e non si modifica più.', [
                $dato('Stato', static::badge($status), true),
                $dato('Numero', trim((string) ($values['number'] ?? '')) ?: '—'),
                $dato('Data', substr(OrderSheet::date((string) ($values['date'] ?? '')), 0, 10) ?: '—'),
                $dato('Ordine', $order !== []
                    ? '<a href="'.static::escape(OrderResource::detailUrl((int) $order['id'])).'">'.static::escape((string) ($order['order_number'] ?? '')).'</a>'
                    : '—', true),
                $dato('Destinatario', static::customerOf($values) ?: '—'),
            ]),
            $riquadro('Luogo di consegna', 'Dove va la merce: parte dall\'ordine e si può cambiare finché il DDT è in bozza.', [
                $campo(FormField::key('shipping_business_name')->text()->label('Ragione sociale / Azienda')->value($v('shipping_business_name')), 12),
                $campo(FormField::key('shipping_name')->text()->label('Destinatario: nome')->value($v('shipping_name')), 3),
                $campo(FormField::key('shipping_surname')->text()->label('Cognome')->value($v('shipping_surname')), 3),
                $campo(FormField::key('shipping_street')->text()->label('Via')->value($v('shipping_street')), 4),
                $campo(FormField::key('shipping_number')->text()->label('Civico')->value($v('shipping_number')), 2),
                $campo(FormField::key('shipping_cap')->text()->label('CAP')->value($v('shipping_cap')), 2),
                $campo(FormField::key('shipping_city')->text()->label('Città')->value($v('shipping_city')), 4),
                $campo(FormField::key('shipping_province')->text()->label('Provincia')->value($v('shipping_province')), 3),
                $campo(FormField::key('shipping_country')->text()->label('Paese')->value($v('shipping_country') ?: 'IT'), 3),
            ]),
            $riquadro('Trasporto', 'I valori di partenza si scelgono in Impostazioni → DDT. L\'inizio del trasporto vuoto è il momento dell\'emissione.', [
                $campo(FormField::key('transport_by')->select(DeliveryNotePdf::TRANSPORT_BY)->label('A cura del')->value((string) ($values['transport_by'] ?? 'sender')), 3),
                $campo(FormField::key('carrier_id')->select(['0' => 'Nessuno'] + SettingResource::carriers())->label('Vettore')->value((string) ($values['carrier_id'] ?? '0')), 3),
                $campo(FormField::key('transport_started_at')->textDatetime()->label('Inizio trasporto')->value(str_starts_with($partenza, '0000') ? '' : $partenza), 3),
                $campo(FormField::key('freight_terms')->select(DeliveryNotePdf::FREIGHT_TERMS)->label('Porto')->value((string) ($values['freight_terms'] ?? 'prepaid')), 3),
                $campo(FormField::key('goods_appearance')->text()->label('Aspetto dei beni')->value($v('goods_appearance')), 3),
                $campo(FormField::key('packages_count')->number()->label('Colli')->value((int) ($values['packages_count'] ?? 0) > 0 ? (string) (int) $values['packages_count'] : ''), 3),
                $campo(FormField::key('gross_weight')->text()->label('Peso lordo (kg)')->value($numero($values['gross_weight'] ?? 0))->attribute('inputmode="decimal"'), 3),
                $campo(FormField::key('net_weight')->text()->label('Peso netto (kg)')->value($numero($values['net_weight'] ?? 0))->attribute('inputmode="decimal"'), 3),
                $campo(FormField::key('document_note')->textarea()->label('Note')->value($v('document_note')), 12),
            ]),
            $riquadro('Righe', $draft ? 'Le righe vengono dall\'ordine, una per lotto. Abbassa la quantità per consegnare una parte; zero toglie la riga. Il resto si mette in un altro DDT.' : '', static::lineComponents((array) ($values['lines'] ?? []), $draft)),
            (new Card)->components([
                RichText::make(static::buttonsHtml($values))->tag('div')->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ])->columns(12);
    }

    /**
     * Una riga del DDT: descrizione, codice e lotto, e la quantità da
     * consegnare (`qty_<riga>`), modificabile solo in bozza.
     *
     * @param list<array<string, mixed>> $lines
     * @return list<object>
     */
    private static function lineComponents(array $lines, bool $draft): array
    {
        if ($lines === []) {
            return [RichText::make('<p class="text-body-secondary mb-0">Nessuna riga.</p>')->tag('div')->columnSpan(12)];
        }

        $out = [];

        foreach ($lines as $line) {
            $descrizione = static::escapeStored((string) ($line['description'] ?? ''));
            $dettagli = array_filter([
                trim((string) ($line['sku'] ?? '')) !== '' ? 'Codice '.static::escapeStored((string) $line['sku']) : '',
                trim((string) ($line['batch_number'] ?? '')) !== '' ? 'Lotto '.static::escapeStored((string) $line['batch_number']) : '',
            ]);
            $unita = trim((string) ($line['unit'] ?? ''));
            $quantita = OrderSheet::number($line['quantity'] ?? 0);

            $out[] = RichText::make('<div class="fw-semibold">'.$descrizione.'</div>'
                .($dettagli !== [] ? '<div class="small text-body-secondary">'.implode(' · ', $dettagli).'</div>' : ''))
                ->tag('div')
                ->columnSpan(['default' => 12, 'md' => 8]);
            $out[] = $draft
                ? FormField::key('qty_'.(int) $line['id'])->text()
                    ->label('Quantità'.($unita !== '' ? ' ('.$unita.')' : ''))
                    ->value($quantita)
                    ->attribute('inputmode="decimal"')
                    ->columnSpan(['default' => 12, 'md' => 4])
                : DataItem::make('Quantità', trim($quantita.' '.$unita))->columnSpan(['default' => 12, 'md' => 4]);
        }

        return $out;
    }

    /**
     * I pulsanti in fondo: in bozza *Salva bozza* (il primo, così Invio
     * salva), *Emetti* ed *Elimina bozza*; da emesso *Annulla DDT*, *Invia per
     * email* e il PDF. Postano `intent`; le azioni che non tornano indietro
     * chiedono conferma con la lib, e l'isola di salvataggio copia solo
     * *Salva bozza* ed *Emetti*.
     */
    private static function buttonsHtml(array $values): string
    {
        $id = (int) ($values['id'] ?? 0);
        $status = (string) ($values['status'] ?? 'draft');
        $invia = static fn (string $intent, string $label, string $variant, bool $outline, string $icon = ''): Button => ($icon === ''
            ? Button::make($label)
            : Button::make($label)->icon($icon))
            ->type('submit')
            ->attr('name', 'intent')
            ->attr('value', $intent)
            ->variant($variant)
            ->outline($outline)
            ->schema('inline', true);
        $pulsanti = [];

        if ($status === 'draft') {
            $pulsanti[] = $invia('save', 'Salva bozza', 'dark', true);
            $pulsanti[] = $invia('issue', 'Emetti', 'dark', false, 'bi bi-check2-circle')
                ->confirm('Il DDT prende numero e data e si archivia il PDF; l\'ordine risulta evaso per queste righe. Dopo non si modifica più: si può solo annullare.', title: 'Emetti il DDT', ok: 'Emetti', variant: 'primary');
            $pulsanti[] = $invia('delete', 'Elimina bozza', 'danger', true, 'bi bi-trash')
                ->attr('class', 'order-first me-auto')
                ->attr('data-wi-save-bar-ignore', true)
                ->confirm('La bozza del DDT si elimina: le righe restano da consegnare.', title: 'Elimina bozza', ok: 'Elimina');
        } elseif ($status === 'issued') {
            $pulsanti[] = $invia('send', 'Invia per email', 'secondary', true, 'bi bi-envelope')
                ->attr('data-wi-save-bar-ignore', true)
                ->confirm('Il cliente riceve il DDT in PDF all\'indirizzo dell\'ordine.', title: 'Invia per email', ok: 'Invia', variant: 'primary');
            $pulsanti[] = $invia('cancel', 'Annulla DDT', 'danger', true, 'bi bi-x-circle')
                ->attr('class', 'order-first me-auto')
                ->attr('data-wi-save-bar-ignore', true)
                ->confirm('Il DDT resta con il suo numero, annullato: le sue righe tornano da consegnare e l\'evasione dell\'ordine torna indietro.', title: 'Annulla DDT', ok: 'Annulla DDT');
        }

        if ($status !== 'draft' && trim((string) ($values['pdf_file'] ?? '')) !== '') {
            $pulsanti[] = Button::to(static::pdfUrl($id), 'PDF')->icon('bi bi-file-earmark-pdf')->variant('dark')->blank()->schema('inline', true);
        }

        return '<div class="d-flex flex-wrap align-items-center justify-content-end gap-2">'
            .implode('', array_map(static fn (Button $b): string => $b->render('bootstrap'), $pulsanti))
            .'</div>';
    }

    /**
     * *Salva*, *Emetti*, *Elimina bozza*, *Annulla DDT*, *Invia per email*.
     * Non restituisce mai i valori al core.
     */
    public static function mutateRequestValues(array $values, string $action, string $context = 'backend', ?array $oldValues = null): array
    {
        $id = (int) ($oldValues['id'] ?? 0);
        $result = Csrf::verify()
            ? static::handleSubmit($values, $id, static::currentUserId())
            : ['ok' => false, 'message' => UserError::make('stock_document.expired')->getMessage(), 'url' => static::editUrl($id)];

        if ($result['ok']) {
            FlashAlert::saved($result['message']);
        } else {
            FlashAlert::custom('Attenzione', $result['message'], 'warning');
        }

        if (!headers_sent()) {
            header('Location: '.$result['url']);
        }

        exit();
    }

    /**
     * @param array<string, mixed> $post
     * @return array{ok: bool, message: string, url: string}
     */
    public static function handleSubmit(array $post, int $id, int $userId): array
    {
        $intent = (string) ($post['intent'] ?? 'save');
        $edit = static::editUrl($id);
        $options = ['user_id' => $userId, 'source' => 'user'];

        try {
            if ($intent === 'delete') {
                DeliveryNotes::delete($id);

                return ['ok' => true, 'message' => 'Bozza del DDT eliminata.', 'url' => static::listUrl()];
            }

            if ($intent === 'cancel') {
                DeliveryNotes::cancel($id, $options);

                return ['ok' => true, 'message' => 'DDT annullato: le sue righe tornano da consegnare.', 'url' => $edit];
            }

            if ($intent === 'send') {
                return ['ok' => true, 'url' => $edit, 'message' => match (DeliveryNotes::send($id)) {
                    'sent' => 'DDT inviato al cliente con il PDF.',
                    'no_recipients' => 'Il cliente non ha un\'email: scarica il PDF e mandalo tu.',
                    default => 'L\'email non è partita: scarica il PDF e mandalo tu.',
                }];
            }

            DeliveryNotes::saveDraft($id, static::header($post), static::quantities($post));

            if ($intent === 'issue') {
                $issued = DeliveryNotes::issue($id, $options);

                return ['ok' => true, 'message' => 'DDT '.$issued['number'].' emesso.', 'url' => $edit];
            }

            return ['ok' => true, 'message' => 'Bozza del DDT salvata.', 'url' => $edit];
        } catch (UserError $error) {
            return ['ok' => false, 'message' => $error->getMessage(), 'url' => $id > 0 ? $edit : static::listUrl()];
        }
    }

    /** Il trasporto e la destinazione dalla pagina. @return array<string, string> */
    public static function header(array $post): array
    {
        $out = [];

        foreach ($post as $key => $value) {
            $key = (string) $key;

            if (!is_scalar($value)) {
                continue;
            }

            if (in_array($key, ['transport_by', 'carrier_id', 'transport_started_at', 'goods_appearance', 'packages_count', 'gross_weight', 'net_weight', 'freight_terms', 'document_note'], true)
                || str_starts_with($key, 'shipping_')) {
                $out[$key] = (string) $value;
            }
        }

        return $out;
    }

    /** Le quantità delle righe: `qty_<riga>`. @return array<int, string> */
    public static function quantities(array $post): array
    {
        $out = [];

        foreach ($post as $key => $value) {
            if (is_string($key) && preg_match('/^qty_(\d+)$/', $key, $m) === 1 && is_scalar($value)) {
                $out[(int) $m[1]] = trim((string) $value);
            }
        }

        return $out;
    }

    public static function badge(string $status): string
    {
        [$label, $color] = self::STATUS_LABELS[$status] ?? [$status, 'secondary'];

        return Badge::make($label)->variant($color)->inline()->render('bootstrap');
    }

    /** Il destinatario, come nel DDT. */
    public static function customerOf(array $row): string
    {
        $business = trim(html_entity_decode((string) ($row['billing_business_name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $business !== '' ? $business : trim(html_entity_decode((string) ($row['billing_name'] ?? '').' '.(string) ($row['billing_surname'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public static function editUrl(int $id): string
    {
        return static::routeUrl('edit', '/backend/'.static::path().'/'.$id.'/edit/', ['id' => $id]);
    }

    public static function listUrl(): string
    {
        return static::routeUrl('list', '/backend/'.static::path().'/');
    }

    public static function pdfUrl(int $id): string
    {
        return static::routeUrl('pdf', '/backend/'.static::path().'/'.$id.'/pdf/', ['id' => $id]);
    }

    /** Il PDF del DDT emesso, dall'archivio. */
    public static function registerBackendRoutes(string $rootApp, string $slug): void
    {
        Route::get('/{id}/pdf/', Gestionale::handlerPath('backend/sales/delivery-note-pdf.php'), [
            'resource' => $slug,
            'resource_action' => 'pdf',
        ])->name('pdf')
            ->permit(Access::roles(Gestionale::area('vendite'), 'view'))
            ->where('id', '[0-9]+');
    }

    private static function orderLink(int $orderId): string
    {
        $order = static::orderOf($orderId);

        if ($order === []) {
            return '—';
        }

        return '<a href="'.static::escape(OrderResource::detailUrl($orderId)).'">'.static::escape((string) ($order['order_number'] ?? '#'.$orderId)).'</a>';
    }

    /** @return array<string, mixed> */
    private static function orderOf(int $orderId): array
    {
        if ($orderId <= 0) {
            return [];
        }

        if (!array_key_exists($orderId, static::$orders)) {
            try {
                $order = Order::findById($orderId);
            } catch (Throwable) {
                $order = null;
            }

            static::$orders[$orderId] = is_array($order) ? $order : [];
        }

        return static::$orders[$orderId];
    }

    private static function currentUserId(): int
    {
        $user = LegacyGlobals::get('USER');

        return is_object($user) ? (int) ($user->id ?? 0) : 0;
    }

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
