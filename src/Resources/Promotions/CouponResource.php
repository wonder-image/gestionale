<?php

namespace Wonder\Plugin\Gestionale\Resources\Promotions;

use Throwable;
use Wonder\App\Permission\Access;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\Elements\Components\Badge;
use Wonder\Elements\Components\Button;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\Modal;
use Wonder\Elements\Components\RichText;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Backend\Support\FlashAlert;
use Wonder\Elements\Form\Form;
use Wonder\Http\Csrf;
use Wonder\Http\Exceptions\NotFoundHttpException;
use Wonder\Http\Route;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Contacts\Company;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Promotions\Coupon;
use Wonder\Plugin\Gestionale\Models\Promotions\CouponCustomer;
use Wonder\Plugin\Gestionale\Models\Promotions\CouponRedemption;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderResource;
use Wonder\Plugin\Gestionale\Support\Contacts\Contacts;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Numbers;
use Wonder\Plugin\Gestionale\Support\Promotions\Campaigns;
use Wonder\Plugin\Gestionale\Support\Promotions\CouponCodes;
use Wonder\Plugin\Gestionale\Support\Promotions\Coupons;
use Wonder\Plugin\Gestionale\Support\Promotions\PromotionSheet;
use Wonder\Plugin\Gestionale\Support\Promotions\ScopeForm;
use Wonder\Plugin\Gestionale\Support\Sales\Channels;
use Wonder\Sql\Transaction;
use Wonder\Plugin\Gestionale\Support\RequestMemo;

/**
 * «Coupon»: un codice che il cliente scrive nel carrello per avere uno sconto,
 * la spedizione gratuita o — a regole più strette — solo certi prodotti.
 *
 * Lo stato (programmato, in corso, terminato, disattivato) non si scrive: lo
 * ricava `Campaigns::status` da interruttore e date, come per le campagne. Il
 * selettore dei prodotti è quello delle campagne (`ScopeForm`); i clienti
 * riservati stanno in `CouponCustomer` e si riscrivono, come i ponti, a ogni
 * salvataggio. Sotto il modulo, in sola lettura, la tabella degli utilizzi.
 *
 * Il credito (`store_credit`) è del Wallet e non si offre qui.
 */
class CouponResource extends GestionaleResource
{
    use PromotionSheet;
    use ScopeForm;

    public static string $feature = 'coupons';
    public static string $model = Coupon::class;
    public static string $orderColumn = 'id';
    public static string $orderDirection = 'DESC';
    public static string $docsPage = 'promozioni/promozioni-coupon';

    public static function path(): string
    {
        return 'app/gestionale/coupon';
    }

    public static function icon(): string
    {
        return 'bi-ticket-perforated';
    }

    public static function titleLabel(): string
    {
        return 'Coupon';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'coupon',
            'plural_label' => 'coupon',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'i',
            'full' => 'attivo',
            'empty' => 'disattivato',
            'this' => 'questo',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'code' => 'Codice',
            'name' => 'Nome',
            'discount_type' => 'Tipo di sconto',
            'discount_value' => 'Sconto',
            'min_order_amount' => 'Spesa minima',
            'usage_limit' => 'Utilizzi massimi',
            'usage_limit_per_customer' => 'Utilizzi per cliente',
            'first_order_only' => 'Solo sul primo ordine',
            'exclude_discounted_products' => 'Non sui prodotti già scontati',
            'stackable' => 'Cumulabile con altri coupon',
            'starts_at' => 'Dal',
            'ends_at' => 'Fino al',
            'period' => 'Periodo',
            'usage' => 'Utilizzi',
            'active' => 'Interruttore',
            'applies_online' => 'Sito',
            'applies_office' => 'Ufficio',
            'applies_pos' => 'Cassa',
            'applies_subscriptions' => 'Vale per gli abbonamenti',
            'duration' => 'Durata',
            'duration_months' => 'Mesi',
            'customers' => 'Clienti riservati',
            'companies' => 'Aziende riservate',
            'status' => 'Stato',
            'note' => 'Note',
        ];
    }

    public static function formSchema(): array
    {
        return [
            // Il codice è scritto sugli ordini e dato ai clienti: dopo la creazione non si cambia.
            FormField::key('code')->text()->label('Codice')->required()->disabled(static::currentId() !== null),
            FormField::key('name')->text()->label('Nome'),
            // Il credito ha un'altra strada: qui non si sceglie.
            FormField::key('discount_type')
                ->select(['percent' => 'Percentuale (%)', 'amount' => 'Importo (€)', 'free_shipping' => 'Spedizione gratuita'])
                ->value('percent')
                ->label('Tipo di sconto')
                ->required(),
            FormField::key('discount_value')
                ->number()
                ->decimal(2)
                ->label('Sconto')
                ->hiddenWhen('discount_type', 'free_shipping'),
            FormField::key('min_order_amount')->number()->decimal(2)->label('Spesa minima'),
            FormField::key('usage_limit')->number()->label('Utilizzi massimi'),
            FormField::key('usage_limit_per_customer')->number()->label('Utilizzi per cliente'),
            FormField::key('first_order_only')->toggle()->value('false')->label('Solo sul primo ordine'),
            FormField::key('exclude_discounted_products')->toggle()->value('false')->label('Non sui prodotti già scontati'),
            FormField::key('stackable')->toggle()->value('false')->label('Cumulabile con altri coupon'),
            FormField::key('starts_at')->dateInput()->label('Dal'),
            FormField::key('ends_at')->dateInput()->label('Fino al'),
            FormField::key('active')
                ->select(['true' => 'Attivo', 'false' => 'Disattivato'])
                ->value('true')
                ->label('Interruttore')
                ->required(),
            FormField::key('applies_online')->toggle()->value(Channels::defaults()['applies_online'])->label('Sito'),
            FormField::key('applies_office')->toggle()->value(Channels::defaults()['applies_office'])->label('Ufficio'),
            FormField::key('applies_pos')->toggle()->value(Channels::defaults()['applies_pos'])->label('Cassa'),
            ...static::subscriptionFields(),
            ...static::scopeFields(),
            FormField::key('customers')
                ->selectSearch(static::customerOptions(), true)
                ->label('Clienti riservati'),
            FormField::key('companies')
                ->selectSearch(static::companyOptions(), true)
                ->label('Aziende riservate'),
            FormField::key('note')->textarea()->label('Note'),
        ];
    }

    public static function formLayoutSchema(): ?Form
    {
        $main = (new Card)->components([
            SectionTitle::make('Coupon')
                ->tooltip('Il codice si scrive in maiuscolo e senza spazi, è unico anche rispetto ai coupon eliminati e dopo la creazione non si cambia. Dal primo all\'ultimo giorno scelti, estremi compresi; un limite a zero vuol dire senza limite.')
                ->columnSpan(12),
            static::getInput('code')->columnSpan(6),
            static::getInput('name')->columnSpan(6),
            static::getInput('discount_type')->columnSpan(4),
            static::getInput('discount_value')->columnSpan(4),
            static::getInput('active')->columnSpan(4),
            static::getInput('starts_at')->columnSpan(6),
            static::getInput('ends_at')->columnSpan(6),
        ])->columns(12)->columnSpan(12);

        $rules = (new Card)->components([
            SectionTitle::make('Regole')
                ->tooltip('Spesa minima sui prodotti adatti (per la spedizione gratuita, su tutti i prodotti). Gli utilizzi si contano alla creazione dell\'ordine e tornano disponibili se l\'ordine viene annullato o scade. Il coupon non si somma a uno sconto scritto a mano sull\'ordine. «Cumulabile»: si somma ad altri coupon cumulabili (prima le percentuali, poi gli importi); un coupon non cumulabile sta da solo.')
                ->columnSpan(12),
            static::getInput('min_order_amount')->columnSpan(4),
            static::getInput('usage_limit')->columnSpan(4),
            static::getInput('usage_limit_per_customer')->columnSpan(4),
            static::getInput('first_order_only')->columnSpan(6),
            static::getInput('exclude_discounted_products')->columnSpan(6),
            static::getInput('stackable')->columnSpan(6),
        ])->columns(12)->columnSpan(12);

        $scope = (new Card)->components([
            SectionTitle::make('Prodotti e clienti')
                ->tooltip('«Tutto il catalogo» o «Solo la selezione»: categorie (con le loro sottocategorie), tag, marchi e articoli. Gli articoli esclusi non hanno mai lo sconto. Con dei clienti riservati solo loro possono usare il codice.')
                ->columnSpan(12),
            static::getInput('applies_to_all')->columnSpan(12),
            static::getInput('categories')->columnSpan(12),
            static::getInput('tags')->columnSpan(12),
            static::getInput('brands')->columnSpan(12),
            static::getInput('models')->columnSpan(12),
            static::getInput('excluded_models')->columnSpan(12),
            static::getInput('customers')->columnSpan(12),
            static::getInput('companies')->columnSpan(12),
        ])->columns(12)->columnSpan(12);

        $side = [];

        // «Dove vale» c'è solo se il sito ha più di un canale acceso.
        if (Channels::choose()) {
            $side[] = (new Card)->components([
                SectionTitle::make('Dove vale')
                    ->tooltip('Il sito applica il coupon al carrello. Ufficio e Cassa lo usano per gli ordini fatti dal gestionale e dalla cassa.')
                    ->columnSpan(12),
                ...array_map(
                    static fn (string $channel) => static::getInput(Channels::column($channel))->columnSpan(12),
                    Channels::active()
                ),
            ])->columns(12)->columnSpan(12);
        }

        $side = [
            ...$side,
            (new Card)->components([
                SectionTitle::make('Note')->columnSpan(12),
                static::getInput('note')->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ];

        $left = [$main, $rules];

        if (Gestionale::feature('subscriptions')) {
            $left[] = (new Card)->components([
                SectionTitle::make('Abbonamenti')
                    ->tooltip('Acceso, il coupon vale anche per gli abbonamenti, con uno sconto in percentuale o in importo: sul primo addebito, per qualche mese (un annuale lo prende solo sul primo addebito se i mesi sono meno di dodici) o per sempre. Sugli ordini la durata non conta.')
                    ->columnSpan(12),
                static::getInput('applies_subscriptions')->columnSpan(4),
                static::getInput('duration')->columnSpan(4),
                static::getInput('duration_months')->columnSpan(4),
            ])->columns(12)->columnSpan(12);
        }

        $left[] = $scope;

        return (new Form)->components([
            (new Container)->components($left)->columns(12)->columnSpan(8),
            (new Container)->components($side)->columns(12)->columnSpan(4),
        ])->columns(12);
    }

    public static function tableSchema(): array
    {
        $now = static fn (): string => date('Y-m-d H:i:s');

        return [
            TableColumn::key('code')->text()->link('view'),
            TableColumn::key('name')->text(),
            TableColumn::key('discount_value')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(static::discountLabel($row))),
            TableColumn::key('period')
                ->text()
                ->formatter(static fn (array $row): string => static::escape(static::periodLabel($row))),
            TableColumn::key('usage')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(
                    static::usageLabel(Coupons::usedCount((int) ($row['id'] ?? 0)), $row)
                )),
            TableColumn::key('status')
                ->text()
                ->size('little')
                ->formatter(static function (array $row) use ($now): string {
                    $status = Campaigns::status($row, $now());
                    $class = ['running' => 'success', 'scheduled' => 'primary', 'ended' => 'secondary', 'inactive' => 'light'][$status] ?? 'light';

                    return Badge::make(static::statusLabel($row, $now()))->variant($class)->inline()->render('bootstrap');
                }),
            TableColumn::key('actions')->button()->actions([
                'view',
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
            // Il coupon si apre in lettura: dettagli e utilizzi stanno nella
            // scheda, la modifica è dietro al bottone «Modifica».
            ->enable(['view'])
            ->titles([
                'list' => 'Coupon',
                'create' => 'Nuovo coupon',
                'view' => 'Coupon',
                'edit' => 'Modifica coupon',
            ])
            ->view('show', Gestionale::viewPath('pages/coupon-show.php'))
            ->actions('view', static fn (array $item): array => [
                ...static::codeActions($item),
                [
                    'label' => 'Modifica',
                    'icon' => 'bi-pencil',
                    'class' => 'btn-warning btn-sm',
                    'href' => static::editUrlFor((int) ($item['id'] ?? 0)),
                ],
            ]);
    }

    /** «Scarica i codici» (CSV) e il POST di «Genera codici» (gestionale#113). */
    public static function registerBackendRoutes(string $rootApp, string $slug): void
    {
        Route::get('/{id}/codici/', Gestionale::handlerPath('backend/promotions/coupon-codes.php'), [
            'resource' => $slug,
            'resource_action' => 'codes',
        ])->name('codes')
            ->permit(Access::roles(Gestionale::area('promozioni'), 'view'))
            ->where('id', '[0-9]+');

        Route::post('/{id}/codici/genera/', Gestionale::handlerPath('backend/promotions/coupon-generate.php'), [
            'resource' => $slug,
            'resource_action' => 'generate',
        ])->name('generate')
            ->permit(Access::roles(Gestionale::area('promozioni'), 'edit'))
            ->where('id', '[0-9]+');
    }

    public static function codesUrl(int $id): string
    {
        return static::routeUrl('codes', '/backend/'.static::path().'/'.$id.'/codici/', ['id' => $id]);
    }

    public static function generateUrl(int $id): string
    {
        return static::routeUrl('generate', '/backend/'.static::path().'/'.$id.'/codici/genera/', ['id' => $id]);
    }

    public static function generateModalId(int $id): string
    {
        return 'coupon-generate-'.$id;
    }

    /**
     * I bottoni dei codici in blocco sulla scheda: «Genera codici» sempre (non
     * per i coupon degli abbonamenti), «Scarica i codici» quando ce ne sono.
     *
     * @param array<string, mixed> $item
     * @return list<Button>
     */
    protected static function codeActions(array $item): array
    {
        $id = (int) ($item['id'] ?? 0);

        if ($id <= 0 || (string) ($item['applies_subscriptions'] ?? 'false') === 'true') {
            return [];
        }

        $actions = [
            Button::make('Genera codici')->icon('bi bi-upc-scan', 'start')->variant('secondary')->outline()->size('sm')
                ->opensModal(static::generateModalId($id)),
        ];

        if ((string) ($item['batch'] ?? 'false') === 'true') {
            $actions[] = Button::make('Scarica i codici', static::codesUrl($id))->icon('bi bi-download', 'start')
                ->variant('secondary')->outline()->size('sm');
        }

        return $actions;
    }

    /** La finestra di «Genera codici»: prefisso e quantità. */
    public static function generateModal(array $coupon): string
    {
        $id = (int) ($coupon['id'] ?? 0);

        if ($id <= 0 || (string) ($coupon['applies_subscriptions'] ?? 'false') === 'true') {
            return '';
        }

        return Modal::make('Genera codici')
            ->id(static::generateModalId($id))
            ->form(static::generateUrl($id))
            ->columns(12)
            ->components([
                RichText::make(static::escape('Ogni codice vale una volta, con le regole di questo coupon. Dopo la generazione il codice '.html_entity_decode((string) ($coupon['code'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8').' non si accetta più: valgono solo i codici generati.'))
                    ->class('mb-0')
                    ->columnSpan(12),
                FormField::key('prefix')->text()->label('Prefisso')->columnSpan(6),
                FormField::key('quantity')->number()->label('Quantità')->value('1')->required()->columnSpan(6),
            ])
            ->cancel('Indietro')
            ->submit('Genera', 'primary')
            ->render('bootstrap');
    }

    /** Il POST di «Genera codici»: scrive i codici e torna alla scheda. */
    public static function generateCodes(int $id): never
    {
        $coupon = $id > 0 && static::featureActive() ? Coupon::findById($id) : null;

        if (!is_array($coupon) || !isset($coupon['id']) || (string) ($coupon['deleted'] ?? 'false') !== 'false') {
            throw new NotFoundHttpException();
        }

        if (!Csrf::verify()) {
            FlashAlert::custom('Attenzione', UserError::make('stock_document.expired')->getMessage(), 'warning');
        } else {
            try {
                $codes = CouponCodes::generate($id, (int) ($_POST['quantity'] ?? 0), (string) ($_POST['prefix'] ?? ''));
                RequestMemo::forget(Coupon::MEMO_GROUP);
                FlashAlert::saved(count($codes) === 1 ? 'Generato 1 codice.' : 'Generati '.count($codes).' codici.');
            } catch (UserError $error) {
                FlashAlert::custom('Attenzione', $error->getMessage(), 'warning');
            }
        }

        if (!headers_sent()) {
            header('Location: '.static::routeUrl('view', '/backend/'.static::path().'/'.$id.'/', ['id' => $id]));
        }

        exit();
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

    /**
     * La scheda in lettura: a sinistra (otto colonne) i dettagli, le regole e le
     * tabelle del core di utilizzi e codici; a destra (quattro) lo stato, i
     * numeri, il cumulo, le azioni, dove vale e le note.
     */
    public static function showLayoutSchema(array $row): Container
    {
        $id = (int) ($row['id'] ?? 0);
        $now = date('Y-m-d H:i:s');
        $status = Campaigns::status($row + ['active' => 'false'], $now);
        $class = ['running' => 'success', 'scheduled' => 'primary', 'ended' => 'secondary', 'inactive' => 'light'][$status] ?? 'light';
        $name = trim((string) ($row['name'] ?? ''));
        $yes = static fn (string $key): string => ($row[$key] ?? 'false') === 'true' ? 'Sì' : 'No';
        $limit = static fn (string $key): string => (int) ($row[$key] ?? 0) > 0 ? (string) (int) $row[$key] : 'Senza limite';
        $minimum = (float) ($row['min_order_amount'] ?? 0);
        $batch = ($row['batch'] ?? 'false') === 'true';
        $card = static fn (string $title, array $components, string $tooltip = ''): Card => (new Card)->components([
            $tooltip !== '' ? SectionTitle::make($title)->tooltip($tooltip)->columnSpan(12) : SectionTitle::make($title)->columnSpan(12),
            ...$components,
        ])->columns(12)->columnSpan(12);

        $main = $card('Coupon', [
            RichText::make('<h5 class="mb-0"><code class="fs-5">'.static::escape((string) ($row['code'] ?? '')).'</code></h5>')
                ->tag('div')
                ->columnSpan(12),
            static::sheetRow('Nome', $name)->columnSpan(6),
            static::sheetRow('Sconto', static::discountLabel($row))->columnSpan(6),
        ]);

        $rules = $card('Regole', [
            static::sheetRow('Spesa minima', $minimum > 0 ? number_format($minimum, 2, ',', '.').' €' : '')->columnSpan(6),
            static::sheetRow('Utilizzi massimi', $limit('usage_limit'))->columnSpan(6),
            static::sheetRow('Utilizzi per cliente', $limit('usage_limit_per_customer'))->columnSpan(6),
            ...(Gestionale::feature('subscriptions')
                ? [static::sheetRow('Abbonamenti', static::durationLabel($row))->columnSpan(6)]
                : []),
        ]);

        $links = static::rowsOf(CouponCustomer::class, ['coupon_id' => $id]);
        $customers = [];
        $companies = [];

        foreach ($links as $link) {
            if ((int) ($link['company_id'] ?? 0) > 0) {
                $companies[] = static::escape(static::companyName((int) $link['company_id']));
            } elseif ((int) ($link['customer_id'] ?? 0) > 0) {
                $customers[] = static::escape(static::customerName((int) $link['customer_id'], ''));
            }
        }

        $scope = $card('Prodotti e clienti', [
            static::sheetRow('Prodotti', static::scopeHtml('coupon', $id), true)->columnSpan(12),
            static::sheetRow('Clienti riservati', implode(', ', $customers), true)->columnSpan(12),
            static::sheetRow('Aziende riservate', implode(', ', $companies), true)->columnSpan(12),
        ]);

        $blocks = [
            $main,
            $rules,
            $scope,
            $card('Utilizzi', [
                RichText::make(CouponRedemptionTableResource::embed($id))->tag('div')->columnSpan(12),
            ], 'Gli ordini che hanno usato il coupon. «Rilasciato» vuol dire che l\'ordine è stato annullato o è scaduto e l\'utilizzo non conta più.'),
        ];

        if ($batch) {
            $blocks[] = $card('Codici', [
                RichText::make(CouponCodeTableResource::embed($id))->tag('div')->columnSpan(12),
            ], 'I codici generati: ognuno vale una volta. L\'elenco completo è anche in «Scarica i codici».');
        }

        $used = Coupons::usedCount($id);
        $codes = $batch ? CouponCodes::of($id) : [];
        $codesUsed = count(array_filter($codes, static fn (array $code): bool => $code['used']));

        $side = [
            $card('Stato', [
                RichText::make(Badge::make(static::statusLabel($row + ['active' => 'false'], $now))->variant($class)->inline()->render('bootstrap'))
                    ->tag('div')
                    ->columnSpan(12),
                static::sheetRow('Periodo', static::periodLabel($row))->columnSpan(12),
                static::sheetRow('Scadenza', static::deadlineLabel($row, $now))->columnSpan(12),
            ]),
            $card('Numeri', [
                RichText::make(static::usageHtml($used, (int) ($row['usage_limit'] ?? 0)))->tag('div')->columnSpan(12),
                static::sheetRow('Sconto concesso', number_format(Coupons::discountGranted($id), 2, ',', '.').' €')->columnSpan(12),
                ...($batch ? [
                    static::sheetRow('Codici generati', (string) count($codes))->columnSpan(4),
                    static::sheetRow('Usati', (string) $codesUsed)->columnSpan(4),
                    static::sheetRow('Liberi', (string) (count($codes) - $codesUsed))->columnSpan(4),
                ] : []),
            ], 'Gli utilizzi rilasciati non contano né negli utilizzi né nello sconto concesso.'),
            $card('Cumulo', [
                static::sheetRow('Cumulabile con altri coupon', $yes('stackable'))->columnSpan(12),
                static::sheetRow('Solo sul primo ordine', $yes('first_order_only'))->columnSpan(12),
                static::sheetRow('Non sui prodotti già scontati', $yes('exclude_discounted_products'))->columnSpan(12),
            ]),
            $card('Azioni', [
                RichText::make('<div class="d-grid gap-2">'.implode('', array_map(
                    static fn (Button $button): string => $button->block()->render('bootstrap'),
                    [
                        Button::make('Modifica', static::editUrlFor($id))->icon('bi bi-pencil', 'start')->variant('warning')->size('sm'),
                        ...static::codeActions($row),
                    ]
                )).'</div>')->tag('div')->columnSpan(12),
            ]),
            $card('Dove vale', [
                RichText::make(static::channelsHtml($row))->tag('div')->columnSpan(12),
            ]),
        ];

        if (trim((string) ($row['note'] ?? '')) !== '') {
            $side[] = $card('Note', [
                static::sheetRow('', (string) $row['note'])->columnSpan(12),
            ]);
        }

        return (new Container)->components([
            (new Container)->components($blocks)->columns(12)->columnSpan(8),
            (new Container)->components($side)->columns(12)->columnSpan(4),
        ])->columns(12);
    }

    /** Gli utilizzi sul limite, con la barra quando il limite c'è. */
    public static function usageHtml(int $used, int $limit): string
    {
        $html = '<div class="d-flex justify-content-between small text-body-secondary"><span>Utilizzi</span>'
            .'<span class="fw-semibold text-body">'.static::escape($used.' / '.($limit > 0 ? $limit : '∞')).'</span></div>';

        if ($limit <= 0) {
            return $html;
        }

        $percent = (int) min(100, round($used * 100 / $limit));

        return $html.'<div class="progress mt-1" style="height: 6px" role="progressbar" aria-label="Utilizzi" aria-valuenow="'.$percent.'" aria-valuemin="0" aria-valuemax="100">'
            .'<div class="progress-bar'.($percent >= 100 ? ' bg-secondary' : '').'" style="width: '.$percent.'%"></div></div>';
    }

    /** «Scade fra 5 giorni», «Scade oggi», «Inizia fra 3 giorni», «Terminato il 10/10/2026», «Senza scadenza». */
    public static function deadlineLabel(array $row, string $now): string
    {
        $today = strtotime(date('Y-m-d', strtotime($now)));
        $days = static fn (string $date): int => (int) round((strtotime(date('Y-m-d', strtotime($date))) - $today) / 86400);
        $count = static fn (int $n): string => $n === 1 ? '1 giorno' : $n.' giorni';
        $from = static::dayOf($row['starts_at'] ?? '', 'Y-m-d');
        $to = static::dayOf($row['ends_at'] ?? '', 'Y-m-d');

        if ($from !== '' && $days($from) > 0) {
            return 'Inizia fra '.$count($days($from));
        }

        if ($to === '') {
            return 'Senza scadenza';
        }

        $left = $days($to);

        return match (true) {
            $left < 0 => 'Terminato il '.static::dayOf($to),
            $left === 0 => 'Scade oggi',
            default => 'Scade fra '.$count($left),
        };
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->access(Gestionale::area('promozioni'));
    }

    /** Nessuna API di Resource: nel modulo non c'è un chiamante. */
    public static function apiSchema(): ApiSchema
    {
        return parent::apiSchema()->enabled(false);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return parent::navigationSchema()
            ->section('promozioni', 'Promozioni', 'bi-percent', 360)
            ->title('Coupon')
            ->order(20)
            ->access(Gestionale::area('promozioni'));
    }

    /** «20 %», «12,5 %», «10,00 €» o «Spedizione gratuita». */
    public static function discountLabel(array $row): string
    {
        $value = (float) ($row['discount_value'] ?? 0);

        return match ((string) ($row['discount_type'] ?? 'percent')) {
            'free_shipping' => 'Spedizione gratuita',
            'amount' => number_format($value, 2, ',', '').' €',
            default => rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',').' %',
        };
    }

    /** «dal 01/10/2026 al 10/10/2026», «sempre» se non ha date. */
    public static function periodLabel(array $row): string
    {
        $from = static::dayOf($row['starts_at'] ?? '');
        $to = static::dayOf($row['ends_at'] ?? '');

        return match (true) {
            $from !== '' && $to !== '' => 'dal '.$from.' al '.$to,
            $from !== '' => 'dal '.$from,
            $to !== '' => 'fino al '.$to,
            default => 'sempre',
        };
    }

    /** «3 / 10», «3 / ∞» se non c'è un limite. */
    public static function usageLabel(int $used, array $row): string
    {
        $limit = (int) ($row['usage_limit'] ?? 0);

        return $used.' / '.($limit > 0 ? $limit : '∞');
    }

    /** «12 usati su 100». */
    public static function codesLabel(int $couponId): string
    {
        $rows = CouponCodes::of($couponId);
        $used = count(array_filter($rows, static fn (array $row): bool => $row['used']));

        return $used.' usati su '.count($rows);
    }

    /** «No», «Sì, sul primo addebito», «Sì, per 3 mesi», «Sì, per sempre». */
    public static function durationLabel(array $row): string
    {
        if ((string) ($row['applies_subscriptions'] ?? 'false') !== 'true') {
            return 'No';
        }

        $months = (int) ($row['duration_months'] ?? 0);

        return match ((string) ($row['duration'] ?? 'once')) {
            'forever' => 'Sì, per sempre',
            'repeating' => 'Sì, per '.$months.($months === 1 ? ' mese' : ' mesi'),
            default => 'Sì, sul primo addebito',
        };
    }

    /**
     * I campi degli abbonamenti, solo con `subscriptions` accesa. I mesi si
     * vedono solo con la durata «Per N mesi».
     *
     * @return list<FormField>
     */
    protected static function subscriptionFields(): array
    {
        if (!Gestionale::feature('subscriptions')) {
            return [];
        }

        return [
            FormField::key('applies_subscriptions')->toggle()->value('false')->label('Vale per gli abbonamenti'),
            FormField::key('duration')
                ->select(['once' => 'Sul primo addebito', 'repeating' => 'Per N mesi', 'forever' => 'Per sempre'])
                ->value('once')
                ->label('Durata'),
            FormField::key('duration_months')->number()->label('Mesi')->visibleWhen('duration', 'repeating'),
        ];
    }

    /**
     * Le colonne degli abbonamenti pronte da scrivere. Con `subscriptions`
     * spenta i campi non ci sono: restano i valori di prima, altrimenti un
     * salvataggio li azzererebbe. Spento l'interruttore, la durata torna «una
     * volta» senza mesi; i mesi valgono solo per «Per N mesi».
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed>|null $old
     * @return array<string, mixed>
     */
    public static function subscriptionValues(array $values, ?array $old): array
    {
        if (!Gestionale::feature('subscriptions')) {
            foreach (['applies_subscriptions' => 'false', 'duration' => 'once', 'duration_months' => 0] as $column => $default) {
                $values[$column] = $old !== null && isset($old[$column]) ? $old[$column] : $default;
            }

            return $values;
        }

        $on = ($values['applies_subscriptions'] ?? 'false') === 'true';
        $duration = in_array((string) ($values['duration'] ?? ''), Coupon::DURATIONS, true) ? (string) $values['duration'] : 'once';

        $values['applies_subscriptions'] = $on ? 'true' : 'false';
        $values['duration'] = $on ? $duration : 'once';
        $values['duration_months'] = $on && $duration === 'repeating' ? max(0, (int) ($values['duration_months'] ?? 0)) : 0;

        return $values;
    }

    public static function statusLabel(array $row, string $now): string
    {
        return [
            'running' => 'In corso',
            'scheduled' => 'Programmato',
            'ended' => 'Terminato',
            'inactive' => 'Disattivato',
        ][Campaigns::status($row, $now)];
    }

    /**
     * I valori della richiesta pronti da scrivere: codice senza spazi, sconto e
     * spesa minima numerici, limiti interi, date piene (il primo giorno dalle
     * 00:00, l'ultimo fino alle 23:59:59). Un coupon che non sta in piedi si
     * rifiuta qui, prima di scrivere qualsiasi cosa.
     */
    public static function mutateRequestValues(
        array $values,
        string $action,
        string $context = 'backend',
        ?array $oldValues = null
    ): array {
        $values = Channels::keepHidden($values, $oldValues);
        // Il codice non si cambia una volta creato (il campo, spento, non arriva nemmeno);
        // alla creazione si scrive in maiuscolo e senza spazi.
        $values['code'] = $action === 'update' && $oldValues !== null
            ? (string) ($oldValues['code'] ?? '')
            : mb_strtoupper((string) preg_replace('/\s+/u', '', (string) ($values['code'] ?? '')));
        $values['discount_type'] = (string) ($values['discount_type'] ?? '');
        $values['starts_at'] = static::momentOf($values['starts_at'] ?? '', '00:00:00');
        $values['ends_at'] = static::momentOf($values['ends_at'] ?? '', '23:59:59');
        $values['min_order_amount'] = Numbers::fromForm($values['min_order_amount'] ?? null) ?? '0.00';
        $values['usage_limit'] = max(0, (int) ($values['usage_limit'] ?? 0));
        $values['usage_limit_per_customer'] = max(0, (int) ($values['usage_limit_per_customer'] ?? 0));
        $values['discount_value'] = $values['discount_type'] === 'free_shipping'
            ? '0.00'
            : (Numbers::fromForm($values['discount_value'] ?? null) ?? '');

        $values = static::subscriptionValues($values, $oldValues);

        // `batch` non arriva dal form: lo accende solo «Genera codici».
        $values['stackable'] = ($values['stackable'] ?? 'false') === 'true' ? 'true' : 'false';
        $values['batch'] = (string) ($oldValues['batch'] ?? 'false') === 'true' ? 'true' : 'false';

        Coupons::validate(
            [
                'customers' => static::readCustomers((array) $_POST),
                'companies' => static::readCompanies((array) $_POST),
            ] + $values,
            (int) ($oldValues['id'] ?? 0)
        );

        // Selettore e clienti riservati non sono colonne: si salvano a parte.
        return array_diff_key($values, array_flip([...static::FIELDS, 'customers', 'companies']));
    }

    /** Riempie il form con il selettore, i clienti riservati e le date senza ora. */
    public static function mutateFormValues(array $values, string $mode, string $context = 'backend'): array
    {
        foreach (['starts_at', 'ends_at'] as $column) {
            if (array_key_exists($column, $values)) {
                $values[$column] = static::dayOf($values[$column], 'Y-m-d');
            }
        }

        $id = (int) ($values['id'] ?? 0);

        if ($mode === 'edit' && $id > 0) {
            $values = static::loadScope('coupon', $id) + $values;
            $links = static::rowsOf(CouponCustomer::class, ['coupon_id' => $id]);
            $values['customers'] = static::linkIds($links, 'customer_id');
            $values['companies'] = static::linkIds($links, 'company_id');
        }

        return $values;
    }

    public static function afterStore(object $result, array $values = []): void
    {
        // Il backend scrive la testata con una query diretta.
        RequestMemo::forget(Coupon::MEMO_GROUP);
        $id = (int) ($result->insert_id ?? 0);

        if ($id > 0) {
            static::saveRelations($id);
        }
    }

    public static function afterUpdate(int|string $id, object $result, array $values = []): void
    {
        RequestMemo::forget(Coupon::MEMO_GROUP);
        static::saveRelations((int) $id);
    }

    public static function afterDelete(int|string $id, object $result, array $values = []): void
    {
        RequestMemo::forget(Coupon::MEMO_GROUP);
        parent::afterDelete($id, $result, $values);
    }

    /** Selettore dei prodotti e clienti riservati, riscritti per intero. */
    protected static function saveRelations(int $id): void
    {
        $post = (array) $_POST;
        $customers = static::readCustomers($post);
        $companies = static::readCompanies($post);

        Transaction::run(static function () use ($id, $post, $customers, $companies): void {
            static::saveScope('coupon', $id, static::readScope($post));

            foreach (static::rowsOf(CouponCustomer::class, ['coupon_id' => $id]) as $row) {
                CouponCustomer::delete((int) $row['id']);
            }

            foreach ($customers as $customerId) {
                CouponCustomer::create(['coupon_id' => $id, 'customer_id' => $customerId]);
            }

            foreach ($companies as $companyId) {
                CouponCustomer::create(['coupon_id' => $id, 'customer_id' => 0, 'company_id' => $companyId]);
            }
        });
    }

    /**
     * Gli id di una colonna dei riservati, come stringhe per il form.
     *
     * @param list<array<string, mixed>> $links
     * @return list<string>
     */
    protected static function linkIds(array $links, string $column): array
    {
        $ids = [];

        foreach ($links as $link) {
            if ((int) ($link[$column] ?? 0) > 0) {
                $ids[] = (string) (int) $link[$column];
            }
        }

        return $ids;
    }

    /** @param array<string, mixed> $post @return list<int> */
    protected static function readCompanies(array $post): array
    {
        return static::scopeIds($post['companies'] ?? null);
    }

    /** Le aziende da proporre, id => ragione sociale. @return array<int, string> */
    protected static function companyOptions(): array
    {
        $names = [];

        foreach (static::rowsOf(Company::class, []) as $row) {
            $names[(int) $row['id']] = Contacts::displayName($row);
        }

        asort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return $names;
    }

    public static function companyName(int $companyId): string
    {
        $row = $companyId > 0 ? (static::rowsOf(Company::class, ['id' => $companyId])[0] ?? null) : null;

        return is_array($row) ? Contacts::displayName($row) : '';
    }

    /**
     * I clienti riservati scritti nella richiesta, come il selettore: id interi
     * positivi senza doppioni.
     *
     * @param array<string, mixed> $post
     * @return list<int>
     */
    protected static function readCustomers(array $post): array
    {
        return static::scopeIds($post['customers'] ?? null);
    }

    /** I clienti da proporre, id => nome, in ordine di nome. @return array<int, string> */
    protected static function customerOptions(): array
    {
        $names = [];

        foreach (static::rowsOf(Contact::class, ['is_customer' => 'true']) as $row) {
            $names[(int) $row['id']] = Contacts::displayName($row);
        }

        asort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return $names;
    }

    /** Il nome del cliente; se non c'è (un ospite) l'email. */
    public static function customerName(int $customerId, string $email): string
    {
        if ($customerId > 0) {
            $row = static::rowsOf(Contact::class, ['id' => $customerId])[0] ?? null;

            if (is_array($row)) {
                return Contacts::displayName($row);
            }
        }

        return $email;
    }

    /** Il giorno di una data-ora, `d/m/Y` (o il formato dato); vuoto se manca o è la data zero di MySQL. */
    protected static function dayOf(mixed $value, string $format = 'd/m/Y'): string
    {
        $value = trim((string) $value);

        if ($value === '' || str_starts_with($value, '0000') || strtotime($value) === false) {
            return '';
        }

        return date($format, strtotime($value));
    }

    /** Una data del form, o con l'ora, come `Y-m-d H:i:s`; vuoto se non è una data. */
    protected static function momentOf(mixed $value, string $time): string
    {
        $value = trim((string) $value);

        if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:[ T](\d{2}:\d{2})(?::(\d{2}))?)?$/', $value, $m) !== 1) {
            return '';
        }

        return $m[1].' '.(isset($m[2]) ? $m[2].':'.($m[3] ?? '00') : $time);
    }
}
