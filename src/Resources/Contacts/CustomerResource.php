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
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Contacts\ContactAddress;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Contacts\Contacts;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Purchasing\ProductSuppliers;
use Wonder\Sql\Transaction;

/**
 * "Clienti": l'elenco di chi compra, e la scheda della rubrica.
 *
 * La scheda è **una sola** per clienti e fornitori: chi è tutti e due si
 * modifica in un posto, e la sua partita IVA resta una. Quello che cambia fra
 * i due elenchi — titolo, indirizzo, filtro, funzionalità — sta in
 * `SupplierResource`, che estende questa.
 *
 * Non è `final`: la estende `SupplierResource`, e i test con una classe
 * anonima.
 */
class CustomerResource extends GestionaleResource
{
    public static string $model = Contact::class;
    public static string $orderColumn = 'id';
    public static string $orderDirection = 'DESC';
    public static string $docsPage = 'anagrafiche/anagrafiche';

    /** La colonna del ruolo che questo elenco mostra. */
    public static function roleColumn(): string
    {
        return 'is_customer';
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
            'roles' => 'Ruolo',
            'is_customer' => 'Ruolo',
            'is_supplier' => 'Fornitore',
            'note' => 'Note',
            'active' => 'Stato',
            'addresses' => 'Indirizzi di consegna',
            'label' => 'Etichetta',
            ...Contact::billing()->labels(),
        ];
    }

    public static function formSchema(): array
    {
        $billing = Contact::billing()->formSchema();

        // I campi aziendali si vedono solo per le aziende: un privato non deve
        // incontrare la parola "SDI" (D20).
        foreach (['business_name', 'pi', 'sdi', 'pec'] as $key) {
            if (isset($billing[$key])) {
                $billing[$key]->visibleWhen('type', 'business');
            }
        }

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

        // Il ruolo si sceglie solo dove c'è una scelta: senza gli acquisti
        // sbloccati i fornitori non esistono, e ogni scheda è un cliente
        // (D20). Chiederlo lo stesso sarebbe una domanda con una risposta
        // sola, e quella sbagliata rifiutata.
        if (Gestionale::feature('purchasing')) {
            $fields[] = FormField::key('roles')
                ->select(Contacts::ROLE_CHOICES)
                ->value(static::defaultRoleChoice())
                ->label('Ruolo')
                ->required();
        }

        return $fields;
    }

    public static function formLayoutSchema(): ?Form
    {
        $acquisti = Gestionale::feature('purchasing');

        $chiE = [
            SectionTitle::make('Chi è')
                ->tooltip($acquisti
                    ? 'Una scheda è una sola identità fiscale: la stessa azienda a cui vendi e da cui compri è una riga sola, con il ruolo «Cliente e fornitore». Cambiando ruolo la scheda passa nell\'altro elenco.'
                    : 'Una scheda è una sola identità fiscale: la stessa persona non si scrive due volte.')
                ->columnSpan(12),
            static::getInput('type')->columnSpan(4),
            static::getInput('name')->columnSpan(4),
            static::getInput('surname')->columnSpan(4),
            static::getInput('business_name')->columnSpan($acquisti ? 6 : 8),
        ];

        if ($acquisti) {
            $chiE[] = static::getInput('roles')->columnSpan(4);
        }

        $chiE[] = static::getInput('active')->columnSpan($acquisti ? 2 : 4);

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
                static::getInput('cf')->columnSpan(4),
                static::getInput('pi')->columnSpan(4),
                static::getInput('sdi')->columnSpan(2),
                static::getInput('pec')->columnSpan(2),
                static::getInput('country')->columnSpan(3),
                static::getInput('province')->columnSpan(3),
                static::getInput('city')->columnSpan(3),
                static::getInput('cap')->columnSpan(3),
                static::getInput('street')->columnSpan(6),
                static::getInput('number')->columnSpan(2),
                static::getInput('more')->columnSpan(4),
            ])->columns(12)->columnSpan(12),
            (new Card)->components([
                SectionTitle::make('Indirizzi di consegna')
                    ->tooltip('Dove si consegna, quando non è l\'indirizzo di fatturazione. Il destinatario è chi il corriere deve cercare.')
                    ->columnSpan(12),
                static::getInput('addresses')->columnSpan(12),
            ])->columns(12)->columnSpan(12),
            (new Card)->components([
                SectionTitle::make('Note')->columnSpan(12),
                static::getInput('note')->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ];

        return (new Form)->components([
            (new Container)->components($cards)->columns(12)->columnSpan(12),
        ])->columns(12);
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('name')
                ->text()
                ->link('edit')
                ->formatter(static fn (array $row): string => static::escape(
                    Contacts::displayName($row)
                )),
            TableColumn::key('email')->text(),
            TableColumn::key('city')->text()->size('little'),
            TableColumn::key('is_customer')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(Contacts::roles($row))),
            TableColumn::key('active')
                ->booleanBadge()
                ->badgeOn('Attiva', 'bi-check-circle', 'success')
                ->badgeOff('Non attiva', 'bi-pause-circle', 'secondary')
                ->size('little'),
            TableColumn::key('actions')->button()->actions(['edit', 'delete']),
        ];
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()->titles([
            'list' => static::titleLabel(),
            'create' => 'Aggiungi '.static::textSchema()['label'],
            'edit' => 'Modifica '.static::textSchema()['label'],
        ]);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->section('anagrafiche', 'Anagrafiche', 'bi-people', 500, ['admin', 'administrator'])
            ->title(static::titleLabel())
            ->order(10)
            ->authority(['admin', 'administrator'])
            ->enabled(static::featureActive());
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->backendCrud(['admin', 'administrator']);
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
    }

    /** Ogni elenco mostra solo i suoi: la tabella è la stessa. */
    public static function querySchema(): array
    {
        $schema = parent::querySchema();
        $schema['condition'] = "deleted = 'false' AND ".static::roleColumn()." = 'true'";

        return $schema;
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
        if (!Gestionale::feature('purchasing')) {
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
        unset($values['roles'], $values['is_customer'], $values['is_supplier']);

        if (Gestionale::feature('purchasing') && $choice !== '') {
            $values = [...$values, ...Contacts::rolesFromChoice($choice)];
        }

        // Solo se nessuno ha scelto altro: da "Clienti" si può creare la
        // scheda di un fornitore, e forzare il ruolo dell'elenco la
        // riporterebbe qui contro la volontà di chi l'ha scritta.
        if ($action === 'store' && !isset($values[static::roleColumn()])) {
            $values[static::roleColumn()] = 'true';
        }

        $customer = ($values['is_customer'] ?? static::storedRole($id, 'is_customer')) === 'true';
        $supplier = ($values['is_supplier'] ?? static::storedRole($id, 'is_supplier')) === 'true';

        if (!$customer && !$supplier) {
            throw UserError::make('contact.no_role');
        }

        // Un fornitore che smette di esserlo sparirebbe dalla tendina degli
        // articoli e delle opzioni che comprano da lui, e al primo salvataggio
        // li perderebbe.
        if ($id > 0 && !$supplier && static::storedRole($id, 'is_supplier') === 'true') {
            $links = ProductSuppliers::countForSupplier($id);

            if ($links > 0) {
                throw UserError::make('contact.supplier_role_in_use', ['count' => $links]);
            }
        }

        foreach ([
            'pi' => 'contact.vat_taken',
            'cf' => 'contact.tax_code_taken',
            'email' => 'contact.email_taken',
        ] as $column => $key) {
            $value = trim((string) ($values[$column] ?? ''));
            $duplicate = Contacts::duplicateOf($column, $value, $id > 0 ? $id : null);

            if ($duplicate !== []) {
                throw UserError::make($key, ['contact' => Contacts::displayName($duplicate)]);
            }
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
     * Lo stesso per un fornitore con dei costi d'acquisto su articoli o
     * opzioni in vendita, anche ad acquisti spenti: il costo resta salvato e
     * tornerà.
     */
    public static function assertDeletable(int|string $id): void
    {
        $row = Contact::findById((int) $id);

        if (is_array($row) && (int) ($row['user_id'] ?? 0) > 0) {
            throw UserError::refusal('contact.has_account');
        }

        $links = ProductSuppliers::countForSupplier((int) $id);

        if ($links > 0) {
            throw UserError::refusal('contact.supplier_in_use', ['count' => $links]);
        }
    }

    /**
     * La scheda se ne va con i suoi indirizzi di consegna.
     *
     * Prima di cancellare la scheda se ne vanno i suoi costi su articoli e
     * opzioni già eliminati: nessuno li vede più, e la chiave esterna li
     * terrebbe fermi.
     */
    public static function deleteRecord(int|string $id): object
    {
        static::assertDeletable($id);
        ProductSuppliers::dropForRemovedModels((int) $id);
        ProductSuppliers::dropForRemovedProducts((int) $id);

        // Gli indirizzi di consegna se ne vanno con la scheda, anche quelli
        // già tolti dalla griglia: da soli la terrebbero ferma. Insieme, così
        // una scheda che non si cancella non resta senza indirizzi.
        return Transaction::run(static function () use ($id): object {
            foreach (static::rowsOf(ContactAddress::class, ['contact_id' => (int) $id, 'deleted' => ['true', 'false']]) as $row) {
                ContactAddress::delete((int) $row['id']);
            }

            return parent::deleteRecord($id);
        });
    }

    /** Il ruolo con cui nasce una scheda aperta da questo elenco. */
    protected static function defaultRoleChoice(): string
    {
        return static::roleColumn() === 'is_supplier' ? 'supplier' : 'customer';
    }

    /** Il ruolo già salvato, per quando il campo non è stato stampato. */
    protected static function storedRole(int $id, string $column): string
    {
        if ($id <= 0) {
            return 'false';
        }

        $row = Contact::findById($id);

        return is_array($row) ? (string) ($row[$column] ?? 'false') : 'false';
    }

    /** Gli indirizzi di consegna, dentro la scheda. */
    protected static function addressesField(): Input
    {
        return FormField::key('addresses')
            ->repeater([
                RepeaterColumn::key('id')->hidden(),
                RepeaterColumn::key('label')->text()->label('Etichetta')->columnSpan(2),
                RepeaterColumn::key('name')->text()->label('Nome')->columnSpan(2),
                RepeaterColumn::key('surname')->text()->label('Cognome')->columnSpan(2),
                RepeaterColumn::key('street')->text()->label('Via')->columnSpan(2),
                RepeaterColumn::key('number')->text()->label('N.')->columnSpan(1),
                RepeaterColumn::key('cap')->text()->label('CAP')->columnSpan(1),
                RepeaterColumn::key('city')->text()->label('Città')->columnSpan(1),
                RepeaterColumn::key('is_default')
                    ->select(['false' => 'No', 'true' => 'Sì'])
                    ->label('Predefinito')
                    ->columnSpan(1),
            ])
            ->relation(
                RepeaterRelation::make(ContactAddress::$table, 'contact_id')
                    ->model(ContactAddress::class)
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
            ->label('Indirizzi di consegna');
    }
}
