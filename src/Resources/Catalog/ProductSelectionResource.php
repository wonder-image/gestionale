<?php

namespace Wonder\Plugin\Gestionale\Resources\Catalog;

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
use Wonder\Plugin\Gestionale\Models\Catalog\ProductSelection;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Placements;
use Wonder\Plugin\Gestionale\Support\Catalog\PlacementsForm;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\RequestMemo;

/**
 * «Selezioni»: elenchi di articoli scelti a mano per i blocchi del sito,
 * come «In evidenza» della home o il prodotto del mese.
 *
 * Il sito ne prende una per url pubblico (`home-prodotti`), che nasce dal nome
 * e non cambia più: rinominare la selezione non rompe la home. Titolo,
 * sottotitolo, testo e immagine sono del blocco; gli articoli stanno in
 * `gst_product_placements` ({@see Placements}). Esiste solo con la vendita
 * online, come il sito che la mostra.
 */
final class ProductSelectionResource extends GestionaleResource
{
    use PlacementsForm;

    public const PLACEMENT_CONTEXT = Placements::SELECTION;

    public static string $model = ProductSelection::class;
    public static string $feature = 'online_sales';
    public static string $orderColumn = 'name';
    public static string $orderDirection = 'ASC';
    public static string $docsPage = 'catalogo/catalogo-selezioni';

    public static function path(): string
    {
        return 'app/gestionale/selezioni';
    }

    public static function icon(): string
    {
        return 'bi-collection';
    }

    public static function titleLabel(): string
    {
        return 'Selezioni';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'selezione',
            'plural_label' => 'selezioni',
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
            'slug' => 'Url pubblico',
            'title' => 'Titolo',
            'subtitle' => 'Sottotitolo',
            'text' => 'Testo',
            'image' => 'Immagine',
            'sort_mode' => 'Ordine',
            'visible' => 'Stato',
            'placements' => 'Articoli',
        ];
    }

    public static function formSchema(): array
    {
        return [
            FormField::key('name')->text()->label('Nome')->required(),
            FormField::key('title')->text()->label('Titolo'),
            FormField::key('subtitle')->text()->label('Sottotitolo'),
            FormField::key('text')->textarea('plus')->label('Testo'),
            FormField::key('image')->fileDragDrop('image')->label('Immagine'),
            FormField::key('visible')
                ->select(['true' => 'Visibile', 'false' => 'Nascosta'])
                ->value('true')
                ->label('Stato')
                ->required(),
            FormField::key('sort_mode')
                ->select(Placements::SELECTION_SORT_MODES)
                ->value('manual')
                ->label('Ordine')
                ->required(),
            static::placementsField('Articoli', 'Aggiungi un articolo'),
        ];
    }

    public static function formLayoutSchema(): ?Form
    {
        $main = (new Card)->components([
            SectionTitle::make('Selezione')
                ->tooltip('Il nome serve a te. L\'url pubblico nasce dal nome alla creazione e non cambia più: è quello con cui il sito trova la selezione, per esempio «home-prodotti».')
                ->columnSpan(12),
            static::getInput('name')->columnSpan(12),
        ])->columns(12)->columnSpan(12);

        $items = (new Card)->components([
            SectionTitle::make('Articoli')
                ->tooltip('Un articolo con tutte le sue varianti, o una sola variante. Trascinali nell\'ordine in cui il sito li deve mostrare. Gli articoli nascosti al sito restano in elenco ma non compaiono finché non tornano visibili. La foto di contesto è facoltativa: la usano i blocchi del sito che la prevedono.')
                ->columnSpan(12),
            static::getInput(self::PLACEMENTS_INPUT)->columnSpan(12),
        ])->columns(12)->columnSpan(12);

        $block = (new Card)->components([
            SectionTitle::make('Blocco nel sito')
                ->tooltip('Titolo, sottotitolo, testo e immagine del blocco che mostra la selezione, se il sito li prevede. Lasciati vuoti, il blocco usa i suoi.')
                ->columnSpan(12),
            static::getInput('title')->columnSpan(6),
            static::getInput('subtitle')->columnSpan(6),
            static::getInput('text')->columnSpan(12),
            static::getInput('image')->columnSpan(12),
        ])->columns(12)->columnSpan(12);

        $side = (new Card)->components([
            SectionTitle::make('Pubblicazione')
                ->tooltip('«Nell\'ordine dato» segue l\'elenco; le altre regole riordinano gli stessi articoli.')
                ->columnSpan(12),
            static::getInput('visible')->columnSpan(12),
            static::getInput('sort_mode')->columnSpan(12),
        ])->columns(12)->columnSpan(12);

        return (new Form)->components([
            (new Container)->components([$main, $items, $block])->columns(12)->columnSpan(8),
            (new Container)->components([$side])->columns(12)->columnSpan(4),
        ])->columns(12);
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
                'list' => 'Selezioni',
                'create' => 'Nuova selezione',
                'edit' => 'Modifica selezione',
            ]);
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
        return parent::navigationSchema()
            ->inSection('catalogo')
            ->title('Selezioni')
            ->order(44)
            ->access(Gestionale::area('catalogo'));
    }

    /**
     * Nome obbligatorio anche lato server; l'url pubblico nasce dal nome alla
     * creazione e poi non si tocca; stato e ordine solo fra quelli noti.
     */
    public static function mutateRequestValues(
        array $values,
        string $action,
        string $context = 'backend',
        ?array $oldValues = null
    ): array {
        $values = static::requireName($values, $action, 'selection.name_required');

        if ($action === 'store') {
            $values['slug'] = Slug::make((string) ($values['name'] ?? ''), ProductSelection::$table);
        } else {
            unset($values['slug']);
        }

        if ($action === 'store' || array_key_exists('sort_mode', $values)) {
            $values['sort_mode'] = Placements::selectionSortMode($values['sort_mode'] ?? '');
        }

        if ($action === 'store' || array_key_exists('visible', $values)) {
            $values['visible'] = ($values['visible'] ?? 'true') === 'false' ? 'false' : 'true';
        }

        return $values;
    }

    public static function mutateFormValues(array $values, string $mode, string $context = 'backend'): array
    {
        return static::placementFormValues($values);
    }

    // Il backend scrive con una query diretta: la copia della richiesta va buttata.
    public static function afterStore(object $result, array $values = []): void
    {
        RequestMemo::forget(ProductSelection::MEMO_GROUP);
        parent::afterStore($result, $values);
    }

    public static function afterUpdate(int|string $id, object $result, array $values = []): void
    {
        RequestMemo::forget(ProductSelection::MEMO_GROUP);
        parent::afterUpdate($id, $result, $values);
    }

    public static function afterDelete(int|string $id, object $result, array $values = []): void
    {
        Placements::dropContext(Placements::SELECTION, (int) $id);
        RequestMemo::forget(ProductSelection::MEMO_GROUP);
        parent::afterDelete($id, $result, $values);
    }
}
