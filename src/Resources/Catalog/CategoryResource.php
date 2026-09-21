<?php

namespace Wonder\Plugin\Gestionale\Resources\Catalog;

use RuntimeException;
use Throwable;
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
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Catalog\CategoryTree;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

/**
 * "Categorie": l'albero con cui si naviga il catalogo.
 *
 * L'albero è profondo quanto serve, ma una categoria non può finire sotto sé
 * stessa né sotto una sua discendente: il select non le propone e il
 * salvataggio le rifiuta lo stesso, perché il select non è una difesa.
 */
final class CategoryResource extends GestionaleResource
{
    public static string $model = Category::class;
    public static string $orderColumn = 'position';
    public static string $orderDirection = 'ASC';
    public static string $docsPage = 'catalogo/marchi-categorie-tag';

    public static function path(): string
    {
        return 'app/gestionale/categorie';
    }

    public static function icon(): string
    {
        return 'bi-diagram-3';
    }

    public static function titleLabel(): string
    {
        return 'Categorie';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'categoria',
            'plural_label' => 'categorie',
            'last' => 'ultime',
            'all' => 'tutte',
            'article' => 'le',
            'full' => 'visibile',
            'empty' => 'nascosta',
            'this' => 'questa',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'name' => 'Nome',
            'slug' => 'Indirizzo',
            'parent_id' => 'Categoria padre',
            'image' => 'Immagine',
            'description' => 'Descrizione',
            'position' => 'Posizione',
            'visible' => 'Stato',
        ];
    }

    public static function formSchema(): array
    {
        return [
            FormField::key('name')->text()->label('Nome')->required(),
            FormField::key('parent_id')->select(static::parentOptions())->label('Categoria padre'),
            FormField::key('position')->number()->decimal(0)->label('Posizione')->value('1'),
            FormField::key('visible')
                ->select(['true' => 'Visibile', 'false' => 'Nascosta'])
                ->value('true')
                ->label('Stato')
                ->required(),
            FormField::key('image')->fileDragDrop('image')->label('Immagine'),
            FormField::key('description')->textarea()->label('Descrizione'),
        ];
    }

    public static function formLayoutSchema(): ?Form
    {
        return (new Form)->components([
            (new Container)->components([
                (new Card)->components([
                    SectionTitle::make('Categoria')
                        ->tooltip('Lasciando vuoto il padre la categoria è principale. L\'indirizzo nasce dal nome alla creazione e non cambia più.')
                        ->columnSpan(12),
                    static::getInput('name')->columnSpan(6),
                    static::getInput('parent_id')->columnSpan(6),
                    static::getInput('position')->columnSpan(3),
                    static::getInput('visible')->columnSpan(3),
                    static::getInput('description')->columnSpan(12),
                ])->columns(12)->columnSpan(12),

                (new Card)->components([
                    SectionTitle::make('Immagine')->columnSpan(12),
                    static::getInput('image')->columnSpan(12),
                ])->columns(12)->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ]);
    }

    public static function tableSchema(): array
    {
        return [
            // Nell'elenco conta dove sta la categoria, non solo come si chiama.
            TableColumn::key('name')
                ->text()
                ->link('edit')
                ->formatter(static function (array $row): string {
                    $path = CategoryTree::sorted(static::rows());
                    $names = array_column($path, 'path', 'id');

                    return htmlspecialchars(
                        (string) ($names[(int) ($row['id'] ?? 0)] ?? ($row['name'] ?? '')),
                        ENT_QUOTES,
                        'UTF-8'
                    );
                }),
            TableColumn::key('slug')->text(),
            TableColumn::key('position')->text()->size('little'),
            TableColumn::key('visible')->visibleBadge()->size('little'),
            TableColumn::key('actions')->button()->actions(['edit', 'delete']),
        ];
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()
            ->disable(['view'])
            ->titles([
                'list' => 'Categorie',
                'create' => 'Nuova categoria',
                'edit' => 'Modifica categoria',
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
            ->title('Categorie')
            ->order(41)
            ->authority(['admin', 'administrator']);
    }

    /** Slug alla creazione, e nessun anello nell'albero. */
    public static function mutateRequestValues(
        array $values,
        string $action,
        string $context = 'backend',
        ?array $oldValues = null
    ): array {
        if ($action === 'store') {
            $values['slug'] = Slug::make((string) ($values['name'] ?? ''), Category::$table);
        } else {
            unset($values['slug']);
        }

        $id = (int) ($oldValues['id'] ?? 0);
        $parent = (int) ($values['parent_id'] ?? 0);

        if ($id > 0 && $parent > 0) {
            static::assertNoLoop(static::rows(), $id, $parent);
        }

        return $values;
    }

    /** Voci del select del padre, senza la categoria stessa e i suoi figli. */
    public static function parentOptions(?array $rows = null, ?int $exclude = null): array
    {
        return CategoryTree::options($rows ?? static::rows(), $exclude ?? static::currentId());
    }

    /** Un anello nell'albero si ferma qui, con parole da leggere. */
    public static function assertNoLoop(array $rows, int $id, int $parentId): void
    {
        if (CategoryTree::wouldLoop($rows, $id, $parentId)) {
            throw UserError::make('category.loop');
        }
    }

    /** Una categoria con figli non si elimina: resterebbero orfani. */
    public static function assertDeletable(int|string $id): void
    {
        if (CategoryTree::descendants(static::rows(), (int) $id) !== []) {
            throw new RuntimeException(
                'Questa categoria ha delle sottocategorie: spostale o eliminale prima.'
            );
        }
    }

    /**
     * Le categorie, per l'albero.
     *
     * Senza database (test degli schemi, convenzioni) torna vuoto invece di
     * far esplodere il form: qui servono le voci di un select, non i dati.
     *
     * @return list<array<string, mixed>>
     */
    private static function rows(): array
    {
        try {
            $rows = Category::find(['deleted' => 'false'], null, 'position', 'ASC');
        } catch (Throwable) {
            return [];
        }


        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }

    /** L'id della categoria aperta, quando c'è. */
    private static function currentId(): ?int
    {
        $id = (int) ($_GET['id'] ?? 0);

        if ($id > 0) {
            return $id;
        }

        // Rotta `/categorie/{id}/edit/`: l'id sta nel percorso.
        if (preg_match('#/categorie/(\d+)/#', (string) ($_SERVER['REQUEST_URI'] ?? ''), $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }
}
