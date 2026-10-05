<?php

namespace Wonder\Plugin\Gestionale\Resources\Promotions;

use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\RichText;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Promotions\Coupon;
use Wonder\Plugin\Gestionale\Models\Promotions\CouponCustomer;
use Wonder\Plugin\Gestionale\Models\Promotions\CouponRedemption;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderResource;
use Wonder\Plugin\Gestionale\Support\Contacts\Contacts;
use Wonder\Plugin\Gestionale\Support\Numbers;
use Wonder\Plugin\Gestionale\Support\Promotions\Campaigns;
use Wonder\Plugin\Gestionale\Support\Promotions\Coupons;
use Wonder\Plugin\Gestionale\Support\Promotions\PromotionSheet;
use Wonder\Plugin\Gestionale\Support\Promotions\ScopeForm;
use Wonder\Plugin\Gestionale\Support\Sales\Channels;
use Wonder\Sql\Transaction;

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
            'starts_at' => 'Dal',
            'ends_at' => 'Fino al',
            'period' => 'Periodo',
            'usage' => 'Utilizzi',
            'active' => 'Interruttore',
            'applies_online' => 'Sito',
            'applies_office' => 'Ufficio',
            'applies_pos' => 'Cassa',
            'customers' => 'Clienti riservati',
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
            ...static::scopeFields(),
            FormField::key('customers')
                ->selectSearch(static::customerOptions(), true)
                ->label('Clienti riservati'),
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
                ->tooltip('Spesa minima sui prodotti adatti (per la spedizione gratuita, su tutti i prodotti). Gli utilizzi si contano alla creazione dell\'ordine e tornano disponibili se l\'ordine viene annullato o scade. Il coupon non si somma a uno sconto scritto a mano sull\'ordine.')
                ->columnSpan(12),
            static::getInput('min_order_amount')->columnSpan(4),
            static::getInput('usage_limit')->columnSpan(4),
            static::getInput('usage_limit_per_customer')->columnSpan(4),
            static::getInput('first_order_only')->columnSpan(6),
            static::getInput('exclude_discounted_products')->columnSpan(6),
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

        $left = [$main, $rules, $scope];

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

                    return '<span class="badge text-bg-'.$class.'">'.static::escape(static::statusLabel($row, $now())).'</span>';
                }),
            TableColumn::key('actions')->button()->actions(['view', 'edit', 'delete']),
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
            ->actions('view', static fn (array $item): array => [[
                'label' => 'Modifica',
                'icon' => 'bi-pencil',
                'class' => 'btn-warning btn-sm',
                'href' => static::editUrlFor((int) ($item['id'] ?? 0)),
            ]]);
    }

    /**
     * La scheda in lettura: come il form, due colonne (otto e quattro), con in
     * più gli utilizzi, che nel form non stanno.
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

        $main = (new Card)->components([
            SectionTitle::make('Coupon')->columnSpan(12),
            RichText::make('<h5 class="mb-0">'.static::escape((string) ($row['code'] ?? '')).' '
                .'<span class="badge text-bg-'.$class.' align-middle">'.static::escape(static::statusLabel($row + ['active' => 'false'], $now)).'</span></h5>')
                ->tag('div')
                ->columnSpan(12),
            static::sheetRow('Nome', $name)->columnSpan(6),
            static::sheetRow('Sconto', static::discountLabel($row))->columnSpan(6),
            static::sheetRow('Periodo', static::periodLabel($row))->columnSpan(6),
            static::sheetRow('Utilizzi', static::usageLabel(Coupons::usedCount($id), $row))->columnSpan(6),
        ])->columns(12)->columnSpan(12);

        $rules = (new Card)->components([
            SectionTitle::make('Regole')->columnSpan(12),
            static::sheetRow('Spesa minima', $minimum > 0 ? number_format($minimum, 2, ',', '.').' €' : '')->columnSpan(6),
            static::sheetRow('Utilizzi massimi', $limit('usage_limit'))->columnSpan(6),
            static::sheetRow('Utilizzi per cliente', $limit('usage_limit_per_customer'))->columnSpan(6),
            static::sheetRow('Solo sul primo ordine', $yes('first_order_only'))->columnSpan(6),
            static::sheetRow('Non sui prodotti già scontati', $yes('exclude_discounted_products'))->columnSpan(6),
        ])->columns(12)->columnSpan(12);

        $customers = array_map(
            static fn (array $link): string => static::escape(static::customerName((int) $link['customer_id'], '')),
            static::rowsOf(CouponCustomer::class, ['coupon_id' => $id])
        );
        $scope = (new Card)->components([
            SectionTitle::make('Prodotti e clienti')->columnSpan(12),
            static::sheetRow('Prodotti', static::scopeHtml('coupon', $id), true)->columnSpan(12),
            static::sheetRow('Clienti riservati', implode(', ', $customers), true)->columnSpan(12),
        ])->columns(12)->columnSpan(12);

        $redemptions = (new Card)->components([
            SectionTitle::make('Utilizzi')
                ->tooltip('Gli ordini che hanno usato il coupon. «Rilasciato» vuol dire che l\'ordine è stato annullato o è scaduto e l\'utilizzo non conta più.')
                ->columnSpan(12),
            RichText::make(static::redemptionsHtml($id))->columnSpan(12),
        ])->columns(12)->columnSpan(12);

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
            (new Container)->components([$main, $rules, $scope, $redemptions])->columns(12)->columnSpan(8),
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
            ->title('Coupon')
            ->order(20)
            ->authority(['admin', 'administrator']);
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

        Coupons::validate(
            ['customers' => static::readCustomers((array) $_POST)] + $values,
            (int) ($oldValues['id'] ?? 0)
        );

        // Selettore e clienti riservati non sono colonne: si salvano a parte.
        return array_diff_key($values, array_flip([...static::FIELDS, 'customers']));
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
            $values['customers'] = array_map(
                static fn (array $row): string => (string) $row['customer_id'],
                static::rowsOf(CouponCustomer::class, ['coupon_id' => $id])
            );
        }

        return $values;
    }

    public static function afterStore(object $result, array $values = []): void
    {
        $id = (int) ($result->insert_id ?? 0);

        if ($id > 0) {
            static::saveRelations($id);
        }
    }

    public static function afterUpdate(int|string $id, object $result, array $values = []): void
    {
        static::saveRelations((int) $id);
    }

    /**
     * La tabella degli utilizzi, dal più recente: ordine (con il link), cliente,
     * sconto, data e se è stato rilasciato. Senza utilizzi, una riga di testo.
     */
    public static function redemptionsHtml(int $couponId): string
    {
        $rows = $couponId > 0 ? static::rowsOf(CouponRedemption::class, ['coupon_id' => $couponId], 'id', 'DESC') : [];

        if ($rows === []) {
            return '<p class="text-muted mb-0">Nessun utilizzo.</p>';
        }

        $html = '<table class="table table-sm align-middle mb-0"><thead><tr>'
            .'<th>Ordine</th><th>Cliente</th><th class="text-end">Sconto</th><th>Data</th><th>Rilasciato</th>'
            .'</tr></thead><tbody>';

        foreach ($rows as $row) {
            $orderId = (int) ($row['order_id'] ?? 0);
            $order = '<a href="'.static::escape(OrderResource::detailUrl($orderId)).'">#'.$orderId.'</a>';
            $released = static::dayOf($row['released_at'] ?? '') !== '';

            $html .= '<tr><td>'.$order.'</td>'
                .'<td>'.static::escape(static::customerName((int) ($row['customer_id'] ?? 0), (string) ($row['email'] ?? ''))).'</td>'
                .'<td class="text-end">'.static::escape(number_format((float) ($row['discount_amount'] ?? 0), 2, ',', '.')).' €</td>'
                .'<td>'.static::escape(static::dayOf($row['redeemed_at'] ?? '')).'</td>'
                .'<td>'.($released ? 'Sì' : 'No').'</td></tr>';
        }

        return $html.'</tbody></table>';
    }

    /** Selettore dei prodotti e clienti riservati, riscritti per intero. */
    protected static function saveRelations(int $id): void
    {
        $post = (array) $_POST;
        $customers = static::readCustomers($post);

        Transaction::run(static function () use ($id, $post, $customers): void {
            static::saveScope('coupon', $id, static::readScope($post));

            foreach (static::rowsOf(CouponCustomer::class, ['coupon_id' => $id]) as $row) {
                CouponCustomer::delete((int) $row['id']);
            }

            foreach ($customers as $customerId) {
                CouponCustomer::create(['coupon_id' => $id, 'customer_id' => $customerId]);
            }
        });
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
    protected static function customerName(int $customerId, string $email): string
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
