<?php

namespace Wonder\Plugin\Gestionale\Resources\Promotions;

use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\Elements\Components\Alert;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\RichText;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Promotions\DiscountCampaign;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Numbers;
use Wonder\Plugin\Gestionale\Support\Promotions\Campaigns;
use Wonder\Plugin\Gestionale\Support\Promotions\ProductScope;
use Wonder\Plugin\Gestionale\Support\Promotions\PromotionSheet;
use Wonder\Plugin\Gestionale\Support\Promotions\ScopeForm;
use Wonder\Plugin\Gestionale\Support\Sales\Channels;

/**
 * «Campagne di sconto»: un prezzo che cambia da solo, in un periodo, per
 * tutto il catalogo o per una selezione di prodotti.
 *
 * Lo stato (programmata, in corso, terminata, disattivata) non si scrive: lo
 * ricava `Campaigns::status` da interruttore e date. Il selettore dei prodotti
 * è quello dei coupon (`ScopeForm`) e sta nelle tabelle dei ponti; si
 * riscrive per intero a ogni salvataggio.
 *
 * La campagna si apre in una **scheda di lettura** (`showLayoutSchema`): lì
 * stanno l'**anteprima** (tutti i prodotti che la campagna prende, in una
 * tabella del core con nome per intero, SKU, prezzo prima e dopo e la ricerca) e, se un'altra campagna attiva copre gli stessi
 * prodotti negli stessi giorni, un **avviso** che non blocca niente — il
 * calcolo sceglie comunque lo sconto maggiore. La modifica è dietro al
 * bottone «Modifica».
 */
class DiscountCampaignResource extends GestionaleResource
{
    use ScopeForm;
    use PromotionSheet;

    public static string $feature = 'discount_campaigns';
    public static string $model = DiscountCampaign::class;
    public static string $orderColumn = 'id';
    public static string $orderDirection = 'DESC';
    public static string $docsPage = 'promozioni/promozioni-campagne';

    public static function path(): string
    {
        return 'app/gestionale/campagne-sconto';
    }

    public static function icon(): string
    {
        return 'bi-percent';
    }

    public static function titleLabel(): string
    {
        return 'Campagne di sconto';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'campagna',
            'plural_label' => 'campagne',
            'last' => 'ultime',
            'all' => 'tutte',
            'article' => 'le',
            'full' => 'attiva',
            'empty' => 'disattivata',
            'this' => 'questa',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'name' => 'Nome',
            'discount_type' => 'Tipo di sconto',
            'discount_value' => 'Sconto',
            'starts_at' => 'Dal',
            'ends_at' => 'Fino al',
            'period' => 'Periodo',
            'active' => 'Interruttore',
            'exclude_sale_products' => 'Non sui prodotti già scontati',
            'applies_online' => 'Sito',
            'applies_office' => 'Ufficio',
            'applies_pos' => 'Cassa',
            'channels' => 'Canali',
            'status' => 'Stato',
            'note' => 'Note',
        ];
    }

    public static function formSchema(): array
    {
        return [
            FormField::key('name')->text()->label('Nome')->required(),
            FormField::key('discount_type')
                ->select(['percent' => 'Percentuale (%)', 'amount' => 'Importo (€)'])
                ->value('percent')
                ->label('Tipo di sconto')
                ->required(),
            FormField::key('discount_value')->number()->decimal(2)->label('Sconto')->required(),
            FormField::key('starts_at')->dateInput()->label('Dal'),
            FormField::key('ends_at')->dateInput()->label('Fino al'),
            FormField::key('active')
                ->select(['true' => 'Attiva', 'false' => 'Disattivata'])
                ->value('true')
                ->label('Interruttore')
                ->required(),
            FormField::key('exclude_sale_products')
                ->toggle()
                ->value('false')
                ->label('Non sui prodotti già scontati'),
            FormField::key('applies_online')->toggle()->value(Channels::defaults()['applies_online'])->label('Sito'),
            FormField::key('applies_office')->toggle()->value(Channels::defaults()['applies_office'])->label('Ufficio'),
            FormField::key('applies_pos')->toggle()->value(Channels::defaults()['applies_pos'])->label('Cassa'),
            ...static::scopeFields(),
            FormField::key('note')->textarea()->label('Note'),
        ];
    }

    public static function formLayoutSchema(): ?Form
    {
        $main = (new Card)->components([
            SectionTitle::make('Campagna')
                ->tooltip('Dal primo all\'ultimo giorno scelti, estremi compresi. Senza «Fino al» la campagna non finisce. Se più campagne coprono lo stesso prodotto vince lo sconto maggiore. Un prezzo scritto a mano sulla riga d\'ordine vince su tutto.')
                ->columnSpan(12),
            static::getInput('name')->columnSpan(8),
            static::getInput('active')->columnSpan(4),
            static::getInput('discount_type')->columnSpan(4),
            static::getInput('discount_value')->columnSpan(4),
            static::getInput('exclude_sale_products')->columnSpan(4),
            static::getInput('starts_at')->columnSpan(6),
            static::getInput('ends_at')->columnSpan(6),
        ])->columns(12)->columnSpan(12);

        $scope = (new Card)->components([
            SectionTitle::make('Prodotti')
                ->tooltip('«Tutto il catalogo» o «Solo la selezione»: categorie (con le loro sottocategorie), tag, marchi e articoli. Gli articoli esclusi non hanno mai lo sconto, anche se rientrano in una categoria scelta o nel catalogo intero.')
                ->columnSpan(12),
            static::getInput('applies_to_all')->columnSpan(12),
            static::getInput('categories')->columnSpan(12),
            static::getInput('tags')->columnSpan(12),
            static::getInput('brands')->columnSpan(12),
            static::getInput('models')->columnSpan(12),
            static::getInput('excluded_models')->columnSpan(12),
        ])->columns(12)->columnSpan(12);

        $side = [];

        // «Dove vale» c'è solo se il sito ha più di un canale acceso.
        if (Channels::choose()) {
            $side[] = (new Card)->components([
                SectionTitle::make('Dove vale')
                    ->tooltip('Il sito applica la campagna al carrello. Ufficio e Cassa la usano per gli ordini fatti dal gestionale e dalla cassa.')
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

        $left = [$main, $scope];

        return (new Form)->components([
            (new Container)->components($left)->columns(12)->columnSpan(8),
            (new Container)->components($side)->columns(12)->columnSpan(4),
        ])->columns(12);
    }

    public static function tableSchema(): array
    {
        $now = static fn (): string => date('Y-m-d H:i:s');

        return [
            TableColumn::key('name')->text()->link('view'),
            TableColumn::key('discount_value')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(static::discountLabel($row))),
            TableColumn::key('period')
                ->text()
                ->formatter(static fn (array $row): string => static::escape(static::periodLabel($row))),
            TableColumn::key('channels')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(static::channelsLabel($row))),
            TableColumn::key('status')
                ->text()
                ->size('little')
                ->formatter(static function (array $row) use ($now): string {
                    $status = Campaigns::status($row, $now());
                    $class = ['running' => 'success', 'scheduled' => 'primary', 'ended' => 'secondary', 'inactive' => 'light'][$status] ?? 'light';

                    return '<span class="badge text-bg-'.$class.'">'.static::escape(static::statusLabel($row, $now())).'</span>';
                }),
            TableColumn::key('actions')->button()->actions(['view', 'edit', 'delete']),
        ];
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()
            // La campagna si apre in lettura: anteprima e avviso stanno nella
            // scheda, la modifica è dietro al bottone «Modifica».
            ->enable(['view'])
            ->titles([
                'list' => 'Campagne di sconto',
                'create' => 'Nuova campagna',
                'view' => 'Campagna',
                'edit' => 'Modifica campagna',
            ])
            ->view('show', Gestionale::viewPath('pages/campaign-show.php'))
            ->actions('view', static fn (array $item): array => [[
                'label' => 'Modifica',
                'icon' => 'bi-pencil',
                'class' => 'btn-warning btn-sm',
                'href' => static::editUrlFor((int) ($item['id'] ?? 0)),
            ]]);
    }

    /**
     * La scheda in lettura: come il form, due colonne (otto e quattro), con in
     * più l'anteprima e l'avviso di sovrapposizione, che nel form non stanno.
     */
    public static function showLayoutSchema(array $row): Container
    {
        $id = (int) ($row['id'] ?? 0);
        $now = date('Y-m-d H:i:s');
        $status = Campaigns::status($row + ['active' => 'false'], $now);
        $class = ['running' => 'success', 'scheduled' => 'primary', 'ended' => 'secondary', 'inactive' => 'light'][$status] ?? 'light';
        $yes = static fn (string $key): string => ($row[$key] ?? 'false') === 'true' ? 'Sì' : 'No';

        $main = (new Card)->components([
            SectionTitle::make('Campagna')->columnSpan(12),
            RichText::make('<h5 class="mb-0">'.static::escape(trim((string) ($row['name'] ?? ''))).' '
                .'<span class="badge text-bg-'.$class.' align-middle">'.static::escape(static::statusLabel($row + ['active' => 'false'], $now)).'</span></h5>')
                ->tag('div')
                ->columnSpan(12),
            static::sheetRow('Sconto', static::discountLabel($row))->columnSpan(6),
            static::sheetRow('Periodo', static::periodLabel($row))->columnSpan(6),
            static::sheetRow('Non sui prodotti già scontati', $yes('exclude_sale_products'))->columnSpan(6),
        ])->columns(12)->columnSpan(12);

        $scope = (new Card)->components([
            SectionTitle::make('Prodotti')->columnSpan(12),
            static::sheetRow('Prodotti', static::scopeHtml('campaign', $id), true)->columnSpan(12),
        ])->columns(12)->columnSpan(12);

        $preview = (new Card)->components([
            SectionTitle::make('Anteprima')
                ->tooltip('I prodotti che la campagna prende così com\'è salvata, con il prezzo prima e dopo lo sconto. Si cerca per nome o SKU.')
                ->columnSpan(12),
            RichText::make(static::previewHtml($id))->columnSpan(12),
        ])->columns(12)->columnSpan(12);

        $left = [$main, $scope, $preview];
        $notice = static::overlapNotice($id);

        if ($notice !== '') {
            array_unshift($left, (new Container)->components([Alert::make($notice, 'warning')])->columnSpan(12));
        }

        $side = [
            (new Card)->components([
                SectionTitle::make('Dove vale')->columnSpan(12),
                RichText::make(static::channelsHtml($row))->tag('div')->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ];

        if (trim((string) ($row['note'] ?? '')) !== '') {
            $side[] = (new Card)->components([
                SectionTitle::make('Note')->columnSpan(12),
                static::sheetRow('', (string) $row['note'])->columnSpan(12),
            ])->columns(12)->columnSpan(12);
        }

        return (new Container)->components([
            (new Container)->components($left)->columns(12)->columnSpan(8),
            (new Container)->components($side)->columns(12)->columnSpan(4),
        ])->columns(12);
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->backendCrud(['admin', 'administrator']);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return parent::navigationSchema()
            ->section('promozioni', 'Promozioni', 'bi-percent', 360, ['admin', 'administrator'])
            ->title('Campagne di sconto')
            ->order(10)
            ->authority(['admin', 'administrator']);
    }

    /** «20 %», «12,5 %» o «10,00 €». */
    public static function discountLabel(array $row): string
    {
        $value = (float) ($row['discount_value'] ?? 0);

        if (($row['discount_type'] ?? 'percent') === 'amount') {
            return number_format($value, 2, ',', '').' €';
        }

        return rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',').' %';
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

    /** I canali accesi per nome; «—» se nessuno. */
    public static function channelsLabel(array $row): string
    {
        $names = [];

        foreach (['applies_online' => 'Online', 'applies_office' => 'Ufficio', 'applies_pos' => 'Cassa'] as $column => $name) {
            if (($row[$column] ?? 'false') === 'true') {
                $names[] = $name;
            }
        }

        return $names === [] ? '—' : implode(', ', $names);
    }

    public static function statusLabel(array $row, string $now): string
    {
        return [
            'running' => 'In corso',
            'scheduled' => 'Programmata',
            'ended' => 'Terminata',
            'inactive' => 'Disattivata',
        ][Campaigns::status($row, $now)];
    }

    /**
     * I valori della richiesta pronti da scrivere: sconto numerico, date piene
     * (il primo giorno dalle 00:00, l'ultimo fino alle 23:59:59, così
     * «estremi compresi» vale davvero), canali e interruttori. Una campagna
     * che non sta in piedi si rifiuta qui, prima di scrivere qualsiasi cosa.
     */
    public static function mutateRequestValues(
        array $values,
        string $action,
        string $context = 'backend',
        ?array $oldValues = null
    ): array {
        $values = Channels::keepHidden($values, $oldValues);
        $values['discount_type'] = ($values['discount_type'] ?? '') === 'amount' ? 'amount' : 'percent';
        $values['discount_value'] = Numbers::fromForm($values['discount_value'] ?? null) ?? '';
        $values['starts_at'] = static::momentOf($values['starts_at'] ?? '', '00:00:00');
        $values['ends_at'] = static::momentOf($values['ends_at'] ?? '', '23:59:59');

        Campaigns::validate($values + ['scope' => static::readScope((array) $_POST)]);

        // Il selettore non è una colonna: si salva nei ponti.
        return array_diff_key($values, array_flip(static::FIELDS));
    }

    /** Riempie il form con il selettore salvato e le date senza ora. */
    public static function mutateFormValues(array $values, string $mode, string $context = 'backend'): array
    {
        foreach (['starts_at', 'ends_at'] as $column) {
            if (array_key_exists($column, $values)) {
                $values[$column] = static::dayOf($values[$column], 'Y-m-d');
            }
        }

        $id = (int) ($values['id'] ?? 0);

        if ($mode === 'edit' && $id > 0) {
            $values = static::loadScope('campaign', $id) + $values;
        }

        return $values;
    }

    public static function afterStore(object $result, array $values = []): void
    {
        $id = (int) ($result->insert_id ?? 0);

        if ($id > 0) {
            static::saveScope('campaign', $id, static::readScope((array) $_POST));
        }
    }

    public static function afterUpdate(int|string $id, object $result, array $values = []): void
    {
        static::saveScope('campaign', (int) $id, static::readScope((array) $_POST));
    }

    /**
     * Il testo dell'avviso se un'altra campagna attiva copre gli stessi
     * prodotti negli stessi giorni; vuoto se nessuna.
     */
    public static function overlapNotice(int $id): string
    {
        $row = static::savedCampaign($id);

        if ($row === null) {
            return '';
        }

        $names = Campaigns::overlaps($row + ['scope' => ProductScope::of('campaign', $id)], date('Y-m-d H:i:s'), $id);

        if ($names === []) {
            return '';
        }

        return 'Anche '.implode(', ', array_map(static fn (string $name): string => '«'.$name.'»', $names))
            .' copre'.(count($names) > 1 ? 'no' : '').' alcuni di questi prodotti negli stessi giorni: su ciascuno vale lo sconto maggiore.';
    }

    /**
     * I prodotti che la campagna salvata prende: quanti sono e, sotto, la
     * tabella del core (`CampaignProductTableResource`) con foto, nome per
     * intero, SKU, prezzo di prima e di dopo, la ricerca e le pagine.
     */
    public static function previewHtml(int $id): string
    {
        $row = static::savedCampaign($id);

        if ($row === null) {
            return '';
        }

        $preview = Campaigns::preview($row + ['scope' => ProductScope::of('campaign', $id)], date('Y-m-d H:i:s'));

        return '<p class="mb-2"><strong>'.(int) $preview['count'].'</strong> '.($preview['count'] === 1 ? 'prodotto' : 'prodotti').'.</p>'
            .($preview['products'] === [] ? '' : CampaignProductTableResource::embed($id, array_column($preview['products'], 'id')));
    }

    /** @return array<string, mixed>|null */
    protected static function savedCampaign(int $id): ?array
    {
        $row = static::rowsOf(DiscountCampaign::class, ['id' => $id])[0] ?? null;

        return is_array($row) ? $row : null;
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
