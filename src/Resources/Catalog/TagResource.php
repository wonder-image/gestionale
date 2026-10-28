<?php

namespace Wonder\Plugin\Gestionale\Resources\Catalog;

use RuntimeException;
use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Tag;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Placements;
use Wonder\Plugin\Gestionale\Support\Catalog\PlacementsForm;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Positions;

/**
 * "Tag": un'etichetta trasversale alle categorie ("novità", "saldi").
 *
 * Un tag usato non si elimina: si nasconde, così le schede non perdono
 * l'etichetta che qualcuno aveva messo apposta.
 *
 * Con «Vendita online» la scheda ha anche «Ordine nel sito», come le
 * categorie ({@see Placements}).
 */
final class TagResource extends GestionaleResource
{
    use PlacementsForm;

    public const PLACEMENT_CONTEXT = Placements::TAG;

    public static string $model = Tag::class;
    public static string $orderColumn = 'position';
    public static string $orderDirection = 'ASC';
    public static string $docsPage = 'catalogo/catalogo-tassonomie';

    public static function path(): string
    {
        return 'app/gestionale/tag';
    }

    public static function icon(): string
    {
        return 'bi-tag';
    }

    public static function titleLabel(): string
    {
        return 'Tag';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'tag',
            'plural_label' => 'tag',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'i',
            'full' => 'visibile',
            'empty' => 'nascosto',
            'this' => 'questo',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'name' => 'Nome',
            'slug' => 'Url pubblico',
            'image' => 'Immagine',
            'visible' => 'Stato',
            'sort_mode' => 'Ordine degli altri articoli',
            'placements' => 'In evidenza',
        ];
    }

    public static function formSchema(): array
    {
        return [
            FormField::key('name')->text()->label('Nome')->required(),
            FormField::key('visible')
                ->select(['true' => 'Visibile', 'false' => 'Nascosto'])
                ->value('true')
                ->label('Stato')
                ->required(),
            FormField::key('image')->fileDragDrop('image')->label('Immagine'),
            ...static::siteOrderFields(),
        ];
    }

    public static function formLayoutSchema(): ?Form
    {
        return (new Form)->components([
            (new Container)->components([
                (new Card)->components([
                    SectionTitle::make('Tag')
                        ->tooltip('L\'url pubblico nasce dal nome alla creazione e non cambia più.')
                        ->columnSpan(12),
                    static::getInput('name')->columnSpan(8),
                    static::getInput('visible')->columnSpan(4),
                    static::getInput('image')->columnSpan(12),
                ])->columns(12)->columnSpan(12),

                ...static::siteOrderCards('Gli articoli con questo tag, nel sito. «In evidenza» vanno in cima, nell\'ordine in cui li trascini; gli altri seguono la regola scelta. Un articolo in evidenza senza questo tag non compare. La foto di contesto è facoltativa: la usano i blocchi del sito che la prevedono, per esempio una griglia «Giorno» e «Notte».'),
            ])->columns(12)->columnSpan(12),
        ]);
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('name')->text()->link('edit'),
            TableColumn::key('slug')->text(),
            TableColumn::key('visible')->visibleBadge()->size('little'),
            TableColumn::key('actions')->button()->actions(['edit', 'delete']),
        ];
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()
            ->disable(['view'])
            ->titles([
                'list' => 'Tag',
                'create' => 'Nuovo tag',
                'edit' => 'Modifica tag',
            ])
            ->actions('list', [CatalogOrderResource::listAction('tag')]);
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->access(Gestionale::area('catalogo'));
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->inSection('catalogo')
            ->title('Tag')
            ->order(42)
            ->access(Gestionale::area('catalogo'));
    }

    /**
     * Il nome è obbligatorio anche lato server: il `required` del form non
     * ferma un nome di soli spazi. Lo slug nasce dal nome alla creazione e
     * poi non si tocca più.
     */
    public static function mutateRequestValues(
        array $values,
        string $action,
        string $context = 'backend',
        ?array $oldValues = null
    ): array {
        $values = static::requireName($values, $action, 'tag.name_required');

        if ($action === 'store') {
            $values['slug'] = Slug::make((string) ($values['name'] ?? ''), Tag::$table);
            // In fondo agli altri: l'ordine si cambia da «Ordina».
            $values['position'] = Positions::next(Tag::$table);
        } else {
            unset($values['slug'], $values['position']);
        }

        return static::siteOrderValues($values);
    }

    public static function mutateFormValues(array $values, string $mode, string $context = 'backend'): array
    {
        return static::placementFormValues($values);
    }

    public static function afterDelete(int|string $id, object $result, array $values = []): void
    {
        Placements::dropContext(Placements::TAG, (int) $id);
        parent::afterDelete($id, $result, $values);
    }

    /** Un tag usato resta, nascosto. */
    public static function assertDeletable(int|string $id): void
    {
        if (static::isUsed((int) $id)) {
            throw new RuntimeException(
                'Questo tag è usato da qualche prodotto: nascondilo invece di eliminarlo.'
            );
        }
    }

    /** In G2a i modelli non esistono ancora: lo saprà il piano 3. */
    protected static function isUsed(int $id): bool
    {
        return false;
    }
}
