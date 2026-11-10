<?php

namespace Wonder\Plugin\Gestionale\Resources\Contacts;

use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\Backend\Support\QuickCreateStore;
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
use Wonder\Plugin\CustomerAccount\Support\CompanyBrands;
use Wonder\Plugin\CustomerAccount\Support\ContactExport;
use Wonder\App\Support\GoogleMaps;
use Wonder\Plugin\CustomerAccount\Support\ContactPoints;
use Wonder\Plugin\CustomerAccount\Support\ContactRelations;
use Wonder\Plugin\CustomerAccount\Models\CompanyBrand;
use Wonder\Plugin\Gestionale\Support\Contacts\Brands;
use Wonder\Plugin\Gestionale\Support\Contacts\ContactExports;
use Wonder\Plugin\Gestionale\Support\Contacts\ContactVisibility;
use Wonder\Plugin\Gestionale\Support\Contacts\Contacts;
use Wonder\Plugin\Gestionale\Support\Contacts\CustomerMode;
use Wonder\Plugin\Gestionale\Support\Contacts\CustomerSheet;
use Wonder\Plugin\Gestionale\Support\Contacts\CustomerStats;
use Wonder\Plugin\Gestionale\Support\Contacts\QuickContacts;
use Wonder\Plugin\Gestionale\Support\Contacts\VisitorCountry;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Tax\VatCollectability;
use Wonder\Plugin\Custom\Fattura\Valori\EsigibilitaIva;
use Wonder\Sql\Transaction;
use Wonder\App\Visibility\RowVisibility;
use Wonder\Plugin\Gestionale\Support\SalesAgents\SalesAgents;

/**
 * Le persone della rubrica, e la loro scheda: clienti e referenti delle
 * aziende. L'elenco si chiama come il modo del gestionale (`CustomerMode`):
 * «Clienti» con le sole persone, «Persone» con persone e aziende (il ruolo si
 * sceglie col filtro), «Referenti» con le sole aziende. Cliente è un ruolo,
 * non un tipo di scheda: tabella, classe e percorso restano `clienti`.
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
class CustomerResource extends GestionaleResource implements QuickCreateStore
{
    use HasContactRelations {
        syncRepeaterRelations as relationsSyncRepeaterRelations;
        contactRelationFields as allContactRelationFields;
    }

    public static string $model = Contact::class;
    public static string $orderColumn = 'id';
    public static string $orderDirection = 'DESC';
    public static string $docsPage = 'anagrafiche/anagrafiche';

    /** Il campo che dice al salvataggio che il form ha mostrato i brand degli indirizzi. */
    public const ADDRESS_BRANDS_MARKER = 'address_brands_form';

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
        return match (CustomerMode::current()) {
            CustomerMode::PEOPLE => 'Clienti',
            CustomerMode::COMPANIES => 'Referenti',
            default => 'Persone',
        };
    }

    public static function textSchema(): array
    {
        return match (CustomerMode::current()) {
            CustomerMode::PEOPLE => ['label' => 'cliente', 'plural_label' => 'clienti', 'last' => 'ultimi', 'all' => 'tutti', 'article' => 'i', 'this' => 'questo'],
            CustomerMode::COMPANIES => ['label' => 'referente', 'plural_label' => 'referenti', 'last' => 'ultimi', 'all' => 'tutti', 'article' => 'i', 'this' => 'questo'],
            default => ['label' => 'persona', 'plural_label' => 'persone', 'last' => 'ultime', 'all' => 'tutte', 'article' => 'le', 'this' => 'questa'],
        };
    }

    /**
     * L'elenco esiste nel modo del gestionale: le persone sempre (clienti o
     * referenti), le aziende senza il modo «solo persone» (`CompanyResource`).
     */
    public static function shownInMode(): bool
    {
        return true;
    }

    /** Con il modo che la esclude la pagina sparisce come con la funzionalità spenta: menu, URL e API. */
    public static function featureActive(): bool
    {
        return parent::featureActive() && static::shownInMode();
    }

    /** I legami persona/azienda (referenti), solo dove ci sono le aziende. */
    public static function relationsShown(): bool
    {
        return ContactRelations::enabled() && CustomerMode::companies();
    }

    /**
     * Le parti del cliente (pagamento, listino, esigibilità, commerciale):
     * per le aziende clienti e, dove si vende ai privati, per le persone.
     * Con le sole aziende una persona è un referente.
     */
    public static function sellsTo(): bool
    {
        return static::roleColumn() === 'is_customer' && (static::isCompanySide() || CustomerMode::people());
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
        // Email e telefono stanno nel riquadro «Contatti», non nei campi della fatturazione.
        $billing = array_diff_key(static::$model::billing()->formSchema(), ['phone_prefix' => true, 'phone' => true]);

        // Un'attività cercata per nome dà la ragione sociale, se è vuota (lo script di customer-account).
        if (isset($billing['business_name'], $billing['street'])) {
            $billing['street']->attribute('data-ca-place-name="business_name"');
        }

        $fields = [
            ...array_values($billing),
            FormField::key('active')
                ->select(['true' => 'Attiva', 'false' => 'Non attiva'])
                ->value('true')
                ->label('Stato')
                ->required(),
            FormField::key('note')->textarea()->label('Note'),
            static::addressesField(),
            // Il riquadro «Contatti» di customer-account: tutte le email e i telefoni, con il principale.
            ...static::contactBoxFields(),
            ...(static::addressBrandsShown() ? [FormField::key(self::ADDRESS_BRANDS_MARKER)->hidden()->value('1')] : []),
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
        if (static::sellsTo()) {
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

        // #145: «Referenti» e «Aziende», solo dove ci sono le aziende.
        return [...$fields, ...(static::relationsShown() ? static::contactRelationFields() : [])];
    }

    /** Il commerciale del cliente (#77): lo sceglie l'ufficio, mai il commerciale. */
    public static function agentShown(): bool
    {
        return static::sellsTo()
            && SalesAgents::enabled()
            && !SalesAgents::restricted(RowVisibility::currentUser());
    }

    /** Il listino del cliente: solo per i clienti, con «Listini cliente». */
    public static function priceListShown(): bool
    {
        return static::sellsTo() && Gestionale::feature('customer_price_lists');
    }

    /** Metodo e condizione predefiniti: solo per i clienti, con «Vendita in ufficio». */
    public static function paymentDefaultsShown(): bool
    {
        return static::sellsTo() && Gestionale::feature('office_sales');
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

    /**
     * Il blocco «Pagamento»: metodo, condizione, esigibilità IVA, listino e
     * commerciale, ognuno se c'è, su una riga da schermi larghi (`lg`) in
     * su, a colonne uguali; sotto, uno per riga. In cinque i dodicesimi non
     * si dividono: i primi due (metodo e condizione) ne hanno uno in più.
     *
     * @return list<Card>
     */
    protected static function paymentCards(): array
    {
        if (!static::sellsTo()) {
            return [];
        }

        $offices = static::paymentDefaultsShown();
        $term = $offices && count(static::activeTerms()) > 1;
        $keys = array_keys(array_filter([
            'payment_method_id' => $offices,
            'payment_term_id' => $term,
            'vat_collectability' => true,
            'price_list_id' => static::priceListShown(),
            'agent_id' => static::agentShown(),
        ]));
        $tooltip = implode(' ', array_filter([
            $offices ? 'Quello che il nuovo ordine da ufficio propone per questo cliente: si può sempre cambiare sull\'ordine.' : '',
            $term ? 'La condizione dice in quante rate e quando paga.' : '',
            'Con la scissione dei pagamenti (S) il cliente paga solo l\'imponibile: l\'IVA la versa lui.',
            static::priceListShown() ? 'Il listino dà i prezzi riservati a questo cliente, nel negozio e sugli ordini da ufficio; i prodotti che non ha restano al prezzo di catalogo.' : '',
            static::agentShown() ? (static::isCompanySide()
                ? 'Il commerciale vede e gestisce l\'azienda, i suoi referenti e i suoi documenti; gli ordini nuovi dell\'azienda vanno a lui.'
                : 'Il commerciale vede e gestisce questo cliente e i suoi documenti; gli ordini nuovi del cliente vanno a lui.') : '',
        ]));

        $fields = [];

        foreach ($keys as $index => $key) {
            $width = intdiv(12, count($keys)) + ($index < 12 % count($keys) ? 1 : 0);
            $fields[] = static::getInput($key)->columnSpan(['default' => 12, 'lg' => $width]);
        }

        return [
            (new Card)->components([
                SectionTitle::make('Pagamento')->tooltip($tooltip)->columnSpan(12),
                ...$fields,
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
                    default => static::relationsShown() ? 'Una persona: le aziende per cui lavora si legano più sotto.' : 'Una persona.',
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
            ...static::brandCards(),
            (new Card)->components($chiE)->columns(12)->columnSpan(12),
            (new Card)->components([
                SectionTitle::make('Contatti')
                    ->tooltip(static::isCompanySide()
                        ? 'Tutte le email e i telefoni dell\'azienda. Il principale di ogni tipo è quello della scheda; «Si occupa di» dice a quali documenti va un\'email (vuoto = a tutti).'
                        : 'Tutte le email e i telefoni della persona. Il principale di ogni tipo è quello della scheda; con un account sul sito l\'email di accesso non si cambia da qui.')
                    ->columnSpan(12),
                // Il titolo è già quello della card: niente secondo «Contatti» sopra le righe.
                static::getInput(static::$pointsInput)->label('')->columnSpan(12),
                static::getInput(static::$boxMarker)->columnSpan(12),
                // Lo script del riquadro e della ricerca per nome negli indirizzi (customer-account).
                static::contactFormScript(),
            ])->columns(12)->columnSpan(12),
            // Lato azienda i referenti stanno coi contatti, anche in creazione.
            ...($azienda ? static::relationCards() : []),
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
                // Place ID e coordinate dalla ricerca di Google: nascosti, ma devono arrivare al POST.
                static::getInput('place_id'),
                static::getInput('lat'),
                static::getInput('lng'),
            ])->columns(12)->columnSpan(12),
            (new Card)->components([
                SectionTitle::make('Indirizzi di consegna')
                    ->tooltip('Dove si consegna, quando non è l\'indirizzo di fatturazione. Il destinatario è chi il corriere deve cercare.')
                    ->columnSpan(12),
                static::getInput('addresses')->columnSpan(12),
                ...(static::addressBrandsShown() ? [static::getInput(self::ADDRESS_BRANDS_MARKER)->columnSpan(12)] : []),
            ])->columns(12)->columnSpan(12),
        ];

        $extra = [
            ...static::paymentCards(),
            ...($azienda ? [] : static::relationCards()),
            (new Card)->components([
                SectionTitle::make('Note')->columnSpan(12),
                static::getInput('note')->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ];

        // In creazione quello che si può aggiungere dopo sta in un riquadro chiuso:
        // il form mostra subito chi è, i contatti (con i referenti), la fatturazione e la consegna.
        if (static::currentId() === null) {
            $extra = [
                Accordion::make('Altri dati (facoltativi)')
                    ->description($azienda ? 'Pagamento e note: si possono aggiungere anche dopo.' : 'Pagamento, aziende e note: si possono aggiungere anche dopo.')
                    ->components($extra)
                    ->columns(12)
                    ->columnSpan(12),
            ];
        }

        $cards = [...$cards, ...$extra];

        return (new Form)->components([
            (new Container)->components($cards)->columns(12)->columnSpan(12),
        ])->columns(12);
    }

    /** La card «Brand», prima di tutto: solo le aziende ne hanno. */
    protected static function brandCards(): array
    {
        return [];
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
        if (!static::relationsShown()) {
            return [];
        }

        $company = static::relationSide() === ContactRelations::COMPANY;

        return [
            (new Card)->components([
                SectionTitle::make(__t('gestionale.contacts.relations.'.($company ? 'title_people' : 'title_companies')))
                    ->tooltip(__t('gestionale.contacts.relations.'.($company ? 'tooltip_people' : 'tooltip_companies')))
                    ->columnSpan(12),
                // Il form dichiara un solo repeater, quello del lato della scheda
                static::getInput($company ? self::RELATION_PEOPLE : self::RELATION_COMPANIES)->columnSpan(12),
                static::getInput(self::RELATION_MARKER)->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ];
    }

    /**
     * I campi della finestra «Nuova persona»: l'email non è un campo del form
     * (sta nel riquadro «Contatti»), quindi la finestra li dichiara da sé.
     *
     * @return list<Input>
     */
    public static function quickPersonFields(): array
    {
        return [
            FormField::key('name')->text()->label('Nome')->columnSpan(6),
            FormField::key('surname')->text()->label('Cognome')->columnSpan(6),
            FormField::key('email')->email()->label('Email')->columnSpan(12),
        ];
    }

    /**
     * Il «+» nelle righe dei legami, lo stesso input dei brand: dalla scheda
     * di un'azienda si crea un referente, da quella di una persona
     * un'azienda. La crea `quickCreateStore()` del target.
     */
    protected static function relationQuickCreate(string $parentColumn): ?array
    {
        return $parentColumn === ContactRelations::COMPANY
            ? [CustomerResource::class, ['name', 'surname', 'email'], null, (string) __t('gestionale.contacts.relations.quick_person'),
                static fn (): Container => (new Container)->components(CustomerResource::quickPersonFields())->columns(12)]
            : [CompanyResource::class, ['business_name', 'pi'], null, (string) __t('gestionale.contacts.relations.quick_company')];
    }

    /**
     * La creazione rapida (QuickCreateStore): passa da `QuickContacts`, che
     * cerca prima il doppione per email o partita IVA e salva come l'utente
     * della sessione (la scheda di un commerciale nasce sua).
     */
    public static function quickCreateStore(array $values, string $labelField): array
    {
        $result = static::relationSide() === ContactRelations::COMPANY
            ? QuickContacts::company($values)
            : QuickContacts::person($values);

        if (!$result['ok']) {
            return ['error' => $result['message']];
        }

        return [
            'id' => $result['id'],
            'label' => $result['label'],
            'item' => ['id' => $result['id'], 'name' => $result['label']],
            'message' => $result['existing'] ? $result['message'] : '',
        ];
    }

    /**
     * Vero per le persone che sono referenti vivi di almeno un'azienda viva.
     * Un'azienda non è mai referente.
     */
    protected static function referentSql(): string
    {
        if (static::isCompanySide()) {
            return '0 = 1';
        }

        $table = static::$model::$table;
        $companies = Company::$table;
        $relations = ContactRelation::$table;

        return "EXISTS(SELECT 1 FROM {$relations} cr
            JOIN {$companies} rc ON rc.id = cr.company_id AND rc.deleted = 'false'
            WHERE cr.person_id = {$table}.id AND cr.deleted = 'false')";
    }

    /** Le persone che sono solo referenti: non clienti, legate a un'azienda viva. */
    protected static function onlyReferentSql(): string
    {
        return static::isCompanySide() ? '0 = 1' : "(is_customer <> 'true' AND ".static::referentSql().')';
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

    /**
     * Una persona creata da una riga dei referenti è anche cliente quando il
     * gestionale vende alle persone; in modo «solo aziende» resta referente.
     */
    protected static function relationPersonPayload(array $values): array
    {
        return ['is_customer' => CustomerMode::people() ? 'true' : 'false'] + $values;
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
            ...(static::relationsShown() ? [
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
            static::relationsShown() ? static::relationNamesSql() : '',
        ]);
        $layout = parent::tableLayoutSchema();

        if ($select !== []) {
            $layout = $layout->select(implode(",\n             ", $select));
        }

        $layout = $layout->download($formats)->downloadAsText();

        // Con persone e aziende l'elenco tiene clienti e referenti: il ruolo
        // si sceglie a vista, sopra la tabella. Con un modo solo il ruolo è uno.
        if (static::relationsShown() && !static::isCompanySide() && CustomerMode::current() === CustomerMode::BOTH) {
            $referents = static::referentSql();
            $layout->filterQuery(
                __t('gestionale.contacts.relations.filter'),
                'role',
                [
                    '' => __t('gestionale.contacts.relations.filter_all'),
                    'customers' => __t('gestionale.contacts.relations.filter_customers'),
                    'referents' => __t('gestionale.contacts.relations.filter_referents'),
                ],
                static fn (array $values): string => match ($values[0] ?? '') {
                    'customers' => "is_customer = 'true'",
                    'referents' => $referents,
                    default => '1 = 1',
                },
                'radio'
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

        $extra = static::relationsShown()
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

        // Con la funzionalità o il modo che la escludono la Resource non ha
        // pagine: la scheda in lettura non si riaccende da qui.
        if (!static::hasSheet() || !static::featureActive()) {
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
     * Vero se la scheda in lettura è di un cliente: un'azienda, o una
     * persona cliente dove si vende ai privati. Le altre persone sono
     * referenti delle aziende.
     */
    public static function isCustomerSheet(array $contact): bool
    {
        return static::isCompanySide()
            || (($contact['is_customer'] ?? 'false') === 'true' && CustomerMode::people());
    }

    /**
     * La scheda del cliente. A sinistra le statistiche, tutti i suoi dati,
     * gli ordini e il carrello; a destra la fatturazione e gli indirizzi di
     * consegna; in fondo i coupon. Ordini, carrello e statistiche seguono la
     * funzionalità «orders»: spenta, restano dati, indirizzi e coupon.
     *
     * Un referente non è cliente: la sua scheda ha i dati, le aziende di cui
     * è referente e gli indirizzi, senza le parti del cliente.
     */
    public static function showLayoutSchema(array $contact): Container
    {
        if (!static::isCustomerSheet($contact)) {
            return static::referentLayoutSchema($contact);
        }

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
            ...CustomerSheet::data($contact, static::hasAccount() ? static::authMethod(static::withAccount($contact)) : 'Nessun accesso'),
            ...static::pointItems($id, $contact),
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

        if (($legami = static::relationsCard($id)) !== null) {
            array_splice($destra, 1, 0, [$legami]);
        }

        return (new Container)->components([
            (new Container)->columnSpan(['default' => 12, 'lg' => 8])->columns(12)->components($sinistra),
            (new Container)->columnSpan(['default' => 12, 'lg' => 4])->columns(12)->components($destra),
            $accordion('Coupon assegnati', CustomerSheet::couponsTable(CustomerSheet::coupons($id, $column))),
        ])->columns(12);
    }

    /**
     * La scheda di una persona che è solo referente: tutti i suoi dati (con
     * contatti e accesso al sito), le aziende di cui è referente e gli
     * indirizzi di consegna. Niente ordini, carrello, statistiche, pagamento,
     * listino, abbonamenti e coupon: sono del cliente.
     */
    protected static function referentLayoutSchema(array $contact): Container
    {
        $id = (int) ($contact['id'] ?? 0);
        $destra = [static::deliveryCard($id)];

        if (($legami = static::relationsCard($id)) !== null) {
            array_unshift($destra, $legami);
        }

        return (new Container)->components([
            (new Container)->columnSpan(['default' => 12, 'lg' => 8])->columns(12)->components([
                (new Card)->components([
                    SectionTitle::make('Tutti i suoi dati')->columnSpan(12),
                    ...CustomerSheet::data($contact, static::authMethod(static::withAccount($contact)), 'Referente'),
                    ...static::pointItems($id, $contact),
                ])->columns(12)->columnSpan(12),
            ]),
            (new Container)->columnSpan(['default' => 12, 'lg' => 4])->columns(12)->components($destra),
        ])->columns(12);
    }

    /**
     * I contatti della scheda in lettura (`ContactPoints::all()`): principali,
     * email di accesso, altri, con le aree per le aziende. Senza id, o se i
     * contatti non si leggono, restano i principali della scheda.
     *
     * @param array<string, mixed> $contact la scheda
     * @return list<\Wonder\Elements\Components\DataItem>
     */
    public static function pointItems(int $id, array $contact = []): array
    {
        $primary = [
            ['kind' => ContactPoints::EMAIL, 'value' => (string) ($contact['email'] ?? ''), 'is_primary' => true],
            ['kind' => ContactPoints::PHONE, 'value' => (string) ($contact['phone'] ?? ''), 'phone_prefix' => (string) ($contact['phone_prefix'] ?? ''), 'is_primary' => true],
        ];

        try {
            $rows = $id > 0 ? ContactPoints::all(static::contactPointOwner(), $id, $contact) : $primary;
        } catch (Throwable) {
            $rows = $primary;
        }

        $labels = ContactPoints::labels() + [ContactPoints::ACCESS_LABEL => 'Email di accesso'];

        return CustomerSheet::points($rows, $labels, static::isCompanySide() ? ContactRelations::departments() : []);
    }

    /**
     * I legami della scheda, dove ci sono le aziende: per un'azienda le sue
     * persone, per una persona le sue aziende. Ognuno porta alla sua scheda.
     */
    protected static function relationsCard(int $id): ?Card
    {
        if ($id <= 0 || !static::relationsShown()) {
            return null;
        }

        $azienda = static::isCompanySide();
        $items = [];

        foreach ($azienda ? ContactRelations::peopleOf($id) : ContactRelations::companiesOf($id) as $row) {
            $altro = $row[$azienda ? 'contact' : 'company'] ?? null;

            if (($row['deleted'] ?? 'false') === 'true' || !is_array($altro) || ($altro['deleted'] ?? 'false') === 'true') {
                continue;
            }

            $altroId = (int) $altro['id'];
            // I contatti per quell'azienda: quelli di lavoro se ci sono, altrimenti (email) quelli della persona.
            $dettaglio = array_filter([
                trim((string) ($row['job_title'] ?? '')),
                ($row['is_primary'] ?? 'false') === 'true' ? 'Principale' : '',
                ($row['receives_documents'] ?? 'false') === 'true' ? 'Riceve i documenti' : '',
                $azienda ? trim((string) ($row['contact_email'] ?? ($altro['email'] ?? ''))) : trim((string) ($row['work_email'] ?? '')),
                trim((string) ($row['work_phone_prefix'] ?? '').' '.(string) ($row['work_phone'] ?? '')),
            ]);

            $items[] = [
                'name' => $azienda
                    ? Contacts::displayName($altro)
                    : (trim((string) ($altro['business_name'] ?? '')) ?: 'Azienda #'.$altroId),
                'url' => $azienda ? CustomerResource::viewUrl($altroId) : static::companyUrl($altro),
                'detail' => implode(' · ', $dettaglio),
            ];
        }

        return (new Card)->components([
            SectionTitle::make($azienda ? 'Referenti' : 'Aziende')
                ->tooltip($azienda ? 'Le persone legate all\'azienda: si aggiungono dalla modifica.' : 'Le aziende di cui la persona è referente: si aggiungono dalla modifica.')
                ->columnSpan(12),
            RichText::make(CustomerSheet::relations($items, $azienda ? 'Nessun referente.' : 'Non è referente di nessuna azienda.'))->tag('div')->columnSpan(12),
        ])->columns(12)->columnSpan(12);
    }

    /** La scheda di un'azienda: da cliente, da fornitore o nessuna. */
    private static function companyUrl(array $company): string
    {
        $id = (int) ($company['id'] ?? 0);

        if (($company['is_customer'] ?? 'false') === 'true') {
            return CompanyResource::viewUrl($id);
        }

        return ($company['is_supplier'] ?? 'false') === 'true' ? SupplierResource::viewUrl($id) : '';
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

        // #145: con le aziende ci sono anche le persone che sono solo
        // referenti di un'azienda, senza ruolo: il filtro «Ruolo» le separa.
        // Con le sole aziende l'elenco è dei referenti; un cliente privato di
        // prima resta salvato, nascosto.
        $schema['condition'] = match (true) {
            !static::relationsShown() || static::isCompanySide() => "deleted = 'false' AND {$role}",
            !CustomerMode::people() => "deleted = 'false' AND ".static::referentSql(),
            default => "deleted = 'false' AND ({$role} OR ".static::onlyReferentSql().')',
        };

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

        // Il riquadro «Contatti» rimette il principale in `email`, `phone_prefix`,
        // `phone` prima dei controlli (doppioni compresi); le altre righe le
        // scrive `syncRepeaterRelations()` dal POST. Come i marcatori, non
        // arrivano al salvataggio della scheda. Senza riquadro (creazione
        // rapida, finestre dell'ordine, API) i campi singoli passano come prima.
        $values = static::applyContactPoints($values, $oldValues);
        $values = static::keepAccessEmail($values, $oldValues);
        // I contatti di lavoro dei referenti: il repeater qui non arriva più (`stripRelationInputValues()`).
        static::assertRelationContacts($_POST);
        unset($values[static::$pointsInput], $values[static::$pointsMarker], $values[static::$boxMarker], $values[self::ADDRESS_BRANDS_MARKER]);

        // Place ID e coordinate della fatturazione arrivano dal browser: ripuliti, e solo se postati.
        if (array_intersect_key($values, array_flip(['place_id', 'lat', 'lng'])) !== []) {
            $values = GoogleMaps::cleanGeo($values) + $values;
        }

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

        // Solo se nessuno ha scelto altro: da "Aziende" si può creare la
        // scheda di un fornitore, e forzare il ruolo dell'elenco la
        // riporterebbe qui contro la volontà di chi l'ha scritta. Con le sole
        // aziende la persona nuova è un referente: `checkRoles()` vuole
        // l'azienda.
        if ($action === 'store' && !isset($values[static::roleColumn()])) {
            $values[static::roleColumn()] = static::isCompanySide() || CustomerMode::people() ? 'true' : 'false';
        }

        $values = static::checkRoles($values, $id);

        foreach (static::uniqueColumns() as $column => $key) {
            $value = trim((string) ($values[$column] ?? ''));
            $duplicate = Contacts::duplicateOf($column, $value, $id > 0 ? $id : null, static::$model);

            // Una scheda che chi scrive non vede non si nomina (né se ne dà il codice).
            if ($duplicate !== [] && ContactVisibility::visibleIds(static::$model::$table, [(int) $duplicate['id']]) === []) {
                throw UserError::make('contact.duplicate_hidden');
            }

            if ($duplicate !== []) {
                throw UserError::make($key, ['contact' => Contacts::displayName($duplicate)]);
            }
        }

        // La capogruppo è una colonna: chi vede solo alcune aziende non ne
        // sceglie una che non vede (quella già salvata resta).
        $parent = (int) ($values['parent_id'] ?? 0);

        if (static::isCompanySide() && $parent > 0 && $parent !== (int) ($oldValues['parent_id'] ?? 0)
            && ContactVisibility::visibleIds(static::$model::$table, [$parent]) === []) {
            throw new \InvalidArgumentException((string) __t('contacts.company_parent_missing'));
        }

        return $values;
    }

    /**
     * L'email di accesso di una persona con un account sul sito
     * (`ContactPoints::lockedEmail()`) non si cambia dal gestionale: il
     * riquadro «Contatti» la ignora già (`applyContactPoints()` toglie
     * `email`); un campo singolo (finestra dell'ordine, API) che ne porta
     * un'altra ferma il salvataggio, la stessa passa e non si riscrive. Le
     * aziende non hanno account.
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed>|null $oldValues la scheda salvata
     * @return array<string, mixed>
     */
    protected static function keepAccessEmail(array $values, ?array $oldValues): array
    {
        $locked = ContactPoints::lockedEmail(static::contactPointOwner(), $oldValues ?? []);

        if ($locked === null || !array_key_exists('email', $values)) {
            return $values;
        }

        $email = is_scalar($values['email']) ? strtolower(trim((string) $values['email'])) : '';

        if ($email !== $locked) {
            throw UserError::make('contact.access_email_locked');
        }

        unset($values['email']);

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

    /**
     * I brand per cui vale un indirizzo di consegna: solo per le aziende, dove
     * ci sono. Non è una colonna di `company_addresses`: la legge
     * `CompanyResource::hydrateRepeaterFormValues()` e la scrive
     * `CompanyResource::syncRepeaterRelations()`, dopo i brand dell'azienda.
     */
    public static function addressBrandsShown(): bool
    {
        return static::isCompanySide() && Brands::enabled();
    }

    /**
     * I legami (referenti, aziende) si scrivono solo dove ci sono le aziende:
     * con il modo «solo persone» un POST che li porta non li tocca. Gli
     * altri (indirizzi, altri contatti) come sempre.
     */
    public static function syncRepeaterRelations(
        int|string $parentId,
        array $post,
        array $files = [],
        string $action = 'store',
        string $context = 'backend'
    ): array {
        if (!static::relationsShown()) {
            unset($post[self::RELATION_MARKER]);
        }

        return static::relationsSyncRepeaterRelations($parentId, static::withoutHiddenBrands($post), $files, $action, $context);
    }

    /**
     * Senza «Brand» il POST perde i brand: i brand seguiti delle righe dei
     * legami (una riga senza `brand_ids` tiene i suoi). Con «Brand» com'è.
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    protected static function withoutHiddenBrands(array $post): array
    {
        $input = static::relationInputName();

        if (Brands::enabled() || !is_array($post[$input] ?? null)) {
            return $post;
        }

        foreach ($post[$input] as $key => $row) {
            if (is_array($row)) {
                unset($post[$input][$key]['brand_ids']);
            }
        }

        return $post;
    }

    /**
     * I repeater dei legami di customer-account; senza «Brand» la riga del
     * referente non ha la colonna dei brand seguiti.
     *
     * @return list<Input>
     */
    public static function contactRelationFields(): array
    {
        $fields = static::allContactRelationFields();

        if (Brands::enabled()) {
            return $fields;
        }

        foreach ($fields as $field) {
            $columns = $field->get('context')['columns'] ?? null;

            if (is_array($columns)) {
                $field->context('columns', array_values(array_filter($columns, static fn ($column): bool => !($column instanceof Input && $column->name === 'brand_ids'))));
            }
        }

        return $fields;
    }

    /** I brand seguiti da un referente: fra quelli che chi scrive vede (customer-account tiene solo quelli dell'azienda). */
    protected static function relationBrandsColumn(): Input
    {
        return RepeaterColumn::key('brand_ids')
            ->selectSearch(ContactVisibility::options(CompanyBrand::$table, CompanyBrands::options()), true)
            ->label(static::relationText('relation_brands'))
            ->columnSpan(12);
    }

    /** I brand non vanno al salvataggio della riga dell'indirizzo: non sono una colonna. */
    public static function prepareRepeaterRelationRow(
        string $inputName,
        array $payload,
        array $row,
        ?array $existingRow = null,
        string $action = 'store',
        string $context = 'backend'
    ): array {
        if ($inputName === 'addresses') {
            unset($payload['brand_ids']);

            // Place ID e coordinate arrivano dal browser: ripuliti, mai per decidere qualcosa.
            if (array_intersect_key($payload, array_flip(['place_id', 'lat', 'lng'])) !== []) {
                $payload = GoogleMaps::cleanGeo($payload) + $payload;
            }
        }

        return parent::prepareRepeaterRelationRow($inputName, $payload, $row, $existingRow, $action, $context);
    }

    /** Gli indirizzi di consegna, dentro la scheda. */
    protected static function addressesField(): Input
    {
        // Le scelte sono tutti i brand, come in customer-account: il form non
        // sa quale azienda mostra e `CompanyAddresses::setBrands()` tiene solo
        // quelli legati all'azienda.
        $brands = static::addressBrandsShown()
            ? [RepeaterColumn::key('brand_ids')->selectSearch(ContactVisibility::options(CompanyBrand::$table, CompanyBrands::options()), true)->label('Brand (vuoto = tutti)')->columnSpan(12)]
            : [];

        return FormField::key('addresses')
            ->repeater([
                RepeaterColumn::key('id')->hidden(),
                RepeaterColumn::key('label')->text()->label('Etichetta')->columnSpan(3),
                // «Presso»: l'attività o la società a quell'indirizzo (customer-account).
                RepeaterColumn::key('business_name')->text()->label('Presso')->columnSpan(3),
                RepeaterColumn::key('name')->text()->label('Nome')->columnSpan(3),
                RepeaterColumn::key('surname')->text()->label('Cognome')->columnSpan(3),
                // La riga la ricava places.js dal nome della via: il prefisso dichiarato vale per tutte.
                // Si cerca anche per nome dell'attività, che riempie «Presso» se è vuoto.
                RepeaterColumn::key('street')->text()->label('Indirizzo o attività')
                    ->attribute('data-wi-place="addresses[][" data-wi-place-names data-ca-place-name="business_name"')->columnSpan(8),
                RepeaterColumn::key('number')->text()->label('Civico')->columnSpan(2),
                RepeaterColumn::key('is_default')
                    ->select(['false' => 'No', 'true' => 'Sì'])
                    ->label('Predefinito')
                    ->columnSpan(2),
                RepeaterColumn::key('country')->country('province')->value('IT')->label('Paese')->columnSpan(3),
                RepeaterColumn::key('province')->states('IT')->label('Provincia')->columnSpan(3),
                RepeaterColumn::key('city')->text()->label('Città')->columnSpan(4),
                RepeaterColumn::key('cap')->text()->label('CAP')->columnSpan(2),
                // Place ID e coordinate li scrive Google: nascosti, ripuliti in `prepareRepeaterRelationRow()`.
                RepeaterColumn::key('place_id')->hidden(),
                RepeaterColumn::key('lat')->hidden(),
                RepeaterColumn::key('lng')->hidden(),
                ...$brands,
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
