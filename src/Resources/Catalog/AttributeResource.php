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
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\Plugin\Gestionale\Models\Catalog\Attribute;
use Wonder\Plugin\Gestionale\Models\Catalog\AttributeValue;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Attributes;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductAttributes;
use Wonder\Plugin\Gestionale\Support\Catalog\Units;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Positions;

/**
 * "Attributi": Colore, Taglia, Materiale — quello che distingue un articolo
 * dall'altro e quello che fa nascere le sue opzioni in vendita.
 *
 * L'uso si sceglie qui, e dice dove finisce il valore. I valori di un
 * attributo a elenco si scrivono nella scheda, come righe. La scheda chiede
 * solo quello che serve al tipo, e cambia mentre lo si sceglie, senza salvare:
 * un Elenco ha il nome del valore, un Colore anche il codice, una Fantasia
 * anche l'immagine, un'Icona un'immagine sua; Testo e Numero non hanno
 * valori, ma un'unità di misura.
 *
 * Non è `final`: i test la estendono con una classe anonima per provare la
 * regola sul cambio di tipo senza database.
 */
class AttributeResource extends GestionaleResource
{
    public static string $model = Attribute::class;
    public static string $orderColumn = 'position';
    public static string $orderDirection = 'ASC';
    public static string $docsPage = 'catalogo/catalogo-attributi';

    /** Tipo di un attributo nuovo: quello che serve quasi sempre. */
    public const DEFAULT_TYPE = 'select';

    /**
     * I tipi che nascono dalla scheda prodotto, con «Nuova caratteristica»:
     * quelli senza valori. Elenchi, Colori, Fantasie e Icone hanno valori e
     * immagini da preparare, e si creano qui.
     */
    public const QUICK_TYPES = ['text', 'number'];

    /** L'uso di una caratteristica nata dalla scheda prodotto. */
    public const QUICK_LEVEL = 'model';

    public static function path(): string
    {
        return 'app/gestionale/attributi';
    }

    public static function icon(): string
    {
        return 'bi-sliders';
    }

    public static function titleLabel(): string
    {
        return 'Attributi';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'attributo',
            'plural_label' => 'attributi',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'gli',
            'full' => 'visibile',
            'empty' => 'nascosto',
            'this' => 'questo',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'name' => 'Nome',
            'slug' => 'Nome macchina',
            'level' => 'Uso',
            'type' => 'Tipo',
            'unit' => 'Unità di misura',
            'is_filterable' => 'Filtro',
            'is_visible' => 'Stato',
        ];
    }

    /**
     * L'indirizzo della scheda di un attributo.
     *
     * Lo usa la matita accanto ai gruppi di spunte della scheda prodotto: si
     * modifica l'attributo da dove lo si sta usando.
     */
    public static function editUrlFor(int $attributeId): string
    {
        $base = '/backend/'.static::path();

        if (function_exists('__r')) {
            try {
                $named = (string) __r('backend.resource.'.static::slug().'.list');
                $base = $named !== '' ? $named : $base;
            } catch (\Throwable) {
                // Rotta non registrata: resta il percorso.
            }
        }

        return rtrim($base, '/').'/'.$attributeId.'/edit/';
    }

    public static function formSchema(): array
    {
        // Un attributo già sugli articoli non cambia uso: i valori scritti
        // stanno nella tabella di quell'uso, e con un altro nessuno li
        // leggerebbe più.
        $inUso = static::isUsed((int) (static::currentId() ?? 0));

        return [
            FormField::key('name')->text()->label('Nome')->required(),
            // Due caselle per una sola risposta: le voci dipendono dal tipo,
            // e il tipo si cambia senza ricaricare. Se ne vede una alla
            // volta; quale conta lo decide il tipo, in mutateRequestValues().
            FormField::key('level')
                ->select(Attributes::levelsFor('select'))
                ->value('product')
                ->label('Uso')
                ->required()
                ->disabled($inUso)
                ->visibleWhen('type', Attributes::VALUE_TYPES),
            FormField::key('level_text')
                ->select(Attributes::levelsFor('text'))
                ->value('model')
                ->label('Uso')
                ->required()
                ->disabled($inUso)
                ->visibleWhen('type', Attributes::UNIT_TYPES),
            FormField::key('type')
                ->select(Attributes::types())
                ->value(static::DEFAULT_TYPE)
                ->label('Tipo')
                ->required(),
            // L'unità è un elenco, non testo libero: due schede scrivevano
            // "g" e "grammi" per la stessa cosa. Si vede solo dove vuol dire
            // qualcosa — un numero o un testo — e non su un colore.
            FormField::key('unit')
                ->select(Units::all())
                ->label('Unità di misura')
                ->visibleWhen('type', Attributes::UNIT_TYPES),
            FormField::key('is_filterable')
                ->select(['true' => 'Sì', 'false' => 'No'])
                ->value('true')
                ->label('Filtro')
                ->required(),
            FormField::key('is_visible')
                ->select(['true' => 'Visibile', 'false' => 'Nascosto'])
                ->value('true')
                ->label('Stato')
                ->required(),
            FormField::key('values')
                ->repeater([
                    RepeaterColumn::key('id')->hidden(),
                    // Le colonne seguono il tipo: l'immagine sulla Fantasia e
                    // sull'Icona (un'Icona è un'immagine sua), il codice solo
                    // sul Colore. Il valore riempie lo spazio che resta
                    // libero — il riordino a mano ne prende tre per le frecce
                    // e il cestino — così una colonna nascosta non lascia un
                    // buco.
                    RepeaterColumn::key('image')
                        ->fileDragDrop('image')
                        ->label('Immagine')
                        ->columnSpan(2)
                        ->visibleWhen('type', ['pattern', 'icon']),
                    RepeaterColumn::key('label')->text()->label('Valore')->columnFill(),
                    RepeaterColumn::key('color')
                        ->color()
                        ->label('Colore')
                        ->columnSpan(3)
                        ->visibleWhen('type', 'color'),
                    RepeaterColumn::key('description')->text()->label('Descrizione')->columnSpan(12),
                ])
                ->relation(
                    RepeaterRelation::make(AttributeValue::$table, 'attribute_id')
                        ->model(AttributeValue::class)
                        ->positionKey('position')
                )
                ->nested()
                ->repeaterSortable()
                // La descrizione non entra nella riga: in un ottavo di
                // larghezza non ci si scrive niente, e a tutta riga
                // spingerebbe i bottoni su una riga loro.
                ->repeaterAdvanced('description')
                ->repeaterAdvancedLabel('Aggiungi una descrizione')
                ->repeaterAddLabel('Aggiungi valore')
                ->repeaterDeleteTitle('Elimina valore')
                ->repeaterDeleteText('Confermi l\'eliminazione di questo valore?')
                ->repeaterDeleteCancelLabel('Annulla')
                ->repeaterDeleteConfirmLabel('Elimina')
                ->repeaterDeleteConfirmClass('btn btn-danger')
                // Niente etichetta: il titolo del riquadro dice già "Valori", e
                // due titoli uguali di fila si leggono male.
                ->label(''),
        ];
    }

    public static function formLayoutSchema(): ?Form
    {
        $cards = [
            (new Card)->components([
                SectionTitle::make('Attributo')
                    ->tooltip('L\'uso dice dove si sceglie il valore. «Scheda tecnica dell\'articolo»: uno per articolo, come il Materiale. «Opzione da scegliere»: fa nascere le opzioni in vendita, come la Taglia. «Opzione con foto proprie»: come la Taglia, ma ogni valore ha le sue foto, come il Colore. Il tipo decide cosa chiedono i valori: un Elenco solo il nome, un Colore anche il pallino, una Fantasia anche l\'immagine, un\'Icona un\'immagine sua. Testo e Numero si scrivono a mano, con l\'unità di misura, e non creano opzioni. Quando un attributo è già sugli articoli il suo uso non cambia più.')
                    ->columnSpan(12),
                // L'unità in fondo alla riga: quando il tipo non la usa sparisce
                // e il vuoto resta in coda, non in mezzo.
                static::getInput('name')->columnSpan(6),
                static::getInput('type')->columnSpan(3),
                static::getInput('unit')->columnSpan(3),
                // Una delle due, secondo il tipo: nello stesso posto.
                static::getInput('level')->columnSpan(6),
                static::getInput('level_text')->columnSpan(6),
                static::getInput('is_filterable')->columnSpan(3),
                static::getInput('is_visible')->columnSpan(3),
            ])->columns(12)->columnSpan(12),
            // Il riquadro dei valori c'è sempre e segue il tipo mentre lo si
            // sceglie: prima lo decideva il server, e cambiando tipo bisognava
            // salvare per vederlo comparire o sparire.
            (new Card)->components([
                SectionTitle::make('Valori')
                    ->tooltip('L\'ordine è quello che vedrà il cliente. Il nome di un valore è anche quello che si legge nelle opzioni in vendita: si rinomina qui. La descrizione si apre dalla riga e serve a spiegarlo a chi compra.')
                    ->columnSpan(12),
                static::getInput('values')->columnSpan(12),
            ])->columns(12)->columnSpan(12)->visibleWhen('type', Attributes::VALUE_TYPES),
        ];

        return (new Form)->components([
            (new Container)->components($cards)->columns(12)->columnSpan(12),
        ]);
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('name')->text()->link('edit'),
            TableColumn::key('level')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => htmlspecialchars(
                    Attributes::levelLabel((string) ($row['type'] ?? ''), (string) ($row['level'] ?? '')),
                    ENT_QUOTES,
                    'UTF-8'
                )),
            TableColumn::key('type')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => htmlspecialchars(
                    Attributes::types()[(string) ($row['type'] ?? '')] ?? '',
                    ENT_QUOTES,
                    'UTF-8'
                )),
            TableColumn::key('is_visible')->visibleBadge()->size('little'),
            TableColumn::key('actions')->button()->actions(['edit', 'delete']),
        ];
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()
            ->disable(['view'])
            ->titles([
                'list' => 'Attributi',
                'create' => 'Nuovo attributo',
                'edit' => 'Modifica attributo',
            ]);
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->backendCrud(['admin', 'administrator']);
    }

    /**
     * Solo lo store, per «Nuova caratteristica» della scheda prodotto: chi
     * vende scrive nome, tipo e unità, il resto lo decide il server (vedi
     * quickCreateValues()).
     */
    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)
            ->only(['store'])
            ->fields('store', ['name', 'type', 'unit']);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->inSection('catalogo')
            ->title('Attributi')
            ->order(43)
            ->authority(['admin', 'administrator']);
    }

    /** Vero quando quell'attributo si sceglie da un elenco di valori. */
    public static function usesValues(?array $row): bool
    {
        return $row !== null && Attributes::usesValues((string) ($row['type'] ?? ''));
    }

    /** La casella dell'uso che il tipo mostra, riempita dalla stessa colonna. */
    public static function mutateFormValues(array $values, string $mode, string $context = 'backend'): array
    {
        if (isset($values['level'])) {
            $values['level_text'] = $values['level'];
        }

        return $values;
    }

    /**
     * Nome macchina alla creazione, nessun tipo cambiato sotto ai valori,
     * un uso che il tipo ammette e che non cambia sotto agli articoli, e
     * nessuna unità dove il tipo non la usa.
     */
    public static function mutateRequestValues(
        array $values,
        string $action,
        string $context = 'backend',
        ?array $oldValues = null
    ): array {
        // Lo store API lo usa solo «Nuova caratteristica»: da lì nasce una
        // caratteristica della scheda tecnica, qualunque cosa porti la
        // richiesta.
        if ($context === 'api' && $action === 'store') {
            $values = static::quickCreateValues($values);
        }

        if ($action === 'store') {
            $values['slug'] = Slug::make((string) ($values['name'] ?? ''), Attribute::$table);
            $values['position'] = Positions::next(Attribute::$table);
        } else {
            unset($values['slug'], $values['position']);
        }

        $id = (int) ($oldValues['id'] ?? 0);
        $from = (string) ($oldValues['type'] ?? '');
        $to = (string) ($values['type'] ?? $from);

        if ($id > 0 && $to !== $from && Attributes::usesValues($from) && !Attributes::usesValues($to)) {
            static::assertNoValues($id);
        }

        $values = static::withLevel($values, $to, $oldValues);

        // L'unità nascosta arriva lo stesso con il resto del modulo: passando
        // da Numero a Colore resterebbe "g" su un attributo che non la mostra.
        // Colori e immagini dei valori invece restano: tornando al tipo di
        // prima si ritrovano.
        if ((array_key_exists('unit', $values) || array_key_exists('type', $values)) && !Attributes::usesUnit($to)) {
            $values['unit'] = '';
        }

        return $values;
    }

    /**
     * L'uso dalla casella che il tipo mostra.
     *
     * Arrivano tutte e due — quella nascosta viene postata lo stesso — e
     * nessuna quando l'attributo è in uso: le caselle sono spente, e un
     * campo spento non si posta. Allora resta quello di prima.
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed>|null $oldValues
     * @return array<string, mixed>
     */
    public static function withLevel(array $values, string $type, ?array $oldValues = null): array
    {
        $key = Attributes::usesUnit($type) ? 'level_text' : 'level';
        $before = (string) ($oldValues['level'] ?? '');
        $level = trim((string) ($values[$key] ?? ''));
        unset($values['level_text']);

        if ($level === '') {
            if ($before === '') {
                unset($values['level']);

                return $values;
            }

            $level = $before;
        }

        $id = (int) ($oldValues['id'] ?? 0);

        if ($id > 0 && $level !== $before && static::isUsed($id)) {
            throw UserError::make('attribute.level_locked');
        }

        // Il controllo sull'uso viene prima: un attributo in uso con un uso
        // che il tipo nuovo non ammette dice di non cambiarlo, non di
        // sceglierne un altro.
        if (!Attributes::acceptsLevel($type, $level) && !($id > 0 && $level === $before && static::isUsed($id))) {
            throw UserError::make('attribute.text_level');
        }

        $values['level'] = $level;

        return $values;
    }

    /**
     * I tipi del modal «Nuova caratteristica», con i loro nomi.
     *
     * @return array<string, string>
     */
    public static function quickTypes(): array
    {
        return array_intersect_key(Attributes::types(), array_flip(static::QUICK_TYPES));
    }

    /**
     * I campi del modal «Nuova caratteristica» della scheda prodotto.
     *
     * Le caselle nascoste dicono cosa nasce, ma il server non le legge: uso,
     * filtro e stato li rimette lui in quickCreateValues().
     *
     * @return list<\Wonder\App\ResourceSchema\Input>
     */
    public static function quickCreateFields(): array
    {
        return [
            FormField::key('name')->text()->label('Nome')->required()->columnSpan(12),
            FormField::key('type')
                ->select(static::quickTypes())
                ->value('text')
                ->label('Tipo')
                ->required()
                ->columnSpan(6),
            FormField::key('unit')->select(Units::all())->label('Unità di misura')->columnSpan(6),
            FormField::key('level_text')->hidden()->value(static::QUICK_LEVEL),
            FormField::key('is_filterable')->hidden()->value('false'),
            FormField::key('is_visible')->hidden()->value('true'),
        ];
    }

    /**
     * Quello che lo store API accetta da «Nuova caratteristica».
     *
     * Dalla richiesta vengono solo nome, tipo e unità, e il tipo è un Testo
     * o un Numero; il resto lo decide il server: la scheda tecnica
     * dell'articolo, visibile, fuori dai filtri. Un Elenco, un Colore, una
     * Fantasia o un'Icona hanno valori e immagini da preparare: da qui non
     * nascono, e nemmeno un uso diverso.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public static function quickCreateValues(array $values): array
    {
        $text = static fn (string $key): string => is_scalar($values[$key] ?? null) ? trim((string) $values[$key]) : '';

        $name = $text('name');

        if ($name === '') {
            throw UserError::make('attribute.quick_name');
        }

        $type = $text('type') !== '' ? $text('type') : 'text';

        if (!in_array($type, static::QUICK_TYPES, true)) {
            throw UserError::make('attribute.quick_type');
        }

        $unit = $text('unit');

        if (!array_key_exists($unit, Units::all())) {
            throw UserError::make('attribute.quick_unit');
        }

        return [
            'name' => $name,
            'type' => $type,
            'unit' => $unit,
            // La casella dell'uso che un Testo e un Numero mostrano:
            // withLevel() la legge e la toglie.
            'level_text' => static::QUICK_LEVEL,
            'is_filterable' => 'false',
            'is_visible' => 'true',
        ];
    }

    /**
     * I valori si scrivono solo dalla scheda dell'attributo.
     *
     * Lo store API passa al repeater la richiesta intera, non solo i campi che
     * accetta: senza questo un `values[...]` portato nella richiesta
     * creerebbe dei valori sotto a un Testo appena nato.
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

    /** Cambiare tipo con dei valori dentro li butterebbe via in silenzio. */
    public static function assertNoValues(int $id): void
    {
        if (static::valueCount($id) > 0) {
            throw UserError::make('attribute.type_locked');
        }
    }

    /** Quanti valori ha quell'attributo. */
    public static function valueCount(int $id): int
    {
        return count(static::rowsOf(AttributeValue::class, ['attribute_id' => $id]));
    }

    /** Un attributo usato da un prodotto resta, nascosto. */
    public static function assertDeletable(int|string $id): void
    {
        if (static::isUsed((int) $id)) {
            throw new RuntimeException(
                'Questo attributo è usato da qualche prodotto: nascondilo invece di eliminarlo.'
            );
        }
    }

    /**
     * Vero quando l'attributo sta su almeno un articolo o un'opzione.
     *
     * Senza database (i test degli schemi) non lo è: lì serve il form, non i
     * dati.
     */
    protected static function isUsed(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        try {
            return ProductAttributes::isUsed($id);
        } catch (\Throwable) {
            return false;
        }
    }
}
