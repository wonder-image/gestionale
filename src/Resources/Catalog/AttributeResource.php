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
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Positions;

/**
 * "Attributi": cosa distingue un articolo, una variante o un prodotto.
 *
 * Il livello si sceglie qui e dirà, nel piano 3, dove finisce il valore. I
 * valori di un attributo a elenco si scrivono nella scheda, come righe: il
 * riquadro compare solo quando il tipo li usa, perché un attributo "Testo" non
 * ha niente da elencare.
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
            'level' => 'Come si usa',
            'type' => 'Tipo',
            'group_name' => 'Gruppo',
            'unit' => 'Unità di misura',
            'is_filterable' => 'Filtro',
            'is_visible' => 'Stato',
        ];
    }

    /**
     * L'indirizzo della scheda di un attributo.
     *
     * Lo usa la matita accanto ai gruppi di spunte della scheda prodotto: si
     * modifica l'opzione da dove la si sta usando.
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
        return [
            FormField::key('name')->text()->label('Nome')->required(),
            FormField::key('level')
                ->select(Attributes::levels())
                ->value('product')
                ->label('Come si usa')
                ->required(),
            FormField::key('type')
                ->select(Attributes::types())
                ->value(static::DEFAULT_TYPE)
                ->label('Tipo')
                ->required(),
            FormField::key('group_name')->text()->label('Gruppo'),
            FormField::key('unit')->text()->label('Unità di misura'),
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
                    RepeaterColumn::key('label')->text()->label('Valore')->columnSpan(5),
                    RepeaterColumn::key('color')->color()->label('Colore')->columnSpan(3),
                    RepeaterColumn::key('image')->fileDragDrop('image')->label('Fantasia')->columnSpan(3),
                ])
                ->relation(
                    RepeaterRelation::make(AttributeValue::$table, 'attribute_id')
                        ->model(AttributeValue::class)
                        ->positionKey('position')
                )
                ->nested()
                ->repeaterSortable()
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
                    ->tooltip('«Descrive l\'articolo» finisce nella scheda tecnica: Materiale, Composizione. «Crea versioni con pagina e foto proprie» è il Colore, nei negozi dove ogni colore è un articolo a sé. «Crea versioni da scegliere nel carrello» è la Taglia. L\'unità di misura serve ai tipi "Numero".')
                    ->columnSpan(12),
                static::getInput('name')->columnSpan(6),
                static::getInput('group_name')->columnSpan(6),
                static::getInput('level')->columnSpan(4),
                static::getInput('type')->columnSpan(4),
                static::getInput('unit')->columnSpan(4),
                static::getInput('is_filterable')->columnSpan(6),
                static::getInput('is_visible')->columnSpan(6),
            ])->columns(12)->columnSpan(12),
        ];

        // Il riquadro dei valori dove serve: in creazione vale il tipo
        // predefinito (Elenco), così chi crea un attributo scrive subito i suoi
        // valori; modificando un "Testo" il riquadro non c'è, perché non ha
        // niente da elencare.
        if (static::usesValues(static::currentRow() ?? ['type' => static::DEFAULT_TYPE])) {
            $cards[] = (new Card)->components([
                SectionTitle::make('Valori')
                    ->tooltip('L\'ordine è quello che vedrà il cliente. Il colore serve al pallino in vetrina, la fantasia quando un colore non basta.')
                    ->columnSpan(12),
                static::getInput('values')->columnSpan(12),
            ])->columns(12)->columnSpan(12);
        }

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
                    Attributes::levels()[(string) ($row['level'] ?? '')] ?? '',
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
            TableColumn::key('group_name')->text()->size('little'),
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

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
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

    /** Nome macchina alla creazione, e nessun tipo cambiato sotto ai valori. */
    public static function mutateRequestValues(
        array $values,
        string $action,
        string $context = 'backend',
        ?array $oldValues = null
    ): array {
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

        return $values;
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

    /** In G2a i prodotti non esistono ancora: lo saprà il piano 3. */
    protected static function isUsed(int $id): bool
    {
        return false;
    }

    /** La riga aperta, quando si sta modificando un attributo. */
    protected static function currentRow(): ?array
    {
        $id = static::currentId();

        if ($id === null) {
            return null;
        }

        return static::rowsOf(Attribute::class, ['id' => $id])[0] ?? null;
    }
}
