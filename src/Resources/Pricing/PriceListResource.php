<?php

namespace Wonder\Plugin\Gestionale\Resources\Pricing;

use Throwable;
use Wonder\App\Permission\Access;
use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\RepeaterColumn;
use Wonder\App\ResourceSchema\RepeaterRelation;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\App\ResourceSchema\TableLayoutSchema;
use Wonder\App\Support\Repeater;
use Wonder\Backend\Support\FlashAlert;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\Dropdown;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Components\Submit;
use Wonder\Elements\Form\Form;
use Wonder\Http\Csrf;
use Wonder\Http\Exceptions\NotFoundHttpException;
use Wonder\Http\Route;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Pricing\PriceList;
use Wonder\Plugin\Gestionale\Models\Pricing\PriceListItem;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Exports\ProductFeed;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Numbers;
use Wonder\Plugin\Gestionale\Support\Pricing\Import\PriceListLayout;
use Wonder\Plugin\Gestionale\Support\Pricing\PriceListChannels;
use Wonder\Plugin\Gestionale\Support\Pricing\PriceLists;
use Wonder\Plugin\Gestionale\Support\Pricing\PriceListSeeder;
use Wonder\Plugin\Gestionale\Support\Promotions\ScopeForm;
use Wonder\Plugin\Gestionale\Support\RequestMemo;
use Wonder\Sql\Transaction;
use Wonder\Plugin\Gestionale\Support\Stock\ProductNames;

/**
 * «Listini»: i prezzi riservati a un cliente o a un canale di vendita
 * (G1, `customer_price_lists`; canali e regola in percentuale #162).
 * Le righe stanno in un repeater: prodotto, quantità minima (lo scaglione) e
 * prezzo. Si scaricano e si ricaricano in csv o xlsx. I prodotti senza riga
 * seguono la regola (nessuna, ricarico o sconto in percentuale sul prezzo
 * base) solo se stanno in «Si applica a», il selettore dei coupon
 * (`ScopeForm`). «Cerca prodotti» scrive le righe mancanti
 * (`PriceListSeeder`).
 *
 * Un listino assegnato a clienti o ordini non si elimina: si spegne.
 */
final class PriceListResource extends GestionaleResource
{
    use ScopeForm;

    public static string $model = PriceList::class;
    public static string $feature = 'customer_price_lists';

    /**
     * «Si applica a»: tutto, i prodotti dei filtri, o solo quelli delle righe.
     * Non è una colonna: `applies_to_all` e i ponti la dicono già (vedi
     * `scopeMode()`).
     *
     * @var array<string, string>
     */
    public const SCOPE_MODES = [
        'all' => 'Tutto il catalogo',
        'filter' => 'Filtra catalogo',
        'products' => 'Seleziona prodotti',
    ];

    /** Il bottone «Cerca prodotti»: salva e crea le righe dai filtri. */
    public const SEARCH = 'search_products';

    /** I canali scelti nel form, scritti dopo il salvataggio. */
    private static ?array $channels = null;

    public static function path(): string
    {
        return 'app/gestionale/listini';
    }

    public static function icon(): string
    {
        return 'bi-tags';
    }

    public static function titleLabel(): string
    {
        return 'Listini';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'listino',
            'plural_label' => 'listini',
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
            'prices_include_tax' => 'Prezzi',
            'applies_to_all' => 'Si applica a',
            'scope_mode' => 'Si applica a',
            'price_rule' => 'I prodotti senza una riga',
            'percent' => 'Percentuale',
            'channels' => 'Canali',
            'valid_from' => 'Dal',
            'valid_to' => 'Al',
            'active' => 'Stato',
            'items' => 'Righe',
            'customers' => 'Clienti',
            'note' => 'Nota',
        ];
    }

    public static function formSchema(): array
    {
        return [
            FormField::key('name')->text()->label('Nome')->required(),
            FormField::key('prices_include_tax')
                ->select(['true' => 'IVA inclusa', 'false' => 'IVA esclusa'])
                ->value('true')
                ->label('Prezzi'),
            FormField::key('price_rule')
                ->select(['none' => 'Mantengono il prezzo originale', 'discount' => 'Hanno uno sconto', 'markup' => 'Hanno un ricarico'])
                ->value('none')
                ->label('I prodotti senza una riga'),
            FormField::key('percent')->number()->decimal(2)->label('Percentuale')
                ->visibleWhen('price_rule', ['discount', 'markup']),
            FormField::key('scope_mode')
                ->select(static::SCOPE_MODES)
                ->value('all')
                ->label('Si applica a')
                ->required(),
            ...static::scopeFilterFields('scope_mode', 'filter', ['all', 'filter']),
            // Il titolo è quello della card.
            FormField::key('channels')->checkbox()->options(static::channelOptions((int) (static::currentId() ?? 0)))->value([])->label(''),
            FormField::key('valid_from')->dateInput()->label('Dal'),
            FormField::key('valid_to')->dateInput()->label('Al'),
            FormField::key('active')
                ->select(['true' => 'Attivo', 'false' => 'Non attivo'])
                ->value('true')
                ->label('Stato'),
            FormField::key('note')->textarea()->label('Nota'),
            FormField::key('items')
                ->repeater([
                    RepeaterColumn::key('id')->hidden(),
                    RepeaterColumn::key('product_id')->select(['' => 'Nessuno'] + static::productOptions())->label('Prodotto')->columnFill(),
                    RepeaterColumn::key('min_quantity')->number()->decimal(0)->value('1')->label('Da quantità')->columnSpan(2),
                    RepeaterColumn::key('price')->number()->decimal(2)->label('Prezzo')->columnSpan(3),
                ])
                ->relation(
                    RepeaterRelation::make(PriceListItem::$table, 'price_list_id')
                        ->model(PriceListItem::class)
                        ->positionKey('position')
                        ->softDelete(false)
                )
                ->nested()
                ->repeaterSortable()
                ->repeaterAddLabel('Aggiungi riga')
                ->repeaterDeleteTitle('Elimina riga')
                ->repeaterDeleteText('Confermi l\'eliminazione di questa riga?')
                ->repeaterDeleteCancelLabel('Annulla')
                ->repeaterDeleteConfirmLabel('Elimina')
                ->repeaterDeleteConfirmClass('btn btn-danger')
                ->label(''),
        ];
    }

    public static function formLayoutSchema(): ?Form
    {
        $id = (int) (static::currentId() ?? 0);
        $prices = (new Card)->components([
            SectionTitle::make('Prezzi')
                ->tooltip('Una riga per prodotto e scaglione: «Da quantità» è la quantità minima da cui vale il prezzo. Con più righe dello stesso prodotto vale quella con la quantità più alta raggiunta. Un prodotto che non c\'è si paga a prezzo di catalogo. Il listino sostituisce le campagne; coupon e sconto dell\'ordine si aggiungono.')
                ->columnSpan(12),
            static::getInput('items')->columnSpan(12),
        ])->columns(12)->columnSpan(12);

        // Con le righe la card si vede sempre; senza, con «Seleziona prodotti» o dopo «Cerca prodotti».
        if (!static::hasItems($id)) {
            $prices->visibleWhen('scope_mode', 'products');
        }

        return (new Form)->components([
            (new Container)->components([
                (new Card)->components([
                    SectionTitle::make('Listino')
                        ->tooltip('Si assegna dalla scheda del cliente o a uno o più canali qui accanto. Il listino del cliente viene prima di quello del canale. Vale se è attivo, fra le date (vuote = sempre); altrimenti il cliente paga i prezzi di catalogo. «IVA inclusa» o «esclusa» dice come sono scritti i prezzi.')
                        ->columnSpan(12),
                    static::getInput('name')->columnSpan(6),
                    static::getInput('prices_include_tax')->columnSpan(3),
                    static::getInput('active')->columnSpan(3),
                    static::getInput('valid_from')->columnSpan(3),
                    static::getInput('valid_to')->columnSpan(3),
                    static::getInput('note')->columnSpan(6),
                ])->columns(12)->columnSpan(8),
                (new Card)->components([
                    SectionTitle::make('Canali')
                        ->tooltip('Un canale ha un solo listino: gli ordini e i feed di quel canale usano questo listino quando il cliente non ne ha uno suo. Si vedono solo i canali accesi.')
                        ->columnSpan(12),
                    static::getInput('channels')->columnSpan(12),
                ])->columns(12)->columnSpan(4),
                (new Card)->components([
                    SectionTitle::make('Si applica a')
                        ->tooltip('«Tutto il catalogo»: la regola vale per tutti i prodotti. «Filtra catalogo»: solo per quelli di categorie (con le sottocategorie), tag, marchi e articoli scelti; «Cerca prodotti» salva e scrive una riga per ogni prodotto trovato che non ce l\'ha. «Seleziona prodotti»: valgono solo le righe in «Prezzi». Gli articoli esclusi restano sempre fuori. La regola vale sul prezzo pieno (non su quello scontato); gli altri prodotti pagano il prezzo di catalogo. Una riga in «Prezzi» vince sempre.')
                        ->columnSpan(12),
                    static::getInput('scope_mode')->columnSpan(12),
                    static::getInput('categories')->columnSpan(12),
                    static::getInput('tags')->columnSpan(12),
                    static::getInput('brands')->columnSpan(12),
                    static::getInput('models')->columnSpan(12),
                    static::getInput('excluded_models')->columnSpan(12),
                    static::getInput('price_rule')->columnSpan(4),
                    static::getInput('percent')->columnSpan(2),
                    (new Submit(static::SEARCH))
                        ->label('Cerca prodotti')
                        ->buttonClass('btn btn-outline-secondary')
                        ->visibleWhen('scope_mode', 'filter')
                        ->columnSpan(12),
                ])->columns(12)->columnSpan(12),
                $prices,
            ])->columns(12)->columnSpan(12),
        ]);
    }

    /** Il listino ha già righe. */
    private static function hasItems(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        try {
            return (int) sqlCount(PriceListItem::$table, 'price_list_id = '.$id." AND deleted = 'false'") > 0;
        } catch (Throwable) {
            return false;
        }
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('name')->text()->link('edit'),
            TableColumn::key('items')
                ->text()
                ->formatter(static fn (array $row): string => (string) (int) sqlCount(PriceListItem::$table, 'price_list_id = '.(int) ($row['id'] ?? 0)." AND deleted = 'false'")),
            TableColumn::key('customers')
                ->text()
                ->formatter(static fn (array $row): string => (string) (int) sqlCount(Contact::$table, 'price_list_id = '.(int) ($row['id'] ?? 0)." AND deleted = 'false'")),
            TableColumn::key('channels')
                ->text()
                ->formatter(static fn (array $row): string => static::escape(static::channelLabels((int) ($row['id'] ?? 0)))),
            TableColumn::key('valid_to')
                ->text()
                ->formatter(static fn (array $row): string => static::escape(static::validity($row))),
            TableColumn::key('active')
                ->booleanBadge()
                ->badgeOn('Attivo', 'bi-check-circle', 'success')
                ->badgeOff('Non attivo', 'bi-dash-circle', 'secondary')
                ->size('little'),
            TableColumn::key('actions')->button()->actions([
                'edit',
                'active' => ['label' => ['true' => 'Disattiva', 'false' => 'Attiva'], 'request' => 'boolean'],
                'delete',
            ]),
        ];
    }

    public static function tableLayoutSchema(): TableLayoutSchema
    {
        // «Importa» (#162): i prezzi di più listini da un file; i permessi li decide PriceListLayout::permits().
        return parent::tableLayoutSchema()->importLayout(PriceListLayout::KEY);
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()
            ->disable(['view'])
            ->titles([
                'list' => 'Listini',
                'create' => 'Nuovo listino',
                'edit' => 'Modifica listino',
            ])
            ->actions('edit', static fn (array $item): array => (int) ($item['id'] ?? 0) > 0 ? [
                // Il formato si sceglie nel menu, come nell'«Esporta» degli elenchi.
                Dropdown::make('Scarica')->icon('bi bi-download')->variant('dark')->outline()->size('sm')->align('end')
                    ->item('Excel (.xlsx)', static::itemsUrl((int) $item['id'], 'xlsx'))
                    ->item('CSV (.csv)', static::itemsUrl((int) $item['id'], 'csv')),
            ] : []);
    }

    /** Le righe del listino come file. */
    public static function registerBackendRoutes(string $rootApp, string $slug): void
    {
        Route::get('/{id}/righe/{format}/', Gestionale::handlerPath('backend/pricing/price-list-items.php'), [
            'resource' => $slug,
            'resource_action' => 'items',
        ])->name('items')
            ->permit(Access::roles(Gestionale::area('promozioni'), 'edit'))
            ->where('id', '[0-9]+')
            ->where('format', 'xlsx|csv');

        Route::post('/{id}/crea-righe/', Gestionale::handlerPath('backend/pricing/price-list-seed.php'), [
            'resource' => $slug,
            'resource_action' => 'seed',
        ])->name('seed')
            ->permit(Access::roles(Gestionale::area('promozioni'), 'edit'))
            ->where('id', '[0-9]+');
    }

    public static function seedUrl(int $id): string
    {
        return static::routeUrl('seed', '/backend/'.static::path().'/'.$id.'/crea-righe/', ['id' => $id]);
    }

    /** Il POST della rotta `seed` (per chi la chiamava): scrive le righe e torna alla scheda. */
    public static function seedRows(int $id): never
    {
        $list = $id > 0 && static::featureActive() ? PriceList::findById($id) : null;

        if (!is_array($list) || !isset($list['id']) || (string) ($list['deleted'] ?? 'false') !== 'false') {
            throw new NotFoundHttpException();
        }

        if (!Csrf::verify()) {
            FlashAlert::custom('Attenzione', UserError::make('stock_document.expired')->getMessage(), 'warning');
            static::backToEdit($id);
        }

        static::searchProducts($id);
    }

    /** «Cerca prodotti»: crea le righe mancanti, lo dice e torna alla scheda. */
    private static function searchProducts(int $id): never
    {
        $count = PriceListSeeder::seed($id);
        FlashAlert::saved(match ($count) {
            0 => 'Nessun prodotto nuovo: quelli trovati hanno già una riga.',
            1 => 'Trovato 1 prodotto: creata 1 riga.',
            default => 'Trovati '.$count.' prodotti: create '.$count.' righe.',
        });

        static::backToEdit($id);
    }

    private static function backToEdit(int $id): never
    {
        if (!headers_sent()) {
            header('Location: '.static::routeUrl('edit', '/backend/'.static::path().'/'.$id.'/edit/', ['id' => $id]));
        }

        exit();
    }

    public static function itemsUrl(int $id, string $format): string
    {
        return static::routeUrl('items', '/backend/'.static::path().'/'.$id.'/righe/'.$format.'/', ['id' => $id, 'format' => $format]);
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->access(Gestionale::area('promozioni'));
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return parent::navigationSchema()
            ->section('catalogo', 'Catalogo', 'bi-box-seam', 340)
            ->title('Listini')
            ->order(46)
            ->access(Gestionale::area('promozioni'));
    }

    /**
     * I listini da scegliere su cliente e ordine; quelli spenti solo se già
     * scelti o con `$all` (la scheda cliente non sa quale ha).
     *
     * @return array<int, string>
     */
    public static function options(int $selected = 0, bool $all = false): array
    {
        if (!static::featureActive()) {
            return [];
        }

        $options = [];

        foreach (static::rowsOf(PriceList::class, [], 'name') as $row) {
            $id = (int) $row['id'];
            $active = ($row['active'] ?? 'false') === 'true';

            if ($active || $all || $id === $selected) {
                $options[$id] = html_entity_decode((string) ($row['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8').($active ? '' : ' (non attivo)');
            }
        }

        return $options;
    }

    /**
     * I prodotti da mettere in listino: «Nome — SKU».
     *
     * @return array<int, string>
     */
    public static function productOptions(): array
    {
        $products = static::rowsOf(Product::class, [], 'id');
        $names = ProductNames::models($products);
        $options = [];

        foreach ($products as $product) {
            $sku = trim((string) ($product['sku'] ?? ''));
            $label = ProductNames::full($product, $names).($sku !== '' ? ' ('.$sku.')' : '');
            $options[(int) $product['id']] = ($product['active'] ?? 'false') === 'true' ? $label : $label.' (disattivato)';
        }

        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }

    /** «Dal 01/01/2027 al 31/12/2027», «Sempre». @param array<string, mixed> $row */
    public static function validity(array $row): string
    {
        $from = static::date((string) ($row['valid_from'] ?? ''));
        $to = static::date((string) ($row['valid_to'] ?? ''));

        return match (true) {
            $from !== '' && $to !== '' => 'Dal '.$from.' al '.$to,
            $from !== '' => 'Dal '.$from,
            $to !== '' => 'Fino al '.$to,
            default => 'Sempre',
        };
    }

    public static function mutateRequestValues(
        array $values,
        string $action,
        string $context = 'backend',
        ?array $oldValues = null
    ): array {
        // La valuta non si sceglie: quella del catalogo, o quella già salvata.
        $currency = strtoupper(trim((string) ($oldValues['currency'] ?? '')));
        $values['currency'] = preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : ProductFeed::currency();
        $from = static::day($values['valid_from'] ?? '');
        $to = static::day($values['valid_to'] ?? '');
        $values['valid_from'] = $from;
        $values['valid_to'] = $to;

        if ($from !== '' && $to !== '' && $to < $from) {
            throw UserError::make('price_list.invalid_dates');
        }

        $rule = (string) ($values['price_rule'] ?? 'none');
        $rule = in_array($rule, PriceLists::RULES, true) ? $rule : 'none';
        $percent = (float) (Numbers::fromForm($values['percent'] ?? null) ?? '0');

        if ($rule !== 'none' && ($percent <= 0 || ($rule === 'discount' && $percent >= 100) || $percent > 999)) {
            throw UserError::make($rule === 'discount' ? 'price_list.invalid_discount' : 'price_list.invalid_markup');
        }

        $values['price_rule'] = $rule;
        $values['percent'] = $rule === 'none' ? '0' : number_format($percent, 2, '.', '');

        static::$channels = static::requestedChannels($_POST['channels'] ?? ($values['channels'] ?? []));
        static::checkChannels(static::$channels, $action === 'update' ? (int) ($oldValues['id'] ?? ($_GET['id'] ?? 0)) : 0);
        unset($values['channels']);

        static::checkItems(Repeater::rowsFromRequest('items', (array) $_POST));
        $values['applies_to_all'] = static::modeOf($values['scope_mode'] ?? null) === 'all' ? 'true' : 'false';

        // Il selettore e il modo non sono colonne: si salvano nei ponti.
        return array_diff_key($values, array_flip([...static::FIELDS, 'scope_mode', static::SEARCH]));
    }

    /**
     * I canali dal form, solo quelli noti.
     *
     * @return list<string>
     */
    public static function requestedChannels(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : ($value === '' ? [] : [$value]);
        }

        $known = array_keys(static::channelOptions((int) (static::currentId() ?? 0)));

        return array_values(array_intersect($known, array_map('strval', (array) $value)));
    }

    /**
     * I canali da scegliere: quelli accesi sul sito, più quelli già assegnati
     * al listino anche se spenti (con «non attivo»), così non si perdono.
     *
     * @return array<string, string>
     */
    public static function channelOptions(int $priceListId): array
    {
        $all = PriceListChannels::all();
        $options = PriceListChannels::active();

        foreach ($priceListId > 0 ? PriceLists::channelsOf($priceListId) : [] as $channel) {
            if (!isset($options[$channel])) {
                $options[$channel] = ($all[$channel] ?? $channel).' (non attivo)';
            }
        }

        return $options;
    }

    /** Un canale ha un solo listino: se è già di un altro, si rifiuta. @param list<string> $channels */
    private static function checkChannels(array $channels, int $priceListId): void
    {
        foreach ($channels as $channel) {
            $owner = PriceLists::forChannelAny($channel);

            if ($owner > 0 && $owner !== $priceListId) {
                $found = PriceList::find(['id' => $owner]);
                $name = is_array($found) && array_key_exists('id', $found) ? html_entity_decode((string) ($found['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '#'.$owner;

                throw UserError::make('price_list.channel_taken', [
                    'channel' => PriceListChannels::all()[$channel] ?? $channel,
                    'list' => $name,
                ]);
            }
        }
    }

    /** «Sito, Google Shopping», vuoto se nessuno. */
    public static function channelLabels(int $priceListId): string
    {
        $labels = PriceListChannels::all();

        return implode(', ', array_map(static fn (string $channel): string => $labels[$channel] ?? $channel, PriceLists::channelsOf($priceListId)));
    }

    /** Le date senza ora; quelle «zero» di MySQL vuote. */
    public static function mutateFormValues(array $values, string $mode, string $context = 'backend'): array
    {
        foreach (['valid_from', 'valid_to'] as $column) {
            if (array_key_exists($column, $values)) {
                $values[$column] = static::day($values[$column]);
            }
        }

        $id = (int) ($values['id'] ?? 0);
        $values['channels'] = PriceLists::channelsOf($id);

        if ($mode === 'edit' && $id > 0) {
            $values = static::loadScope('price_list', $id) + $values;
        }

        $values['scope_mode'] = static::scopeMode($values);

        if (($values['price_rule'] ?? 'none') === 'none' || (float) ($values['percent'] ?? 0) <= 0) {
            $values['percent'] = '';
        }

        return $values;
    }

    /**
     * Le righe senza prodotto né prezzo non sono righe; le altre col punto
     * decimale e la quantità minima almeno 1.
     */
    public static function prepareRepeaterRows(
        string $inputName,
        array $rows,
        string $action = 'store',
        string $context = 'backend'
    ): array {
        if ($inputName !== 'items') {
            return $rows;
        }

        $items = [];

        foreach ($rows as $row) {
            if (!is_array($row) || static::emptyRow($row)) {
                continue;
            }

            $row['product_id'] = (int) ($row['product_id'] ?? 0);
            $row['min_quantity'] = Numbers::fromForm($row['min_quantity'] ?? null) ?? '1';
            $row['price'] = Numbers::fromForm($row['price'] ?? null) ?? '0';
            $items[] = $row;
        }

        return $items;
    }

    public static function afterStore(object $result, array $values = []): void
    {
        static::afterSave((int) ($result->insert_id ?? 0));
    }

    public static function afterUpdate(int|string $id, object $result, array $values = []): void
    {
        static::afterSave((int) $id);
    }

    /** Canali e selettore; con «Cerca prodotti» anche le righe, poi di nuovo la scheda. */
    private static function afterSave(int $id): void
    {
        static::writeChannels($id);
        static::writeScope($id);
        $post = (array) $_POST;

        // Un Invio nel form preme il primo submit: le righe si creano solo coi filtri.
        if ($id > 0 && array_key_exists(static::SEARCH, $post) && static::modeOf($post['scope_mode'] ?? null) === 'filter') {
            static::searchProducts($id);
        }
    }

    /**
     * Il modo di «Si applica a» di un listino salvato: `all` se vale per
     * tutto, `filter` se ha filtri, altrimenti `products` (solo le righe).
     *
     * @param array<string, mixed> $values come `loadScope()`
     */
    public static function scopeMode(array $values): string
    {
        if (($values['applies_to_all'] ?? 'true') !== 'false') {
            return 'all';
        }

        foreach (static::FIELDS as $field) {
            if ($field !== 'excluded_models' && array_filter((array) ($values[$field] ?? [])) !== []) {
                return 'filter';
            }
        }

        return 'products';
    }

    /** Il modo dal form, `all` se sconosciuto. */
    public static function modeOf(mixed $value): string
    {
        return is_string($value) && isset(static::SCOPE_MODES[$value]) ? $value : 'all';
    }

    /**
     * Il selettore da salvare per un modo: con «Seleziona prodotti» niente
     * filtri, così la regola non vale per nessun prodotto oltre le righe.
     *
     * @param array{all: bool, categories: list<int>, tags: list<int>, brands: list<int>, models: list<int>, excluded_models: list<int>} $scope come `readScope()`
     * @return array{all: bool, categories: list<int>, tags: list<int>, brands: list<int>, models: list<int>, excluded_models: list<int>}
     */
    public static function scopeFor(string $mode, array $scope): array
    {
        $scope['all'] = $mode === 'all';

        if ($mode === 'products') {
            $scope = ['all' => false, 'categories' => [], 'tags' => [], 'brands' => [], 'models' => [], 'excluded_models' => []];
        }

        return $scope;
    }

    public static function afterDelete(int|string $id, object $result, array $values = []): void
    {
        RequestMemo::forget(PriceList::MEMO_GROUP);
        parent::afterDelete($id, $result, $values);
    }

    /** Il backend scrive la testata con una query diretta: la memoria si svuota. */
    private static function writeScope(int $priceListId): void
    {
        RequestMemo::forget(PriceList::MEMO_GROUP);

        if ($priceListId > 0 && array_key_exists('scope_mode', (array) $_POST)) {
            $post = (array) $_POST;
            $scope = static::scopeFor(static::modeOf($post['scope_mode']), static::readScope($post));
            Transaction::run(static fn () => static::saveScope('price_list', $priceListId, $scope));
        }
    }

    private static function writeChannels(int $priceListId): void
    {
        if ($priceListId > 0 && static::$channels !== null) {
            PriceLists::assignChannels($priceListId, static::$channels);
        }

        static::$channels = null;
    }

    /** Quanti clienti e ordini lo usano. */
    public static function usage(int $id): int
    {
        if ($id <= 0) {
            return 0;
        }

        return (int) sqlCount(Order::$table, 'price_list_id = '.$id." AND deleted = 'false'")
            + (int) sqlCount(Contact::$table, 'price_list_id = '.$id." AND deleted = 'false'")
            + count(PriceLists::channelsOf($id));
    }

    /** Un listino in uso non si elimina: si spegne. */
    public static function assertDeletable(int|string $id): void
    {
        $count = static::usage((int) $id);

        if ($count > 0) {
            throw UserError::refusal('price_list.in_use', ['count' => $count]);
        }
    }

    /**
     * Ogni riga ha prodotto e prezzo; lo stesso prodotto non torna con la
     * stessa quantità minima.
     *
     * @param list<array<string, mixed>> $rows
     */
    private static function checkItems(array $rows): void
    {
        $seen = [];

        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row) || static::emptyRow($row)) {
                continue;
            }

            $line = $index + 1;
            $productId = (int) ($row['product_id'] ?? 0);
            $min = Numbers::fromForm($row['min_quantity'] ?? null) ?? '1';
            $price = Numbers::fromForm($row['price'] ?? null);

            if ($productId <= 0) {
                throw UserError::make('price_list.item_no_product', ['row' => $line]);
            }

            if ((float) $min <= 0 || $price === null || (float) $price < 0) {
                throw UserError::make('price_list.item_invalid', ['row' => $line]);
            }

            $key = $productId.'|'.round((float) $min, 3);

            if (isset($seen[$key])) {
                throw UserError::make('price_list.item_duplicate', ['row' => $line]);
            }

            $seen[$key] = true;
        }
    }

    /** @param array<string, mixed> $row */
    private static function emptyRow(array $row): bool
    {
        return (int) ($row['product_id'] ?? 0) <= 0 && trim((string) ($row['price'] ?? '')) === '';
    }

    /** Una data come `Y-m-d` (dal form anche `d/m/Y`); vuota se non lo è. */
    private static function day(mixed $value): string
    {
        $value = trim((string) $value);

        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $m) === 1) {
            return $m[1] === '0000-00-00' ? '' : $m[1];
        }

        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $value, $m) === 1) {
            return $m[3].'-'.$m[2].'-'.$m[1];
        }

        return '';
    }

    private static function date(string $value): string
    {
        $day = static::day($value);

        return $day === '' ? '' : date('d/m/Y', (int) strtotime($day));
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
