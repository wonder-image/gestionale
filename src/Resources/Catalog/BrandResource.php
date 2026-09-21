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
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Positions;

/**
 * "Marchi": il produttore di un articolo.
 *
 * Un marchio usato da un modello non si elimina: si nasconde, altrimenti le
 * schede resterebbero senza il loro marchio.
 */
final class BrandResource extends GestionaleResource
{
    public static string $model = Brand::class;
    public static string $orderColumn = 'position';
    public static string $orderDirection = 'ASC';
    public static string $docsPage = 'catalogo/marchi-categorie-tag';

    public static function path(): string
    {
        return 'app/gestionale/marchi';
    }

    public static function icon(): string
    {
        return 'bi-award';
    }

    public static function titleLabel(): string
    {
        return 'Marchi';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'marchio',
            'plural_label' => 'marchi',
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
            'logo' => 'Logo',
            'description' => 'Descrizione',
            'visible' => 'Stato',
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
            FormField::key('logo')->fileDragDrop('image')->label('Logo'),
            FormField::key('description')->textarea()->label('Descrizione'),
        ];
    }

    public static function formLayoutSchema(): ?Form
    {
        return (new Form)->components([
            (new Container)->components([
                (new Card)->components([
                    SectionTitle::make('Marchio')
                        ->tooltip('L\'url pubblico nasce dal nome alla creazione e non cambia più.')
                        ->columnSpan(12),
                    static::getInput('name')->columnSpan(8),
                    static::getInput('visible')->columnSpan(4),
                    static::getInput('description')->columnSpan(12),
                ])->columns(12)->columnSpan(12),

                (new Card)->components([
                    SectionTitle::make('Logo')->columnSpan(12),
                    static::getInput('logo')->columnSpan(12),
                ])->columns(12)->columnSpan(12),
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
                'list' => 'Marchi',
                'create' => 'Nuovo marchio',
                'edit' => 'Modifica marchio',
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
            ->section('catalogo', 'Catalogo', 'bi-box-seam', 300, ['admin', 'administrator'])
            ->inSection('catalogo')
            ->title('Marchi')
            ->order(40)
            ->authority(['admin', 'administrator']);
    }

    /** Lo slug nasce dal nome alla creazione e poi non si tocca più. */
    public static function mutateRequestValues(
        array $values,
        string $action,
        string $context = 'backend',
        ?array $oldValues = null
    ): array {
        if ($action === 'store') {
            $values['slug'] = Slug::make((string) ($values['name'] ?? ''), Brand::$table);
            $values['position'] = Positions::next(Brand::$table);
        } else {
            unset($values['slug'], $values['position']);
        }

        return $values;
    }

    /** Un marchio usato resta, nascosto: le schede non devono perderlo. */
    public static function assertDeletable(int|string $id): void
    {
        if (static::isUsed((int) $id)) {
            throw new RuntimeException(
                'Questo marchio è usato da qualche prodotto: nascondilo invece di eliminarlo.'
            );
        }
    }

    /** In G2a i modelli non esistono ancora: lo saprà il piano 3. */
    protected static function isUsed(int $id): bool
    {
        return false;
    }
}
