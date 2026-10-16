<?php

namespace Wonder\Plugin\Gestionale\Resources\Catalog;

use RuntimeException;
use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\RepeaterColumn;
use Wonder\App\ResourceSchema\RepeaterRelation;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\App\Support\Repeater;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\Plugin\Gestionale\Models\Catalog\Customization;
use Wonder\Plugin\Gestionale\Models\Catalog\CustomizationOption;
use Wonder\Plugin\Gestionale\Models\Catalog\CustomizationLink;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Customizations;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Numbers;
use Wonder\Plugin\Gestionale\Support\Positions;
use Wonder\Sql\Transaction;

/**
 * "Personalizzazioni": l'incisione, il colore del filo, il messaggio sul
 * biglietto — quello che il cliente aggiunge a un articolo e che non è una
 * delle sue opzioni in vendita.
 *
 * Si scrivono una volta qui e si collegano agli articoli e ai piani
 * d'abbonamento dalla loro scheda: «Per i prodotti» e «Per gli abbonamenti»
 * dicono dove si possono collegare.
 * Un **testo** lo scrive il cliente (con un massimo di caratteri), un
 * **numero** con i decimali stabiliti qui, una **scelta** la fa fra le
 * opzioni preparate qui. Il sovrapprezzo della
 * personalizzazione e quello dell'opzione scelta si sommano.
 *
 * Una personalizzazione già su qualche articolo o piano non si elimina: si
 * disattiva, e smette di essere offerta.
 */
class CustomizationResource extends GestionaleResource
{
    public static string $feature = 'customizations';
    public static string $model = Customization::class;
    public static string $orderColumn = 'position';
    public static string $orderDirection = 'ASC';
    public static string $docsPage = 'catalogo/catalogo-personalizzazioni';

    /** Gli interruttori di «Dove si usa», con la funzionalità che li mostra. */
    public const SCOPES = ['for_products' => 'orders', 'for_subscriptions' => 'subscriptions'];

    /** Il valore di un interruttore in una personalizzazione nuova. */
    public const SCOPE_DEFAULTS = ['for_products' => 'true', 'for_subscriptions' => 'false'];

    /** Quanti caratteri può scrivere il cliente in una personalizzazione nata dal modal. */
    public const QUICK_MAX_LENGTH = 100;

    public static function path(): string
    {
        return 'app/gestionale/personalizzazioni';
    }

    public static function icon(): string
    {
        return 'bi-pencil-square';
    }

    public static function titleLabel(): string
    {
        return 'Personalizzazioni';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'personalizzazione',
            'plural_label' => 'personalizzazioni',
            'last' => 'ultime',
            'all' => 'tutte',
            'article' => 'le',
            'full' => 'usata',
            'empty' => 'non usata',
            'this' => 'questa',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'name' => 'Nome',
            'label' => 'Etichetta per il cliente',
            'help_text' => 'Testo d\'aiuto',
            'kind' => 'Tipo',
            'max_length' => 'Caratteri massimi',
            'decimals' => 'Decimali',
            'surcharge' => 'Sovrapprezzo',
            'active' => 'Stato',
        ];
    }

    /** @return array<string, string> */
    public static function kinds(): array
    {
        return ['text' => 'Testo', 'number' => 'Numero', 'choice' => 'Scelta'];
    }

    public static function formSchema(): array
    {
        return [
            FormField::key('name')->text()->label('Nome interno')->required(),
            FormField::key('label')->text()->label('Etichetta per il cliente'),
            FormField::key('help_text')->textarea()->label('Testo d\'aiuto'),
            FormField::key('kind')
                ->select(static::kinds())
                ->value('text')
                ->label('Tipo')
                ->required(),
            FormField::key('max_length')
                ->number()
                ->decimal(0)
                ->value('100')
                ->label('Caratteri massimi')
                ->visibleWhen('kind', 'text'),
            FormField::key('decimals')
                ->select(['0' => '0', '1' => '1', '2' => '2', '3' => '3', '4' => '4', '5' => '5', '6' => '6'])
                ->value('0')
                ->label('Decimali')
                ->visibleWhen('kind', 'number'),
            FormField::key('surcharge')
                ->price()
                ->decimal(2)
                ->value('0.00')
                ->label('Sovrapprezzo')
                // Su una scelta il prezzo lo fanno le opzioni.
                ->hiddenWhen('kind', 'choice'),
            FormField::key('active')
                ->select(['true' => 'Attiva', 'false' => 'Disattivata'])
                ->value('true')
                ->label('Stato')
                ->required(),
            FormField::key('for_products')
                ->toggle()
                ->value('true')
                ->label('Per i prodotti'),
            FormField::key('for_subscriptions')
                ->toggle()
                ->value('false')
                ->label('Per gli abbonamenti'),
            FormField::key('options')
                ->repeater([
                    RepeaterColumn::key('id')->hidden(),
                    RepeaterColumn::key('label')->text()->label('Etichetta')->columnFill(),
                    RepeaterColumn::key('surcharge')->price()->decimal(2)->label('Sovrapprezzo')->columnSpan(3),
                ])
                ->relation(
                    RepeaterRelation::make(CustomizationOption::$table, 'customization_id')
                        ->model(CustomizationOption::class)
                        ->positionKey('position')
                        ->softDelete(false)
                )
                ->nested()
                ->repeaterSortable()
                ->repeaterAddLabel('Aggiungi opzione')
                ->repeaterDeleteTitle('Elimina opzione')
                ->repeaterDeleteText('Confermi l\'eliminazione di questa opzione?')
                ->repeaterDeleteCancelLabel('Annulla')
                ->repeaterDeleteConfirmLabel('Elimina')
                ->repeaterDeleteConfirmClass('btn btn-danger')
                // Il titolo del riquadro dice già «Opzioni».
                ->label(''),
        ];
    }

    /**
     * A sinistra quello che il cliente legge — nome, etichetta, testo d'aiuto
     * — e sotto, quando il tipo è «Scelta», le opzioni. A destra, stretta, i
     * «Dettagli»: tipo, caratteri o decimali, sovrapprezzo.
     */
    public static function formLayoutSchema(): ?Form
    {
        $main = (new Card)->components([
            SectionTitle::make('Personalizzazione')
                ->tooltip('Il nome interno lo vedi solo tu; l\'etichetta è quella che legge il cliente (se la lasci vuota vale il nome). Una personalizzazione disattivata esce dai carrelli: chi l\'aveva già nel carrello deve toglierla per procedere.')
                ->columnSpan(12),
            static::getInput('name')->columnSpan(6),
            static::getInput('active')->columnSpan(6),
            static::getInput('label')->columnSpan(12),
            static::getInput('help_text')->columnSpan(12),
        ])->columns(12)->columnSpan(12);

        // Il riquadro segue il tipo mentre lo si sceglie, senza salvare.
        $options = (new Card)->components([
            SectionTitle::make('Opzioni')
                ->tooltip('Almeno due. L\'ordine è quello che vedrà il cliente. Il sovrapprezzo di un\'opzione si somma a quello della personalizzazione. Eliminare un\'opzione non cambia gli ordini già fatti: le righe d\'ordine ricordano cosa era stato scelto.')
                ->columnSpan(12),
            static::getInput('options')->columnSpan(12),
        ])->columns(12)->columnSpan(12)->visibleWhen('kind', 'choice');

        $details = (new Card)->components([
            SectionTitle::make('Dettagli')
                ->tooltip('Un testo lo scrive il cliente, con un massimo di caratteri; un numero lo scrive con i decimali che dici qui; una scelta la fa fra le opzioni preparate sotto. Il sovrapprezzo di un testo o di un numero si aggiunge al prezzo dell\'articolo; una scelta non ne ha, lo hanno le sue opzioni.')
                ->columnSpan(12),
            static::getInput('kind')->columnSpan(12),
            static::getInput('max_length')->columnSpan(12),
            static::getInput('decimals')->columnSpan(12),
            static::getInput('surcharge')->columnSpan(12),
        ])->columns(12)->columnSpan(12);

        // Un interruttore si vede solo con la sua funzionalità accesa; quello
        // nascosto tiene il valore salvato (vedi `mutateRequestValues()`).
        $scopes = [];

        foreach (static::SCOPES as $key => $feature) {
            if (Gestionale::feature($feature)) {
                $scopes[] = static::getInput($key)->columnSpan(12);
            }
        }

        $right = [$details];

        if ($scopes !== []) {
            $right[] = (new Card)->components([
                SectionTitle::make('Dove si usa')
                    ->tooltip('Dove la puoi collegare: alla scheda degli articoli, a quella dei piani d\'abbonamento o a tutte e due. Per spegnere un interruttore togli prima i collegamenti che ha già.')
                    ->columnSpan(12),
                ...$scopes,
            ])->columns(12)->columnSpan(12);
        }

        // `columns(12)` anche sul Form: la larghezza di un figlio si calcola
        // sulle colonne del padre.
        return (new Form)->components([
            (new Container)->components([$main, $options])->columns(12)->columnSpan(8),
            (new Container)->components($right)->columns(12)->columnSpan(4),
        ])->columns(12);
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('name')->text()->link('edit'),
            TableColumn::key('kind')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(
                    static::kinds()[(string) ($row['kind'] ?? '')] ?? ''
                )),
            TableColumn::key('surcharge')->price()->size('little'),
            // L'icona del core: cartella piena se un articolo la usa, e in quel
            // caso niente «Elimina» (assertDeletable() resta la guardia vera).
            TableColumn::key('usage')
                ->schema('function', [
                    'name' => 'empty',
                    'tables' => [CustomizationLink::$table],
                    'column' => 'customization_id',
                ]),
            TableColumn::key('active')
                ->booleanBadge()
                ->badgeOn('Attiva', 'bi-check-circle', 'success')
                ->badgeOff('Disattivata', 'bi-dash-circle', 'secondary')
                ->size('little'),
            TableColumn::key('actions')->button()->actions(['edit', 'delete']),
        ];
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()
            ->disable(['view'])
            ->titles([
                'list' => 'Personalizzazioni',
                'create' => 'Nuova personalizzazione',
                'edit' => 'Modifica personalizzazione',
            ]);
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->backendCrud(['admin', 'administrator']);
    }

    /**
     * Solo lo store, per «Nuova personalizzazione» della scheda dell'articolo:
     * chi vende scrive nome e sovrapprezzo, il resto lo decide il server (vedi
     * quickCreateValues()).
     */
    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)
            ->only(['store'])
            ->fields('store', ['name', 'kind', 'surcharge', 'scope']);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return parent::navigationSchema()
            ->inSection('catalogo')
            ->title('Personalizzazioni')
            ->order(44)
            ->authority(['admin', 'administrator']);
    }

    /**
     * I campi del modal «Nuova personalizzazione» della scheda articolo: il
     * nome, il tipo — testo o numero: una scelta ha bisogno delle sue
     * opzioni e si fa dalla sua pagina — e il sovrapprezzo. Dalla scheda del
     * piano (`$scope` «subscriptions») porta anche lo scope nascosto, che fa
     * nascere la personalizzazione per gli abbonamenti.
     *
     * @return list<\Wonder\App\ResourceSchema\Input>
     */
    public static function quickCreateFields(string $scope = 'products'): array
    {
        $fields = [
            FormField::key('name')->text()->label('Nome')->required()->columnSpan(12),
            FormField::key('kind')
                ->select(array_diff_key(static::kinds(), ['choice' => true]))
                ->value('text')
                ->label('Tipo')
                ->required()
                ->columnSpan(12),
            FormField::key('surcharge')->price()->decimal(2)->value('0.00')->label('Sovrapprezzo')->columnSpan(12),
        ];

        if ($scope === 'subscriptions') {
            $fields[] = FormField::key('scope')->hidden()->value('subscriptions');
        }

        return $fields;
    }

    /**
     * Quello che lo store API accetta: nome, tipo e sovrapprezzo (vuoto vale
     * zero, la virgola si legge come in un form, negativo no). Il tipo è un
     * numero se lo dice la richiesta, altrimenti un testo da
     * {@see QUICK_MAX_LENGTH} caratteri: le opzioni di una scelta si
     * preparano dalla pagina della personalizzazione. Il resto lo decide il
     * server — attivo, zero decimali —, qualunque cosa porti la richiesta.
     * Con `scope` «subscriptions» nasce per gli abbonamenti, altrimenti per
     * i prodotti.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public static function quickCreateValues(array $values): array
    {
        $name = is_scalar($values['name'] ?? null) ? trim((string) $values['name']) : '';

        if ($name === '') {
            throw UserError::make('customization.quick_name');
        }

        $kind = ($values['kind'] ?? null) === 'number' ? 'number' : 'text';
        $forSubscriptions = ($values['scope'] ?? null) === 'subscriptions';
        $surcharge = $values['surcharge'] ?? null;

        if ($surcharge === null || (is_string($surcharge) && trim($surcharge) === '')) {
            $surcharge = '0.00';
        } else {
            $surcharge = is_scalar($surcharge) ? Numbers::fromForm($surcharge) : null;

            if ($surcharge === null || (float) $surcharge < 0) {
                throw UserError::make('customization.surcharge');
            }

            $surcharge = number_format((float) $surcharge, 2, '.', '');
        }

        return [
            'name' => $name,
            'label' => $name,
            'help_text' => '',
            'kind' => $kind,
            'max_length' => $kind === 'text' ? static::QUICK_MAX_LENGTH : 0,
            'decimals' => 0,
            'surcharge' => $surcharge,
            'active' => 'true',
            'for_products' => $forSubscriptions ? 'false' : 'true',
            'for_subscriptions' => $forSubscriptions ? 'true' : 'false',
        ];
    }

    /**
     * Le regole del form, la posizione alla creazione e il tipo che non
     * lascia opzioni appese.
     */
    public static function mutateRequestValues(
        array $values,
        string $action,
        string $context = 'backend',
        ?array $oldValues = null
    ): array {
        if ($context === 'api' && $action === 'store') {
            $values = static::quickCreateValues($values);
        } else {
            $options = Repeater::rowsFromRequest('options', (array) $_POST);

            // Lo zero non si ristampa nel campo, e un campo vuoto vuol dire zero.
            if (trim((string) ($values['decimals'] ?? '')) === '') {
                $values['decimals'] = '0';
            }

            Customizations::assertDefinition($values, $options);

            // Il controllo ha accettato vuoto e virgola: si scrive sempre un decimale.
            // Una scelta non ha il suo: il prezzo lo fanno le opzioni.
            $values['surcharge'] = ($values['kind'] ?? '') === 'choice'
                ? '0.00'
                : Numbers::fromForm($values['surcharge'] ?? null) ?? '0.00';

            foreach ((array) ($_POST['options'] ?? []) as $key => $row) {
                if (is_array($row)) {
                    $_POST['options'][$key]['surcharge'] = Numbers::fromForm($row['surcharge'] ?? null) ?? '0.00';
                }
            }

            // Un testo o un numero non hanno opzioni: passando da scelta a uno
            // di loro quelle postate (il riquadro è solo nascosto) non devono
            // restare.
            if (($values['kind'] ?? '') !== 'choice') {
                $_POST['options'] = [];
            }

            // Un campo nascosto può arrivare vuoto: le colonne sono interi, e
            // quello che non vale per il tipo si azzera.
            $isText = ($values['kind'] ?? '') === 'text';
            $isNumber = ($values['kind'] ?? '') === 'number';
            $values['max_length'] = $isText || is_numeric($values['max_length'] ?? null) ? (int) $values['max_length'] : 0;
            $values['decimals'] = $isNumber ? (int) $values['decimals'] : 0;
        }

        // Lo store API ha già gli interruttori da quickCreateValues().
        if ($context !== 'api') {
            $values = static::scopeValues($values, (array) $_POST, $oldValues);
        }
        $id = (int) ($oldValues['id'] ?? 0);
        static::assertScopes(
            $values,
            $id > 0 ? static::linkCount($id, 'product_model_id') : 0,
            $id > 0 ? static::linkCount($id, 'plan_id') : 0
        );

        if ($action === 'store') {
            $values['position'] = Positions::next(Customization::$table);
        } else {
            unset($values['position']);
        }

        return $values;
    }

    /** Le opzioni senza etichetta non sono opzioni: non si scrivono. */
    public static function prepareRepeaterRows(
        string $inputName,
        array $rows,
        string $action = 'store',
        string $context = 'backend'
    ): array {
        if ($inputName !== 'options') {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => trim((string) ($row['label'] ?? '')) !== ''
        ));
    }

    /**
     * Le opzioni si scrivono solo dalla pagina della personalizzazione: lo
     * store API passa al repeater la richiesta intera, e un `options[...]`
     * portato lì creerebbe opzioni sotto a un testo appena nato.
     */
    public static function syncRepeaterRelations(
        int|string $parentId,
        array $post,
        array $files = [],
        string $action = 'store',
        string $context = 'backend'
    ): array {
        if ($context === 'api') {
            return [];
        }

        return parent::syncRepeaterRelations($parentId, $post, $files, $action, $context);
    }

    /**
     * I due interruttori come vanno scritti: quello postato se il form lo
     * mostrava, altrimenti quello salvato, altrimenti quello di serie.
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed> $post
     * @param array<string, mixed>|null $oldValues
     * @return array<string, mixed>
     */
    public static function scopeValues(array $values, array $post, ?array $oldValues = null): array
    {
        foreach (static::SCOPE_DEFAULTS as $key => $default) {
            $shown = Gestionale::feature(static::SCOPES[$key]) && array_key_exists($key, $post);
            $value = $shown ? ($values[$key] ?? $post[$key]) : ($oldValues[$key] ?? $default);
            $values[$key] = (string) $value === 'true' ? 'true' : 'false';
        }

        return $values;
    }

    /**
     * Almeno uno dei due interruttori acceso, e nessuno spento finché ha
     * collegamenti: un articolo o un piano si terrebbero una personalizzazione
     * che non potrebbero più avere.
     *
     * @param array<string, mixed> $values
     * @throws UserError
     */
    public static function assertScopes(array $values, int $products, int $plans): void
    {
        $forProducts = ($values['for_products'] ?? 'false') === 'true';
        $forSubscriptions = ($values['for_subscriptions'] ?? 'false') === 'true';

        if (!$forProducts && !$forSubscriptions) {
            throw UserError::make('customization.scope_required');
        }

        if (!$forProducts && $products > 0) {
            throw UserError::make('customization.linked_products', ['count' => $products]);
        }

        if (!$forSubscriptions && $plans > 0) {
            throw UserError::make('customization.linked_plans', ['count' => $plans]);
        }
    }

    /** Quanti articoli e piani la usano. */
    public static function usage(int $id): int
    {
        if ($id <= 0) {
            return 0;
        }

        return count(static::rowsOf(CustomizationLink::class, ['customization_id' => $id]));
    }

    /** Quanti collegamenti vivi ha verso gli articoli (`product_model_id`) o i piani (`plan_id`). */
    public static function linkCount(int $id, string $column): int
    {
        return count(array_filter(
            static::rowsOf(CustomizationLink::class, ['customization_id' => $id]),
            static fn (array $row): bool => (int) ($row[$column] ?? 0) > 0
        ));
    }

    /** Una personalizzazione su qualche articolo o piano si disattiva, non si elimina. */
    public static function assertDeletable(int|string $id): void
    {
        $count = static::usage((int) $id);

        if ($count > 0) {
            // `refusal()` e non `make()`: chi cancella dall'elenco intercetta
            // `RuntimeException` (vedi `UserError`).
            throw UserError::refusal('customization.in_use', ['count' => $count]);
        }
    }

    /** Opzioni e collegamenti nel cestino se ne vanno con la personalizzazione: la chiave esterna non lascerebbe eliminarla. */
    public static function deleteRecord(int|string $id): object
    {
        $id = (int) $id;

        static::assertDeletable($id);

        $result = null;

        Transaction::run(static function () use ($id, &$result): void {
            // Anche quelle nel cestino: la chiave esterna le vede comunque. I
            // collegamenti rimasti sono tutti nel cestino (`assertDeletable`
            // ha rifiutato quelli vivi).
            foreach ([CustomizationOption::class, CustomizationLink::class] as $model) {
                foreach (static::rowsOf($model, ['customization_id' => $id, 'deleted' => ['true', 'false']]) as $row) {
                    $model::delete((int) $row['id']);
                }
            }

            $result = Customization::delete($id);
        });

        return is_object($result) ? $result : (object) ['success' => true, 'table' => Customization::$table, 'id' => $id];
    }
}
