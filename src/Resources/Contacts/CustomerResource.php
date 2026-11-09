<?php

namespace Wonder\Plugin\Gestionale\Resources\Contacts;

use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\Input;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\RepeaterColumn;
use Wonder\App\ResourceSchema\RepeaterRelation;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\App\ResourceSchema\TableLayoutSchema;
use Throwable;
use Wonder\Elements\Components\Accordion;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\RichText;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Contacts\Company;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Contacts\ContactAddress;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentTerm;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Subscription;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Resources\Pricing\PriceListResource;
use Wonder\Plugin\Gestionale\Resources\Sales\CustomerOrderTableResource;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderItemTableResource;
use Wonder\Plugin\CustomerAccount\Resources\Concerns\HasContactRelations;
use Wonder\Plugin\CustomerAccount\Models\ContactRelation;
use Wonder\Plugin\CustomerAccount\Support\ContactExport;
use Wonder\Plugin\CustomerAccount\Support\ContactRelations;
use Wonder\Plugin\Gestionale\Support\Contacts\ContactExports;
use Wonder\Plugin\Gestionale\Support\Contacts\Contacts;
use Wonder\Plugin\Gestionale\Support\Contacts\CustomerSheet;
use Wonder\Plugin\Gestionale\Support\Contacts\CustomerStats;
use Wonder\Plugin\Gestionale\Support\Contacts\VisitorCountry;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Tax\VatCollectability;
use Wonder\Plugin\Custom\Fattura\Valori\EsigibilitaIva;
use Wonder\Sql\Transaction;
use Wonder\App\Visibility\RowVisibility;
use Wonder\Plugin\Gestionale\Support\SalesAgents\SalesAgents;

/**
 * "Clienti": le persone della rubrica, e la loro scheda.
 *
 * Le aziende hanno la loro Resource, `CompanyResource`, che estende questa:
 * cambiano modello, legami e colonne dei documenti (i punti di estensione
 * sono `documentColumn()`, `hasAccount()`, `addressModel()` e
 * `relationSide()`). `SupplierResource` estende quella delle aziende: i
 * fornitori sono sempre aziende.
 *
 * Non è `final`: la estende `CompanyResource`, e i test con una classe
 * anonima.
 */
class CustomerResource extends GestionaleResource
{
    use HasContactRelations;

    public static string $model = Contact::class;
    public static string $orderColumn = 'id';
    public static string $orderDirection = 'DESC';
    public static string $docsPage = 'anagrafiche/anagrafiche';

    /** La colonna del ruolo che questo elenco mostra. */
    public static function roleColumn(): string
    {
        return 'is_customer';
    }

    /** Vero per le Resource delle aziende. */
    public static function isCompanySide(): bool
    {
        return static::relationSide() === ContactRelations::COMPANY;
    }

    /** La colonna dei documenti che punta a questa scheda. */
    public static function documentColumn(): string
    {
        return 'customer_id';
    }

    /** Solo le persone hanno un account sul sito. */
    public static function hasAccount(): bool
    {
        return true;
    }

    /** Il modello degli indirizzi di consegna. @return class-string */
    public static function addressModel(): string
    {
        return ContactAddress::class;
    }

    /** La colonna che lega gli indirizzi di consegna alla scheda. */
    public static function addressColumn(): string
    {
        return 'contact_id';
    }

    /** Il campo «Ruolo» (cliente, fornitore): solo per le aziende, con gli acquisti. */
    public static function rolesShown(): bool
    {
        return false;
    }

    /** Le colonne controllate contro i doppioni. @return array<string, string> */
    protected static function uniqueColumns(): array
    {
        return ['cf' => 'contact.tax_code_taken', 'email' => 'contact.email_taken'];
    }

    public static function path(): string
    {
        return 'app/gestionale/clienti';
    }

    public static function icon(): string
    {
        return 'bi-person-vcard';
    }

    public static function titleLabel(): string
    {
        return 'Clienti';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'cliente',
            'plural_label' => 'clienti',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'i',
            'this' => 'questo',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'email' => 'Email',
            'auth_method' => 'Accesso',
            'roles' => 'Ruolo',
            'is_customer' => 'Ruolo',
            'note' => 'Note',
            'active' => 'Stato',
            'addresses' => 'Indirizzi di consegna',
            'label' => 'Etichetta',
            ...static::$model::billing()->labels(),
        ];
    }

    public static function formSchema(): array
    {
        $billing = static::$model::billing()->formSchema();

        $fields = [
            ...array_values($billing),
            FormField::key('email')->email()->label('Email'),
            FormField::key('active')
                ->select(['true' => 'Attiva', 'false' => 'Non attiva'])
                ->value('true')
                ->label('Stato')
                ->required(),
            FormField::key('note')->textarea()->label('Note'),
            static::addressesField(),
        ];

        // Il ruolo si sceglie solo dove c'è una scelta: le persone sono solo
        // clienti, e senza gli acquisti i fornitori non esistono (D20).
        if (static::rolesShown()) {
            $fields[] = FormField::key('roles')
                ->select(Contacts::ROLE_CHOICES)
                ->value(static::defaultRoleChoice())
                ->label('Ruolo')
                ->required();
        }

        // G9: metodo e condizione che l'ordine da ufficio propone. La
        // condizione si sceglie solo se ce n'è più d'una (D20).
        if (static::paymentDefaultsShown()) {
            $fields[] = FormField::key('payment_method_id')
                ->select(['' => 'Nessuno'] + static::officeMethods())
                ->label('Metodo di pagamento');

            if (count(static::activeTerms()) > 1) {
                $fields[] = FormField::key('payment_term_id')
                    ->select(['' => 'Nessuna'] + static::activeTerms())
                    ->label('Condizione di pagamento');
            }
        }

        // Esigibilità IVA del cliente (S per la PA): vuota vale le impostazioni.
        if (static::roleColumn() === 'is_customer') {
            $fields[] = FormField::key('vat_collectability')
                ->select(['' => 'Predefinita (impostazioni)'] + EsigibilitaIva::Valori)
                ->label('Esigibilità IVA');
        }

        if (static::priceListShown()) {
            $fields[] = FormField::key('price_list_id')
                ->select(['' => 'Nessuno (prezzi di catalogo)'] + PriceListResource::options(0, true))
                ->label('Listino');
        }

        if (static::agentShown()) {
            $fields[] = FormField::key('agent_id')
                ->select(['' => 'Nessuno'] + SalesAgents::options(false))
                ->label('Commerciale');
        }

        // #145: «Referenti» e «Aziende», solo con relations.enabled.
        return [...$fields, ...static::contactRelationFields()];
    }

    /** Il commerciale del cliente (#77): lo sceglie l'ufficio, mai il commerciale. */
    public static function agentShown(): bool
    {
        return static::roleColumn() === 'is_customer'
            && SalesAgents::enabled()
            && !SalesAgents::restricted(RowVisibility::currentUser());
    }

    /** @return list<Card> */
    protected static function agentCards(): array
    {
        if (!static::agentShown()) {
            return [];
        }

        return [
            (new Card)->components([
                SectionTitle::make('Commerciale')
                    ->tooltip(static::isCompanySide()
                        ? 'Il commerciale vede e gestisce l\'azienda, i suoi referenti e i suoi documenti. Gli ordini nuovi dell\'azienda vanno a lui.'
                        : 'Il commerciale vede e gestisce questo cliente e i suoi documenti. Gli ordini nuovi del cliente vanno a lui.')
                    ->columnSpan(12),
                static::getInput('agent_id')->columnSpan(6),
            ])->columns(12)->columnSpan(12),
        ];
    }

    /** Il listino del cliente: solo per i clienti, con «Listini cliente». */
    public static function priceListShown(): bool
    {
        return static::roleColumn() === 'is_customer' && Gestionale::feature('customer_price_lists');
    }

    /** Metodo e condizione predefiniti: solo per i clienti, con «Vendita in ufficio». */
    public static function paymentDefaultsShown(): bool
    {
        return static::roleColumn() === 'is_customer' && Gestionale::feature('office_sales');
    }

    /** I metodi attivi che valgono in ufficio, per id. @return array<int, string> */
    public static function officeMethods(): array
    {
        $metodi = [];

        foreach (static::rowsOf(PaymentMethod::class, ['active' => 'true', 'applies_office' => 'true'], 'position') as $metodo) {
            $metodi[(int) $metodo['id']] = (string) ($metodo['name'] ?? '');
        }

        return $metodi;
    }

    /** Le condizioni di pagamento attive, per id. @return array<int, string> */
    public static function activeTerms(): array
    {
        $condizioni = [];

        foreach (static::rowsOf(PaymentTerm::class, ['active' => 'true'], 'position') as $condizione) {
            $condizioni[(int) $condizione['id']] = (string) ($condizione['name'] ?? '');
        }

        return $condizioni;
    }

    /** @return list<Card> */
    protected static function paymentCards(): array
    {
        if (static::roleColumn() !== 'is_customer') {
            return [];
        }

        $esigibilita = static::getInput('vat_collectability')->columnSpan(6);

        if (!static::paymentDefaultsShown()) {
            return [
                (new Card)->components([
                    SectionTitle::make('Pagamento')
                        ->tooltip('Con la scissione dei pagamenti (S) il cliente paga solo l\'imponibile: l\'IVA la versa lui.')
                        ->columnSpan(12),
                    $esigibilita,
                ])->columns(12)->columnSpan(12),
            ];
        }

        $condizione = count(static::activeTerms()) > 1;

        return [
            (new Card)->components([
                SectionTitle::make('Pagamento')
                    ->tooltip('Quello che il nuovo ordine da ufficio propone per questo cliente: si può sempre cambiare sull\'ordine.'.($condizione ? ' La condizione dice in quante rate e quando paga.' : '').' Con la scissione dei pagamenti (S) il cliente paga solo l\'imponibile.')
                    ->columnSpan(12),
                static::getInput('payment_method_id')->columnSpan(6),
                ...($condizione ? [static::getInput('payment_term_id')->columnSpan(6)] : []),
                $esigibilita,
            ])->columns(12)->columnSpan(12),
        ];
    }

    /** @return list<Card> */
    protected static function priceListCards(): array
    {
        if (!static::priceListShown()) {
            return [];
        }

        return [
            (new Card)->components([
                SectionTitle::make('Listino')
                    ->tooltip('I prezzi riservati a questo cliente, nel negozio e sugli ordini da ufficio. I prodotti che il listino non ha restano al prezzo di catalogo.')
                    ->columnSpan(12),
                static::getInput('price_list_id')->columnSpan(6),
            ])->columns(12)->columnSpan(12),
        ];
    }

    public static function formLayoutSchema(): ?Form
    {
        $ruoli = static::rolesShown();
        $azienda = static::isCompanySide();

        $chiE = [
            SectionTitle::make('Dettagli')
                ->tooltip(match (true) {
                    $ruoli => 'Un\'azienda è una sola identità fiscale: quella a cui vendi e da cui compri è una riga sola, con il ruolo «Cliente e fornitore».',
                    $azienda => 'Un\'azienda è una sola identità fiscale: la stessa partita IVA non si scrive due volte.',
                    default => 'Una persona: le aziende per cui lavora si legano più sotto.',
                })
                ->columnSpan(12),
            ...($azienda
                ? [static::getInput('business_name')->columnSpan($ruoli ? 6 : 9)]
                : [static::getInput('name')->columnSpan(4), static::getInput('surname')->columnSpan(5)]),
        ];

        if ($ruoli) {
            $chiE[] = static::getInput('roles')->columnSpan(3);
        }

        $chiE[] = static::getInput('active')->columnSpan(3);

        $cards = [
            (new Card)->components($chiE)->columns(12)->columnSpan(12),
            (new Card)->components([
                SectionTitle::make('Contatti')->columnSpan(12),
                static::getInput('email')->columnSpan(6),
                static::getInput('phone_prefix')->columnSpan(2),
                static::getInput('phone')->columnSpan(4),
            ])->columns(12)->columnSpan(12),
            (new Card)->components([
                SectionTitle::make('Dati di fatturazione')
                    ->tooltip('Partita IVA e codice fiscale li controlla il framework: se sono sbagliati non si salvano.')
                    ->columnSpan(12),
                ...($azienda ? [
                    static::getInput('cf')->columnSpan(4),
                    static::getInput('pi')->columnSpan(4),
                    static::getInput('sdi')->columnSpan(2),
                    static::getInput('pec')->columnSpan(2),
                ] : [static::getInput('cf')->columnSpan(6)]),
                static::getInput('street')->columnSpan(9),
                static::getInput('number')->columnSpan(3),
                static::getInput('country')->columnSpan(3),
                static::getInput('province')->columnSpan(3),
                static::getInput('city')->columnSpan(3),
                static::getInput('cap')->columnSpan(3),
                static::getInput('more')->columnSpan(12),
            ])->columns(12)->columnSpan(12),
            ...static::agentCards(),
            ...static::paymentCards(),
            ...static::priceListCards(),
            (new Card)->components([
                SectionTitle::make('Indirizzi di consegna')
                    ->tooltip('Dove si consegna, quando non è l\'indirizzo di fatturazione. Il destinatario è chi il corriere deve cercare.')
                    ->columnSpan(12),
                static::getInput('addresses')->columnSpan(12),
            ])->columns(12)->columnSpan(12),
            ...static::relationCards(),
            (new Card)->components([
                SectionTitle::make('Note')->columnSpan(12),
                static::getInput('note')->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ];

        return (new Form)->components([
            (new Container)->components($cards)->columns(12)->columnSpan(12),
        ])->columns(12);
    }

    /**
     * La card «Referenti» (azienda) o «Aziende» (persona): i due repeater
     * seguono il tipo della scheda, il campo nascosto dice al salvataggio che
     * il form li ha mostrati.
     *
     * @return list<Card>
     */
    protected static function relationCards(): array
    {
        if (!ContactRelations::enabled()) {
            return [];
        }

        return [
            (new Card)->components([
                SectionTitle::make(__t('gestionale.contacts.relations.title'))
                    ->tooltip(__t('gestionale.contacts.relations.tooltip'))
                    ->columnSpan(12),
                // Il form dichiara un solo repeater, quello del lato della scheda
                static::getInput(static::relationSide() === ContactRelations::COMPANY ? self::RELATION_PEOPLE : self::RELATION_COMPANIES)->columnSpan(12),
                static::getInput(self::RELATION_MARKER)->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ];
    }

    /**
     * Vero per le persone che sono referenti vivi di almeno un'azienda viva.
     * Un'azienda non è mai «solo referente».
     */
    protected static function onlyReferentSql(): string
    {
        if (static::isCompanySide()) {
            return '0 = 1';
        }

        $table = static::$model::$table;
        $companies = Company::$table;
        $relations = ContactRelation::$table;

        return "(is_customer <> 'true' AND EXISTS(SELECT 1 FROM {$relations} cr
            JOIN {$companies} rc ON rc.id = cr.company_id AND rc.deleted = 'false'
            WHERE cr.person_id = {$table}.id AND cr.deleted = 'false'))";
    }

    /** I nomi dell'altro lato dei legami, per la colonna della lista. */
    protected static function relationNamesSql(): string
    {
        $table = static::$model::$table;
        $relations = ContactRelation::$table;

        [$other, $name, $mine, $theirs] = static::isCompanySide()
            ? [Contact::$table, "TRIM(CONCAT_WS(' ', rc.surname, rc.name))", 'company_id', 'person_id']
            : [Company::$table, 'TRIM(rc.business_name)', 'person_id', 'company_id'];

        return "(SELECT GROUP_CONCAT({$name} ORDER BY cr.position SEPARATOR ', ')
            FROM {$relations} cr
            JOIN {$other} rc ON rc.deleted = 'false' AND rc.id = cr.{$theirs}
            WHERE cr.deleted = 'false' AND cr.{$mine} = {$table}.id) AS relation_names";
    }

    /**
     * I nomi dell'altro lato dei legami di una scheda, per l'export.
     *
     * @param array<string, mixed> $row
     */
    public static function relationNames(array $row): string
    {
        $id = (int) ($row['id'] ?? 0);
        $rows = static::isCompanySide() ? ContactRelations::peopleOf($id) : ContactRelations::companiesOf($id);
        $names = [];

        foreach ($rows as $relation) {
            if (($relation['deleted'] ?? 'false') !== 'false' || ($relation['contact']['deleted'] ?? 'false') !== 'false') {
                continue;
            }

            $title = trim((string) ($relation['job_title'] ?? ''));
            $name = Contacts::displayName((array) $relation['contact']);
            $names[] = $title !== '' ? $name.' ('.$title.')' : $name;
        }

        return implode('; ', $names);
    }

    /** Una persona creata da una riga dei referenti non è cliente. */
    protected static function relationPersonPayload(array $values): array
    {
        return ['is_customer' => 'false'] + $values;
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('name')
                ->text()
                ->link(static::hasSheet() ? 'view' : 'edit')
                ->formatter(static fn (array $row): string => static::escape(
                    Contacts::displayName($row)
                )),
            TableColumn::key('email')->text()
                ->hiddenDevice('mobile'),
            TableColumn::key('city')->text()
                ->hiddenDevice('mobile'),
            ...(ContactRelations::enabled() ? [
                TableColumn::key('relation_names')->text()
                    ->label(__t('gestionale.contacts.relations.column'))
                    ->hiddenDevice('mobile'),
            ] : []),
            TableColumn::key('active')
                ->booleanBadge()
                ->badgeOn('Attiva', 'bi-check-circle', 'success')
                ->badgeOff('Non attiva', 'bi-pause-circle', 'secondary')
                ->size('little'),
            TableColumn::key('actions')->button()->actions([
                ...(static::hasSheet() ? ['view'] : []),
                'edit',
                // L'etichetta segue lo stato della riga; il core inverte la colonna.
                'active' => ['label' => ['true' => 'Disattiva', 'false' => 'Attiva'], 'request' => 'boolean'],
                'delete',
            ]),
        ];
    }

    public static function tableLayoutSchema(): TableLayoutSchema
    {
        $contactTable = static::$model::$table;
        $formats = ContactExports::granted();
        $select = array_filter([
            static::hasAccount() ? "(SELECT GROUP_CONCAT(DISTINCT af.provider ORDER BY af.provider SEPARATOR ',')
                FROM auth_federated af
                WHERE af.user_id = {$contactTable}.user_id AND af.deleted = 'false') AS auth_providers,
             EXISTS(SELECT 1 FROM `user` u
                WHERE u.id = {$contactTable}.user_id
                  AND u.deleted = 'false'
                  AND COALESCE(u.password, '') <> '') AS has_local_password" : '',
            ContactRelations::enabled() ? static::relationNamesSql() : '',
        ]);
        $layout = parent::tableLayoutSchema();

        if ($select !== []) {
            $layout = $layout->select(implode(",\n             ", $select));
        }

        $layout = $layout->download($formats)->downloadAsText();

        if (ContactRelations::enabled() && !static::isCompanySide()) {
            $only = static::onlyReferentSql();
            $layout->filterQuery(
                __t('gestionale.contacts.relations.filter'),
                'referents',
                ['only' => __t('gestionale.contacts.relations.filter_only'), 'hide' => __t('gestionale.contacts.relations.filter_hide')],
                static fn (array $values): string => match ($values[0] ?? '') {
                    'only' => $only,
                    'hide' => "NOT {$only}",
                    default => '1 = 1',
                }
            );
        }

        if (static::agentShown()) {
            $layout->filterQuery(
                'Commerciale',
                'agent',
                ['none' => 'Nessuno'] + SalesAgents::options(false),
                static fn (array $values): string => match (true) {
                    ($values[0] ?? '') === 'none' => '`agent_id` = 0',
                    ctype_digit((string) ($values[0] ?? '')) => '`agent_id` = '.(int) $values[0],
                    default => '1 = 1',
                }
            );
        }

        $extra = ContactRelations::enabled()
            ? [['label' => (string) __t('gestionale.contacts.relations.column'), 'value' => [static::class, 'relationNames']]]
            : [];

        // Senza formati concessi l'«Esporta» non c'è: niente colonne da preparare.
        return $formats === [] ? $layout : $layout
            ->downloadColumns(ContactExport::columns(static::labelSchema(), [Contacts::class, 'displayName'], $extra,
                static::isCompanySide() ? ContactExport::COMPANY_FIELDS : ContactExport::PERSON_FIELDS))
            ->downloadFileName(create_link(static::titleLabel()).'-'.date('Y-m-d'));
    }

    /**
     * La scheda in sola lettura, con ordini, carrello e statistiche: è del
     * cliente. Chi guarda i fornitori si apre direttamente sulla modifica.
     */
    public static function hasSheet(): bool
    {
        return true;
    }

    public static function pageSchema(): PageSchema
    {
        $pages = parent::pageSchema()->titles([
            'list' => static::titleLabel(),
            'create' => 'Aggiungi '.static::textSchema()['label'],
            'edit' => 'Modifica '.static::textSchema()['label'],
            'view' => 'Scheda '.static::textSchema()['label'],
        ]);

        if (!static::hasSheet()) {
            return $pages;
        }

        // Stesso schema dell'articolo: si guarda in lettura, e la modifica sta
        // dietro al bottone «Modifica» in testata.
        return $pages
            ->enable(['view'])
            ->view('show', Gestionale::viewPath(static::sheetView()))
            ->actions('view', static fn (array $item): array => [[
                'label' => 'Modifica',
                'icon' => 'bi-pencil',
                'class' => 'btn-warning btn-sm',
                'href' => static::editUrlFor((int) ($item['id'] ?? 0)),
            ]]);
    }

    /** La vista della scheda in lettura: quella del cliente; il fornitore ha la sua. */
    protected static function sheetView(): string
    {
        return 'pages/customer-show.php';
    }

    /** L'indirizzo della scheda in lettura; il ripiego è il percorso. */
    public static function viewUrl(int $id): string
    {
        return static::namedUrl('view', '/backend/'.static::path().'/'.$id.'/', $id);
    }

    /** L'indirizzo della modifica. */
    public static function editUrlFor(int $id): string
    {
        return static::namedUrl('edit', '/backend/'.static::path().'/'.$id.'/edit/', $id);
    }

    private static function namedUrl(string $action, string $fallback, int $id): string
    {
        if (!function_exists('__r')) {
            return $fallback;
        }

        try {
            $named = (string) __r('backend.resource.'.static::slug().'.'.$action, ['id' => $id]);
        } catch (Throwable) {
            return $fallback;
        }

        return $named !== '' ? $named : $fallback;
    }

    /** Il titolo della scheda: il nome del cliente. */
    public static function pageTitle(array $contact): string
    {
        return Contacts::displayName($contact);
    }

    /**
     * La scheda del cliente. A sinistra le statistiche, tutti i suoi dati,
     * gli ordini e il carrello; a destra la fatturazione e gli indirizzi di
     * consegna; in fondo i coupon. Ordini, carrello e statistiche seguono la
     * funzionalità «orders»: spenta, restano dati, indirizzi e coupon.
     */
    public static function showLayoutSchema(array $contact): Container
    {
        $id = (int) ($contact['id'] ?? 0);
        $userId = static::hasAccount() ? (int) ($contact['user_id'] ?? 0) : 0;
        $column = static::documentColumn();
        $vendite = Gestionale::feature('orders');

        $accordion = static fn (string $titolo, string $html, bool $aperto = false): Accordion => $aperto
            ? Accordion::make($titolo)->expanded()->components([RichText::make($html)->tag('div')->columnSpan(12)])->columnSpan(12)
            : Accordion::make($titolo)->components([RichText::make($html)->tag('div')->columnSpan(12)])->columnSpan(12);

        $sinistra = [];

        if ($vendite) {
            $mine = '`'.$column.'` = '.$id.($userId > 0 ? ' OR `user_id` = '.$userId : '');
            $ordini = static::rowsOf(Order::class, $mine);
            $carrelli = array_values(array_map(
                static fn (array $o): int => (int) $o['id'],
                array_filter($ordini, static fn (array $o): bool => (string) ($o['stage'] ?? '') === 'cart')
            ));
            $righe = $carrelli === [] ? [] : static::rowsOf(OrderItem::class, '`order_id` IN ('.implode(',', $carrelli).')');
            $stats = CustomerStats::of($ordini, $righe);

            $sinistra[] = (new Card)->components([
                SectionTitle::make('Statistiche')
                    ->tooltip('Gli ordini annullati o rimborsati per intero non si contano fra gli speso. Il carrello è quello ancora aperto.')
                    ->columnSpan(12),
                RichText::make(CustomerSheet::stats($stats))->tag('div')->columnSpan(12),
            ])->columns(12)->columnSpan(12);
        }

        $sinistra[] = (new Card)->components([
            SectionTitle::make('Tutti i suoi dati')->columnSpan(12),
            ...CustomerSheet::data($contact, static::hasAccount() ? static::authMethod(static::withAccount($contact)) : '—'),
            ...(static::paymentDefaultsShown()
                ? CustomerSheet::payment(
                    static::officeMethods()[(int) ($contact['payment_method_id'] ?? 0)] ?? '',
                    count(static::activeTerms()) > 1 ? (static::activeTerms()[(int) ($contact['payment_term_id'] ?? 0)] ?? '') : null
                )
                : []),
            ...(static::priceListShown()
                ? CustomerSheet::priceList(PriceListResource::options((int) ($contact['price_list_id'] ?? 0))[(int) ($contact['price_list_id'] ?? 0)] ?? '')
                : []),
        ])->columns(12)->columnSpan(12);

        if ($vendite) {
            $sinistra[] = $accordion('Ordini', static::isCompanySide()
                ? CustomerOrderTableResource::embedForCompany($id)
                : CustomerOrderTableResource::embedForCustomer($id, $userId), true);
            $sinistra[] = $accordion('Prodotti nel carrello', OrderItemTableResource::embedMany($carrelli, 'Nessun prodotto nel carrello.'));
        }

        // Gli abbonamenti, dal sito e dall'ufficio, con la funzionalità accesa (G10).
        if (Gestionale::feature('subscriptions')) {
            $sinistra[] = $accordion('Abbonamenti', CustomerSheet::subscriptionsTable(
                static::rowsOf(Subscription::class, [$column => $id, 'deleted' => 'false'])
            ), true);
        }

        $destra = [
            (new Card)->components([
                SectionTitle::make('Dati di fatturazione')->columnSpan(12),
                ...CustomerSheet::billing($contact),
            ])->columns(12)->columnSpan(12),
            static::deliveryCard($id),
        ];

        return (new Container)->components([
            (new Container)->columnSpan(['default' => 12, 'lg' => 8])->columns(12)->components($sinistra),
            (new Container)->columnSpan(['default' => 12, 'lg' => 4])->columns(12)->components($destra),
            $accordion('Coupon assegnati', CustomerSheet::couponsTable(CustomerSheet::coupons($id, $column))),
        ])->columns(12);
    }

    /**
     * Gli indirizzi di consegna: una card che ne contiene una per indirizzo,
     * ciascuna con i suoi pulsanti. Le finestre le stampa la pagina.
     */
    protected static function deliveryCard(int $contactId): Card
    {
        if (static::isCompanySide()) {
            return static::companyDeliveryCard($contactId);
        }

        $indirizzi = ContactAddressResource::addressesOf($contactId);
        $riquadri = [];

        foreach ($indirizzi as $indirizzo) {
            $indirizzoId = (int) $indirizzo['id'];
            $predefinito = (string) ($indirizzo['is_default'] ?? '') === 'true';
            $pulsanti = ContactAddressResource::openButton('Modifica', $indirizzo)->icon('bi bi-pencil')->variant('secondary')->outline()->size('sm')->render('bootstrap')
                .($predefinito ? '' : ' '.ContactAddressResource::defaultForm($contactId, $indirizzoId, 'Rendi predefinito')->variant('secondary')->outline()->size('sm')->render('bootstrap'))
                .' '.ContactAddressResource::deleteButton('', $indirizzo)->icon('bi bi-trash')->variant('danger')->outline()->size('sm')->render('bootstrap');

            $riquadri[] = (new Card)->components([
                RichText::make(CustomerSheet::delivery($indirizzo).'<div class="mt-2 d-flex flex-wrap gap-1">'.$pulsanti.'</div>')->tag('div')->columnSpan(12),
            ])->columns(12)->columnSpan(12);
        }

        return (new Card)->components([
            SectionTitle::make('Indirizzi di consegna')->columnSpan(12),
            ...($riquadri === []
                ? [RichText::make('<p class="text-muted">'.static::escape(CustomerSheet::noDelivery()).'</p>')->tag('div')->columnSpan(12)]
                : $riquadri),
            RichText::make(ContactAddressResource::openButton('Aggiungi indirizzo')->icon('bi bi-plus-lg')->variant('primary')->outline()->size('sm')->render('bootstrap'))->tag('div')->columnSpan(12),
        ])->columns(12)->columnSpan(12);
    }

    /** Gli indirizzi di un'azienda, in lettura: si cambiano dalla modifica. */
    protected static function companyDeliveryCard(int $companyId): Card
    {
        $riquadri = [];

        foreach (static::rowsOf(static::addressModel(), [static::addressColumn() => $companyId, 'deleted' => 'false'], 'position') as $indirizzo) {
            $riquadri[] = (new Card)->components([
                RichText::make(CustomerSheet::delivery($indirizzo))->tag('div')->columnSpan(12),
            ])->columns(12)->columnSpan(12);
        }

        return (new Card)->components([
            SectionTitle::make('Indirizzi di consegna')->columnSpan(12),
            ...($riquadri === []
                ? [RichText::make('<p class="text-muted">'.static::escape(CustomerSheet::noDelivery()).'</p>')->tag('div')->columnSpan(12)]
                : $riquadri),
        ])->columns(12)->columnSpan(12);
    }

    /**
     * La scheda con i metodi di accesso del suo account, letti qui perché
     * l'elenco li prende con una sottoquery e la scheda no.
     *
     * @return array<string, mixed>
     */
    protected static function withAccount(array $contact): array
    {
        $userId = (int) ($contact['user_id'] ?? 0);

        if ($userId <= 0) {
            return $contact;
        }

        try {
            $providers = (array) sqlSelect('auth_federated', ['user_id' => $userId, 'deleted' => 'false'])->row;
            $user = (array) sqlSelect('user', ['id' => $userId, 'deleted' => 'false'], 1)->row;
        } catch (Throwable) {
            return $contact;
        }

        $contact['auth_providers'] = implode(',', array_filter(array_map(
            static fn ($row): string => is_array($row) ? (string) ($row['provider'] ?? '') : '',
            $providers
        )));
        $contact['has_local_password'] = trim((string) ($user['password'] ?? '')) !== '';

        return $contact;
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->section('anagrafiche', 'Anagrafiche', 'bi-people', 320)
            ->title(static::titleLabel())
            ->order(10)
            ->access(Gestionale::area('contatti'))
            ->enabled(static::featureActive());
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->access(Gestionale::area('contatti'));
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
    }

    /** Ogni elenco mostra solo i suoi: la tabella è la stessa. */
    public static function querySchema(): array
    {
        $schema = parent::querySchema();
        $role = static::listCondition();

        // #145: con i referenti accesi ci sono anche le persone che sono solo
        // referenti di un'azienda, senza ruolo. Il filtro «Referenti» le separa.
        $schema['condition'] = ContactRelations::enabled() && !static::isCompanySide()
            ? "deleted = 'false' AND ({$role} OR ".static::onlyReferentSql().')'
            : "deleted = 'false' AND {$role}";

        return $schema;
    }

    /** Le righe dell'elenco, oltre a `deleted`. */
    protected static function listCondition(): string
    {
        return static::roleColumn()." = 'true'";
    }

    /**
     * Il ruolo salvato torna nel campo che lo chiede.
     *
     * "Ruolo" non è una colonna: sono due, e questa le rimette insieme nella
     * risposta che l'utente aveva dato. Una scheda vecchia senza nessun ruolo
     * acceso si presenta con quello dell'elenco da cui la stai aprendo.
     */
    public static function mutateFormValues(
        array $values,
        string $mode,
        string $context = 'backend'
    ): array {
        // Una scheda nuova parte con il prefisso di chi la sta compilando.
        if ($mode !== 'edit' && trim((string) ($values['phone_prefix'] ?? '')) === '') {
            $values['phone_prefix'] = VisitorCountry::phonePrefix();
        }

        if (!static::rolesShown()) {
            return $values;
        }

        $values['roles'] = $mode === 'edit'
            ? (Contacts::roleChoice($values) ?: static::defaultRoleChoice())
            : static::defaultRoleChoice();

        return $values;
    }

    /**
     * Le regole della rubrica, prima di salvare.
     *
     * Chi nasce in questo elenco nasce con il suo ruolo: aprire "Clienti",
     * premere "Aggiungi" e ritrovarsi una scheda senza ruolo sarebbe un modo
     * per perdere le righe.
     */
    public static function mutateRequestValues(
        array $values,
        string $action,
        string $context = 'backend',
        ?array $oldValues = null
    ): array {
        $id = (int) ($oldValues['id'] ?? 0);
        $choice = trim((string) ($values['roles'] ?? ''));

        // I ruoli non arrivano più come due caselle da spuntare: li decide il
        // campo "Ruolo", e dove quel campo non si stampa restano quelli che
        // sono. Senza questo `unset` il framework riscriverebbe le due colonne
        // con il loro valore di default, e la scheda cambierebbe ruolo da
        // sola al primo salvataggio.
        unset($values['roles'], $values['is_customer'], $values['is_supplier'], $values['type']);

        // Metodo e condizione si scrivono solo dove il form li mostra: un
        // salvataggio da un'altra pagina non li azzera.
        if (!static::paymentDefaultsShown()) {
            unset($values['payment_method_id'], $values['payment_term_id']);
        } else {
            if (array_key_exists('payment_method_id', $values)) {
                $values['payment_method_id'] = (int) $values['payment_method_id'];
            }

            if (count(static::activeTerms()) <= 1) {
                unset($values['payment_term_id']);
            } elseif (array_key_exists('payment_term_id', $values)) {
                $values['payment_term_id'] = (int) $values['payment_term_id'];
            }
        }

        if (array_key_exists('vat_collectability', $values)) {
            $raw = trim((string) $values['vat_collectability']);
            $values['vat_collectability'] = VatCollectability::valid($raw) ?? '';

            if ($raw !== '' && $values['vat_collectability'] === '') {
                throw UserError::make('contact.vat_collectability_invalid');
            }
        }

        // Il listino come metodo e condizione: solo dove il form lo mostra.
        if (!static::priceListShown()) {
            unset($values['price_list_id']);
        } elseif (array_key_exists('price_list_id', $values)) {
            $values['price_list_id'] = max(0, (int) $values['price_list_id']);
        }

        $values = static::agentValue($values, $action);

        if (static::rolesShown() && $choice !== '') {
            $values = [...$values, ...Contacts::rolesFromChoice($choice)];
        }

        // Solo se nessuno ha scelto altro: da "Clienti" si può creare la
        // scheda di un fornitore, e forzare il ruolo dell'elenco la
        // riporterebbe qui contro la volontà di chi l'ha scritta.
        if ($action === 'store' && !isset($values[static::roleColumn()])) {
            $values[static::roleColumn()] = 'true';
        }

        $values = static::checkRoles($values, $id);

        foreach (static::uniqueColumns() as $column => $key) {
            $value = trim((string) ($values[$column] ?? ''));
            $duplicate = Contacts::duplicateOf($column, $value, $id > 0 ? $id : null, static::$model);

            if ($duplicate !== []) {
                throw UserError::make($key, ['contact' => Contacts::displayName($duplicate)]);
            }
        }

        return $values;
    }

    /**
     * Il commerciale si scrive solo dove il form lo mostra; il cliente creato
     * da un commerciale nasce suo e lui non lo può spostare (#77).
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    protected static function agentValue(array $values, string $action): array
    {
        if (static::agentShown()) {
            if (array_key_exists('agent_id', $values)) {
                $values['agent_id'] = max(0, (int) $values['agent_id']);
            }

            return $values;
        }

        unset($values['agent_id']);
        $own = SalesAgents::current();
        if ($action === 'store' && $own > 0) {
            $values['agent_id'] = $own;
        }

        return $values;
    }

    /**
     * I ruoli dopo il salvataggio: una persona che non è cliente deve essere
     * referente di un'azienda (#145).
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    protected static function checkRoles(array $values, int $id): array
    {
        $customer = ($values['is_customer'] ?? static::storedRole($id, 'is_customer')) === 'true';

        if (!$customer && !static::isOnlyReferent($values, $id, $_POST)) {
            throw UserError::make('contact.person_no_role');
        }

        return $values;
    }

    /**
     * Una scheda con un account sul sito non si elimina.
     *
     * Cancellarla lascerebbe un utente che può entrare e non ha più una
     * scheda. Quando arriveranno ordini e documenti (G4) la stessa regola
     * varrà per loro: si disattiva, non si cancella.
     *
     * I fornitori in uso li controlla `CompanyResource`.
     */
    public static function assertDeletable(int|string $id): void
    {
        $row = static::$model::findById((int) $id);

        if (static::hasAccount() && is_array($row) && (int) ($row['user_id'] ?? 0) > 0) {
            throw UserError::refusal('contact.has_account');
        }
    }

    /** La scheda se ne va con i suoi indirizzi di consegna. */
    public static function deleteRecord(int|string $id): object
    {
        static::assertDeletable($id);

        // Gli indirizzi di consegna se ne vanno con la scheda, anche quelli
        // già tolti dalla griglia: da soli la terrebbero ferma. Insieme, così
        // una scheda che non si cancella non resta senza indirizzi.
        return Transaction::run(static function () use ($id): object {
            $addresses = static::addressModel();

            foreach (static::rowsOf($addresses, [static::addressColumn() => (int) $id, 'deleted' => ['true', 'false']]) as $row) {
                $addresses::delete((int) $row['id']);
            }

            return parent::deleteRecord($id);
        });
    }

    /** Il ruolo con cui nasce una scheda aperta da questo elenco. */
    protected static function defaultRoleChoice(): string
    {
        return static::roleColumn() === 'is_supplier' ? 'supplier' : 'customer';
    }

    /** Metodi con cui il cliente può autenticarsi, mostrati nella rubrica. */
    public static function authMethod(array $row): string
    {
        if ((int) ($row['user_id'] ?? 0) <= 0) {
            return 'Nessun account';
        }

        $providers = array_values(array_filter(array_map(
            static fn (string $provider): string => match (strtolower(trim($provider))) {
                'google' => 'Google',
                'apple' => 'Apple',
                default => ucfirst(strtolower(trim($provider))),
            },
            explode(',', (string) ($row['auth_providers'] ?? ''))
        )));
        $local = filter_var($row['has_local_password'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($local) {
            array_unshift($providers, 'Email e password');
        }

        return $providers === [] ? 'Account senza metodo di accesso' : implode(' + ', $providers);
    }

    /** Il ruolo già salvato, per quando il campo non è stato stampato. */
    /**
     * Vero per una persona legata ad almeno un'azienda viva. Il core toglie i
     * repeater dai valori prima di `mutateRequestValues()`: le aziende scritte
     * nel form si leggono dal POST, quelle salvate dal database.
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed> $post
     */
    protected static function isOnlyReferent(array $values, int $id, array $post): bool
    {
        if (!ContactRelations::enabled()) {
            return false;
        }

        if (static::isCompanySide()) {
            return false;
        }

        if (isset($post[self::RELATION_MARKER])) {
            $ids = array_filter(array_map(
                static fn (array $row): int => (int) ($row[ContactRelations::COMPANY] ?? 0),
                array_filter((array) ($post[self::RELATION_COMPANIES] ?? []), 'is_array')
            ));

            return ContactRelations::existingCounterparts(ContactRelations::PERSON, $ids) !== [];
        }

        foreach (ContactRelations::companiesOf($id) as $relation) {
            if (($relation['deleted'] ?? 'false') === 'false' && ($relation['contact']['deleted'] ?? 'false') === 'false') {
                return true;
            }
        }

        return false;
    }

    protected static function storedRole(int $id, string $column): string
    {
        if ($id <= 0) {
            return 'false';
        }

        $row = static::$model::findById($id);

        return is_array($row) ? (string) ($row[$column] ?? 'false') : 'false';
    }

    /** Gli indirizzi di consegna, dentro la scheda. */
    protected static function addressesField(): Input
    {
        return FormField::key('addresses')
            ->repeater([
                RepeaterColumn::key('id')->hidden(),
                RepeaterColumn::key('label')->text()->label('Etichetta')->columnSpan(3),
                RepeaterColumn::key('name')->text()->label('Nome')->columnSpan(3),
                RepeaterColumn::key('surname')->text()->label('Cognome')->columnSpan(3),
                RepeaterColumn::key('is_default')
                    ->select(['false' => 'No', 'true' => 'Sì'])
                    ->label('Predefinito')
                    ->columnSpan(3),
                // La riga la ricava places.js dal nome della via: il prefisso dichiarato vale per tutte.
                RepeaterColumn::key('street')->text()->label('Indirizzo')->attribute('data-wi-place="addresses[]["')->columnSpan(10),
                RepeaterColumn::key('number')->text()->label('Civico')->columnSpan(2),
                RepeaterColumn::key('country')->country('province')->value('IT')->label('Paese')->columnSpan(3),
                RepeaterColumn::key('province')->states('IT')->label('Provincia')->columnSpan(3),
                RepeaterColumn::key('city')->text()->label('Città')->columnSpan(4),
                RepeaterColumn::key('cap')->text()->label('CAP')->columnSpan(2),
            ])
            ->relation(
                RepeaterRelation::make(static::addressModel()::$table, static::addressColumn())
                    ->model(static::addressModel())
                    ->positionKey('position')
            )
            ->nested()
            ->repeaterSortable()
            ->repeaterAddLabel('Aggiungi indirizzo')
            ->repeaterDeleteTitle('Elimina indirizzo')
            ->repeaterDeleteText('Confermi l\'eliminazione di questo indirizzo?')
            ->repeaterDeleteCancelLabel('Annulla')
            ->repeaterDeleteConfirmLabel('Elimina')
            ->repeaterDeleteConfirmClass('btn btn-danger')
            ->label('');
    }
}
