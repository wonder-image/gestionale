<?php

namespace Wonder\Plugin\Gestionale\Resources\Shipping;

use Throwable;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\RepeaterColumn;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\Elements\Components\Alert;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\QuickCreateButton;
use Wonder\Elements\Components\RichText;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Shipping\Carrier;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingMethod;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingRate;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingRateBracket;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingZone;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Positions;
use Wonder\Plugin\Gestionale\Support\Sales\Channels;
use Wonder\Plugin\Gestionale\Support\Shipping\Carriers;
use Wonder\Plugin\Gestionale\Support\Shipping\RateForm;
use Wonder\Sql\Transaction;

/**
 * «Metodi di spedizione»: Standard, Espresso… Il metodo ha il nome e i tempi
 * che il cliente vede; il prezzo sta nei **listini**, uno per zona, che si
 * scrivono nella stessa pagina: un riquadro per ogni zona, con le sue
 * regole e la tabella degli scaglioni di peso.
 *
 * Il core non annida i repeater, quindi i campi dei listini sono piatti
 * (`rate_<zona>_<campo>`) e li legge, controlla e scrive `RateForm`.
 */
final class ShippingMethodResource extends GestionaleResource
{
    /** L'id del bottone vero di «Nuova zona…»: lo preme la voce del menu. */
    public const ZONE_BUTTON = 'wi-shipping-zone-new';

    public static string $feature = 'shipping';
    public static string $model = ShippingMethod::class;
    public static string $orderColumn = 'position';
    public static string $orderDirection = 'ASC';
    public static string $docsPage = 'spedizioni/spedizioni-listini';

    public static function path(): string
    {
        return 'app/gestionale/metodi-di-spedizione';
    }

    public static function icon(): string
    {
        return 'bi-box-seam';
    }

    public static function titleLabel(): string
    {
        return 'Metodi di spedizione';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'metodo',
            'plural_label' => 'metodi',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'i',
            'full' => 'attivo',
            'empty' => 'non attivo',
            'this' => 'questo',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'name' => 'Nome',
            'description' => 'Tempi di consegna',
            'carrier_id' => 'Corriere',
            'applies_online' => 'Sito',
            'applies_office' => 'Ufficio',
            'active' => 'Stato',
            'zones' => 'Zone',
        ];
    }

    public static function formSchema(): array
    {
        $fields = [
            FormField::key('name')->text()->label('Nome')->required(),
            FormField::key('description')->text()->label('Tempi di consegna'),
            FormField::key('carrier_id')
                ->select(['0' => '—'] + array_map('strval', static::carrierOptions()))
                ->value('0')
                ->label('Corriere'),
            FormField::key('applies_online')->toggle()->value(Channels::defaults()['applies_online'])->label('Sito'),
            FormField::key('applies_office')->toggle()->value(Channels::defaults()['applies_office'])->label('Ufficio'),
            FormField::key('active')
                ->select(['true' => 'Attivo', 'false' => 'Non attivo'])
                ->value('true')
                ->label('Stato'),
        ];

        foreach (static::zones() as $zone) {
            array_push($fields, ...static::rateFields((int) $zone['id']));
        }

        return $fields;
    }

    /**
     * I campi del listino di una zona. Il layout li mette in un contenitore
     * che si vede solo a listino acceso; qui restano le regole sul tipo di
     * prezzo: a scaglioni (a peso) o fisso, che non ha gli altri campi.
     *
     * @return list<object>
     */
    protected static function rateFields(int $zone): array
    {
        $name = static fn (string $field): string => 'rate_'.$zone.'_'.$field;
        $tiered = static fn (object $input): object => $input->visibleWhen('rate_'.$zone.'_price_type', 'brackets');

        return [
            // Nascosto: lo accende «Aggiungi zona» e lo spegne «Togli zona».
            FormField::key($name('on'))->hidden()->value('false'),
            FormField::key($name('price_type'))
                ->select(['fixed' => 'Fisso', 'brackets' => 'A scaglioni di peso'])
                ->value('fixed')
                ->label('Prezzo'),
            FormField::key($name('fixed_price'))
                ->price()
                ->decimal(2)
                ->label('Prezzo fisso')
                ->visibleWhen($name('price_type'), 'fixed'),
            $tiered(FormField::key($name('excess_mode'))
                ->select(['total_weight' => 'Tutto il peso', 'excess_only' => 'Solo l\'eccedenza'])
                ->value('total_weight')
                ->label('Oltre lo scaglione più alto, la tariffa al kg si applica a')),
            $tiered(FormField::key($name('volumetric_divisor'))->number()->integer()->label('Divisore volumetrico')),
            $tiered(FormField::key($name('fuel_surcharge_percent'))->number()->decimal(2)->suffix(' %')->value('0')->label('Maggiorazione carburante')),
            $tiered(FormField::key($name('markup_percent'))->number()->decimal(2)->suffix(' %')->value('0')->label('Margine')),
            $tiered(FormField::key($name('rounding_step'))->price()->decimal(2)->label('Arrotonda al')),
            $tiered(FormField::key($name('min_price'))->price()->decimal(2)->value('0')->label('Prezzo minimo')),
            FormField::key($name('free_over_amount'))->price()->decimal(2)->label('Gratis sopra'),
            $tiered(FormField::key($name('free_under_weight'))->number()->decimal(3)->suffix(' kg')->label('Gratis fino a')),
            FormField::key($name('cod_fee'))->price()->decimal(2)->value('0')->label('Commissione contrassegno'),
            $tiered(FormField::key($name('brackets'))
                ->repeater([
                    RepeaterColumn::key('type')
                        ->select(['price' => 'Prezzo fino a', 'excess' => 'Tariffa al kg oltre'])
                        ->value('price')
                        ->label('Scaglione')
                        ->columnSpan(5),
                    RepeaterColumn::key('max_weight')
                        ->number()
                        ->decimal(3)
                        ->suffix(' kg')
                        ->label('Peso massimo')
                        ->columnSpan(3),
                    RepeaterColumn::key('amount')->price()->decimal(2)->label('Importo')->columnFill(),
                ])
                ->nested()
                ->repeaterAddLabel('Aggiungi scaglione')
                ->repeaterDeleteTitle('Togli scaglione')
                ->repeaterDeleteText('Confermi di togliere questo scaglione dal listino?')
                ->repeaterDeleteCancelLabel('Annulla')
                ->repeaterDeleteConfirmLabel('Togli')
                ->repeaterDeleteConfirmClass('btn btn-danger')
                ->label('Scaglioni')),
        ];
    }

    public static function formLayoutSchema(): ?Form
    {
        $left = [];
        $zones = static::zones();
        $unweighted = RateForm::unweighted();

        if ($unweighted > 0) {
            $left[] = (new Container)->components([
                Alert::make(
                    ($unweighted === 1 ? 'Un articolo da spedire non ha il peso' : $unweighted.' articoli da spedire non hanno il peso')
                    .': per '.($unweighted === 1 ? 'lui' : 'loro').' il prezzo è quello dello scaglione più basso.',
                    'warning'
                ),
            ])->columnSpan(12);
        }

        $left[] = (new Card)->components([
            SectionTitle::make('Metodo')
                ->tooltip('Il nome e i tempi li vede il cliente («Standard», «2-3 giorni lavorativi»). Un metodo non attivo non si propone più.')
                ->columnSpan(12),
            static::getInput('name')->columnSpan(5),
            static::getInput('description')->columnSpan(4),
            static::getInput('active')->columnSpan(3),
            static::getInput('carrier_id')->columnSpan(5),
        ])->columns(12)->columnSpan(12);

        $left[] = static::zonesCard($zones);

        $side = [];

        // «Dove vale» c'è solo se il sito ha più di un canale acceso.
        if (Channels::choose()) {
            $side[] = (new Card)->components([
                SectionTitle::make('Dove vale')
                    ->tooltip('Il sito offre il metodo nel carrello. L\'ufficio lo offre negli ordini fatti dal gestionale.')
                    ->columnSpan(12),
                static::getInput('applies_online')->columnSpan(12),
                static::getInput('applies_office')->columnSpan(12),
            ])->columns(12)->columnSpan(12);
        }

        return (new Form)->components($side === [] ? [
            (new Container)->components($left)->columns(12)->columnSpan(12),
        ] : [
            (new Container)->components($left)->columns(12)->columnSpan(8),
            (new Container)->components($side)->columns(12)->columnSpan(4),
        ])->columns(12);
    }

    /**
     * Il riquadro del listino di una zona. Si vede solo se il metodo spedisce
     * verso quella zona: lo decide il campo nascosto `rate_<zona>_on`, che lo
     * script del menu «Aggiungi zona» accende e spegne. Spento il listino
     * resta salvato, e riaggiungendo la zona si ritrovano i suoi valori.
     *
     * @param array<string, mixed> $zone
     */
    protected static function zoneBlock(array $zone): Container
    {
        $id = (int) $zone['id'];
        $title = (string) ($zone['name'] ?? '');
        $name = static fn (string $field): string => 'rate_'.$id.'_'.$field;

        $remove = '<button type="button" class="btn btn-sm btn-link text-body-secondary p-0" data-wi-zone-remove="'.$id.'"'
            .' data-wi-confirm="'.static::escape('Il metodo non spedirà più verso «'.$title.'». Il listino resta salvato: se rimetti la zona lo ritrovi.').'"'
            .' data-wi-confirm-title="Togli zona" data-wi-confirm-ok="Togli" data-wi-confirm-cancel="Annulla" data-wi-confirm-variant="danger">'
            .'<i class="bi bi-x-lg me-1"></i>Togli zona</button>';

        return (new Container)
            ->components([
                SectionTitle::make($title)->columnSpan(9),
                RichText::make($remove)->tag('div')->class('text-end')->columnSpan(3),
                static::getInput($name('on'))->columnSpan(12),
                static::getInput($name('price_type'))->columnSpan(3),
                (new Container)->components([
                    static::getInput($name('fixed_price'))->columnSpan(12),
                ])->columns(12)->columnSpan(3)->visibleWhen($name('price_type'), 'fixed'),
                static::getInput($name('cod_fee'))->columnSpan(3),
                static::getInput($name('free_over_amount'))->columnSpan(3),
                // Col prezzo fisso gli scaglioni e tutto ciò che ne dipende spariscono.
                (new Container)->components([
                    static::getInput($name('brackets'))->columnSpan(12),
                    static::getInput($name('excess_mode'))->columnSpan(12),
                    static::getInput($name('min_price'))->columnSpan(3),
                    static::getInput($name('rounding_step'))->columnSpan(3),
                    static::getInput($name('fuel_surcharge_percent'))->columnSpan(3),
                    static::getInput($name('markup_percent'))->columnSpan(3),
                    static::getInput($name('free_under_weight'))->columnSpan(3),
                    static::getInput($name('volumetric_divisor'))->columnSpan(3),
                ])->columns(12)->columnSpan(12)->visibleWhen($name('price_type'), 'brackets'),
            ])
            ->attr('data-wi-zone', (string) $id)
            ->attr('data-wi-zone-name', $title)
            ->class('border-top pt-3')
            ->columns(12)
            ->columnSpan(12);
    }

    /**
     * L'unica card «Zone»: i listini delle zone che il metodo serve e, in
     * fondo, «+ Aggiungi zona». Il bottone vero della finestra «Nuova zona…» sta
     * prima del menu e resta nascosto: lo apre la voce del menu.
     *
     * @param list<array<string, mixed>> $zones
     */
    protected static function zonesCard(array $zones): Card
    {
        $components = [
            SectionTitle::make('Zone')
                ->tooltip('Un listino per ogni zona in cui il metodo spedisce. Il prezzo è fisso (uno solo, qualunque sia il peso) oppure a scaglioni di peso. A scaglioni è quello dello scaglione che copre il peso del carrello; oltre l\'ultimo vale la tariffa al kg. Poi si aggiungono carburante e margine, si arrotonda e si applica il minimo. Il peso conta il maggiore tra reale e volumetrico (lunghezza × larghezza × altezza ÷ divisore). Con «Gratis sopra» la spedizione è gratuita sopra quell\'importo di prodotti, con «Gratis fino a» sotto quel peso. «Togli zona» spegne il listino senza cancellarlo.')
                ->columnSpan(12),
        ];

        foreach ($zones as $zone) {
            $components[] = static::zoneBlock($zone);
        }

        array_push($components, ...static::zonePicker($zones));

        return (new Card)->components($components)->columns(12)->columnSpan(12);
    }

    /** Set-up → Zone di spedizione, dove si preparano le zone con più aree. */
    protected static function zonesUrl(): string
    {
        if (function_exists('__r')) {
            try {
                $url = (string) __r('backend.resource.'.ShippingZoneResource::slug().'.list');

                if ($url !== '') {
                    return $url;
                }
            } catch (Throwable) {
                // Rotta non registrata (test, comandi): resta il percorso.
            }
        }

        return '/backend/'.ShippingZoneResource::path().'/';
    }

    /**
     * «Aggiungi zona»: il menu delle zone che il metodo non serve ancora e, in
     * fondo, «Nuova zona…», che apre la finestra della zona. Come la scheda
     * tecnica del prodotto: la zona nata da qui si aggiunge al metodo subito.
     *
     * @param list<array<string, mixed>> $zones
     * @return list<object>
     */
    protected static function zonePicker(array $zones): array
    {
        $items = '';

        foreach ($zones as $zone) {
            $items .= '<li><button type="button" class="dropdown-item" data-wi-zone-add="'.(int) $zone['id'].'">'
                .static::escape((string) ($zone['name'] ?? '')).'</button></li>';
        }

        $list = static::escape(static::zonesUrl());
        $resource = json_encode(ShippingZoneResource::slug());
        $button = json_encode(static::ZONE_BUTTON);

        $picker = RichText::make(<<<HTML
<p class="text-body-secondary wi-zone-empty">Il metodo non spedisce ancora verso nessuna zona: aggiungine una.</p>
<div class="dropdown wi-zone-picker">
    <button type="button" class="btn btn-outline-secondary w-100 wi-zone-choose" style="border-style:dashed" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-plus-lg me-1"></i>Aggiungi zona
    </button>
    <ul class="dropdown-menu w-100">
        {$items}
        <li class="wi-zone-divider"><hr class="dropdown-divider"></li>
        <li><button type="button" class="dropdown-item" data-wi-zone-new="true"><i class="bi bi-plus-lg me-1"></i>Nuova zona…</button></li>
        <li><span class="dropdown-item-text small text-body-secondary">Una zona con più aree si prepara in <a href="{$list}">Set-up → Zone di spedizione</a>.</span></li>
    </ul>
</div>
<script>
    window.wiShippingZones = window.wiShippingZones || (function () {
        var RISORSA = {$resource};
        var BOTTONE = {$button};

        // La colonna della griglia che contiene il nodo: è quella che si
        // nasconde o si sposta.
        function colonna(nodo) {
            while (nodo && nodo.parentElement && !nodo.parentElement.classList.contains('row')) {
                nodo = nodo.parentElement;
            }

            return nodo;
        }

        function blocchi() {
            return Array.prototype.slice.call(document.querySelectorAll('[data-wi-zone]'));
        }

        function campoAcceso(nodo) {
            return nodo.querySelector('[name="rate_' + nodo.getAttribute('data-wi-zone') + '_on"]');
        }

        function acceso(nodo) {
            var campo = campoAcceso(nodo);

            return !!campo && campo.value === 'true';
        }

        // Il divisore del menu serve solo se sopra c'è una zona da scegliere,
        // e la frase «nessuna zona» solo se non se ne vede nessuna.
        function riordina() {
            var restano = Array.prototype.slice.call(document.querySelectorAll('[data-wi-zone-add]'))
                .some(function (voce) { return !voce.parentElement.classList.contains('d-none'); });
            var divisore = document.querySelector('.wi-zone-divider');
            var vuoto = document.querySelector('.wi-zone-empty');

            if (divisore) {
                divisore.classList.toggle('d-none', !restano);
            }

            if (vuoto) {
                vuoto.classList.toggle('d-none', blocchi().some(acceso));
            }
        }

        // Una zona accesa si vede e sparisce dal menu; spenta, il contrario.
        function imposta(nodo, on) {
            var campo = campoAcceso(nodo);

            if (campo) {
                campo.value = on ? 'true' : 'false';
            }

            colonna(nodo).classList.toggle('d-none', !on);

            var voce = document.querySelector('[data-wi-zone-add="' + nodo.getAttribute('data-wi-zone') + '"]');

            if (voce) {
                voce.parentElement.classList.toggle('d-none', on);
            }

            riordina();
        }

        function aggiungi(id) {
            var nodo = document.querySelector('[data-wi-zone="' + id + '"]');

            if (!nodo) {
                return;
            }

            imposta(nodo, true);

            // Il riquadro nuovo è la cosa da guardare: il cursore va sul suo primo campo.
            var prima = nodo.querySelector('select, input:not([type="hidden"])');

            colonna(nodo).scrollIntoView({ block: 'nearest', behavior: 'smooth' });

            if (prima) {
                prima.focus({ preventScroll: true });
            }
        }

        document.addEventListener('click', function (evento) {
            var bersaglio = evento.target && evento.target.closest
                ? evento.target.closest('[data-wi-zone-add], [data-wi-zone-new], [data-wi-zone-remove]')
                : null;

            if (!bersaglio) {
                return;
            }

            if (bersaglio.hasAttribute('data-wi-zone-new')) {
                var bottone = document.getElementById(BOTTONE);

                if (bottone) {
                    bottone.click();
                }

                return;
            }

            if (bersaglio.hasAttribute('data-wi-zone-remove')) {
                var nodo = document.querySelector('[data-wi-zone="' + bersaglio.getAttribute('data-wi-zone-remove') + '"]');

                if (nodo) {
                    imposta(nodo, false);

                    var scelta = document.querySelector('.wi-zone-choose');

                    if (scelta) {
                        scelta.focus({ preventScroll: true });
                    }
                }

                return;
            }

            aggiungi(bersaglio.getAttribute('data-wi-zone-add'));
        });

        // La zona nata dalla finestra non ha ancora il suo riquadro in pagina:
        // si legge dalla stessa pagina, che ora la conosce, e si porta qui
        // senza ricaricare, per non perdere quello che si sta scrivendo.
        document.addEventListener('wi:quick-create:created', function (evento) {
            var dettaglio = evento.detail || {};

            if (dettaglio.family !== 'button' || dettaglio.resource !== RISORSA) {
                return;
            }

            var riga = dettaglio.item || {};
            var id = parseInt(dettaglio.id || riga.id, 10);

            if (!(id > 0) || document.querySelector('[data-wi-zone="' + id + '"]')) {
                return;
            }

            var titolo = String(riga.name || dettaglio.label || '');

            fetch(window.location.href, { credentials: 'same-origin' })
                .then(function (risposta) { return risposta.text(); })
                .then(function (html) {
                    var pagina = new DOMParser().parseFromString(html, 'text/html');
                    var dentro = pagina.querySelector('[data-wi-zone="' + id + '"]');
                    var picker = document.querySelector('.wi-zone-picker');

                    if (!dentro || !picker) {
                        return;
                    }

                    var colonnaNuova = document.importNode(colonna(dentro), true);
                    var dove = colonna(picker);

                    dove.parentElement.insertBefore(colonnaNuova, dove);

                    // Gli script di un nodo importato non girano da soli.
                    colonnaNuova.querySelectorAll('script').forEach(function (vecchio) {
                        var nuovo = document.createElement('script');
                        nuovo.text = vecchio.textContent;
                        vecchio.parentNode.replaceChild(nuovo, vecchio);
                    });

                    // La sua voce nel menu, per quando la si toglie.
                    var divisore = document.querySelector('.wi-zone-divider');

                    if (divisore) {
                        var voce = document.createElement('li');
                        var tasto = document.createElement('button');
                        tasto.type = 'button';
                        tasto.className = 'dropdown-item';
                        tasto.setAttribute('data-wi-zone-add', String(id));
                        tasto.textContent = titolo;
                        voce.appendChild(tasto);
                        divisore.parentElement.insertBefore(voce, divisore);
                    }

                    if (typeof setAutonumeric === 'function') {
                        setAutonumeric(colonnaNuova);
                    }

                    if (typeof setConditional === 'function') {
                        setConditional(colonnaNuova);
                    }

                    aggiungi(id);
                });
        });

        var bottoneZona = document.getElementById(BOTTONE);

        if (bottoneZona) {
            colonna(bottoneZona).classList.add('d-none');
        }

        blocchi().forEach(function (nodo) { imposta(nodo, acceso(nodo)); });
        riordina();

        return true;
    })();
</script>
HTML)->tag('div');

        return [
            // Il bottone vero della finestra: lo apre la voce del menu, e lo
            // script ne nasconde la colonna. Sta prima dello script.
            QuickCreateButton::make(ShippingZoneResource::class)
                ->text('Nuova zona')
                ->label('name')
                ->layout(static fn (): Container => (new Container)
                    ->columns(12)
                    ->components(ShippingZoneResource::quickCreateFields()))
                ->size('sm')
                ->id(static::ZONE_BUTTON)
                ->columnSpan(12),
            $picker->columnSpan(12),
        ];
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('name')->text()->link('edit'),
            TableColumn::key('carrier_id')
                ->text()
                ->formatter(static fn (array $row): string => static::escape(static::carrierOptions()[(int) ($row['carrier_id'] ?? 0)] ?? '—')),
            TableColumn::key('zones')
                ->text()
                ->formatter(static fn (array $row): string => static::escape(static::zonesLabel((int) ($row['id'] ?? 0)))),
            TableColumn::key('active')
                ->booleanBadge()
                ->badgeOn('Attivo', 'bi-check-circle', 'success')
                ->badgeOff('Non attivo', 'bi-dash-circle', 'secondary')
                ->size('little'),
            TableColumn::key('actions')->button()->actions([
                'edit',
                // L'etichetta segue lo stato della riga; il core inverte la colonna.
                'active' => ['label' => ['true' => 'Disattiva', 'false' => 'Attiva'], 'request' => 'boolean'],
                'delete',
            ]),
        ];
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()
            ->disable(['view'])
            ->titles([
                'list' => 'Metodi di spedizione',
                'create' => 'Nuovo metodo',
                'edit' => 'Modifica metodo',
            ]);
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->backendCrud(['admin', 'administrator']);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return parent::navigationSchema()
            ->inSection('set-up')
            ->group('spedizioni', 'Spedizioni', 70, ['admin', 'administrator'])
            ->title('Metodi di spedizione')
            ->order(10)
            ->authority(['admin', 'administrator']);
    }

    /** I nomi delle zone con un listino acceso, per l'elenco. */
    public static function zonesLabel(int $methodId): string
    {
        $names = [];

        foreach (static::rowsOf(ShippingRate::class, ['shipping_method_id' => $methodId, 'active' => 'true']) as $rate) {
            $zone = ShippingZone::findById((int) $rate['shipping_zone_id']);

            if (is_array($zone) && ($zone['deleted'] ?? 'false') !== 'true') {
                $names[] = (string) ($zone['name'] ?? '');
            }
        }

        return $names === [] ? '—' : implode(', ', $names);
    }

    /**
     * I listini si leggono e si controllano qui, prima che si scriva qualcosa:
     * un listino che non sta in piedi ferma il salvataggio con la sua frase.
     * Nessun campo `rate_*` è una colonna: li scrive `afterStore`/`afterUpdate`.
     * La posizione la mette il backend, in fondo all'elenco.
     */
    public static function mutateRequestValues(
        array $values,
        string $action,
        string $context = 'backend',
        ?array $oldValues = null
    ): array {
        $values = Channels::keepHidden($values, $oldValues);
        // Il metodo non è offerto alla cassa: la colonna non esiste.
        unset($values['applies_pos']);
        $values['carrier_id'] = max(0, (int) ($values['carrier_id'] ?? 0));

        foreach (array_keys($values) as $key) {
            if (str_starts_with((string) $key, 'rate_')) {
                unset($values[$key]);
            }
        }

        $names = [];

        foreach (static::zones() as $zone) {
            $names[(int) $zone['id']] = (string) ($zone['name'] ?? '');
        }

        $rates = RateForm::readRates((array) $_POST);
        // Le zone che non esistono (più) si ignorano: non si controllano.
        RateForm::validate(array_intersect_key($rates, $names), $names);

        if ($action === 'store') {
            $values['position'] = Positions::next(ShippingMethod::$table);
        } else {
            unset($values['position']);
        }

        return $values;
    }

    /** Riempie il form con i listini salvati. */
    public static function mutateFormValues(array $values, string $mode, string $context = 'backend'): array
    {
        $id = (int) ($values['id'] ?? 0);

        if ($mode === 'edit' && $id > 0) {
            $values = RateForm::loadRates($id) + $values;
        }

        return $values;
    }

    public static function afterStore(object $result, array $values = []): void
    {
        $id = (int) ($result->insert_id ?? 0);

        if ($id > 0) {
            RateForm::saveRates($id, RateForm::readRates((array) $_POST));
        }
    }

    public static function afterUpdate(int|string $id, object $result, array $values = []): void
    {
        RateForm::saveRates((int) $id, RateForm::readRates((array) $_POST));
    }

    /** Un metodo già su ordini o carrelli si spegne, non si elimina. */
    public static function assertDeletable(int|string $id): void
    {
        $count = count(static::rowsOf(Order::class, ['shipping_method_id' => (int) $id]));

        if ($count > 0) {
            // `refusal()` e non `make()`: chi cancella dall'elenco intercetta `RuntimeException`.
            throw UserError::refusal('shipping.method_in_use', ['count' => $count]);
        }
    }

    /** I listini e i loro scaglioni se ne vanno con il metodo: la chiave esterna non lascerebbe eliminarlo. */
    public static function deleteRecord(int|string $id): object
    {
        $id = (int) $id;

        static::assertDeletable($id);

        $result = null;

        Transaction::run(static function () use ($id, &$result): void {
            foreach (static::rowsOf(ShippingRate::class, ['shipping_method_id' => $id, 'deleted' => ['true', 'false']]) as $rate) {
                foreach (static::rowsOf(ShippingRateBracket::class, ['shipping_rate_id' => (int) $rate['id'], 'deleted' => ['true', 'false']]) as $bracket) {
                    ShippingRateBracket::delete((int) $bracket['id']);
                }

                ShippingRate::delete((int) $rate['id']);
            }

            $result = ShippingMethod::delete($id);
        });

        return is_object($result) ? $result : (object) ['success' => true, 'table' => ShippingMethod::$table, 'id' => $id];
    }

    /**
     * I corrieri attivi, id => nome; senza database (o senza tabella) nessuno.
     *
     * @return array<int, string>
     */
    protected static function carrierOptions(): array
    {
        try {
            return Carriers::options();
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<array<string, mixed>> */
    protected static function zones(): array
    {
        return static::rowsOf(ShippingZone::class, [], 'position');
    }
}
