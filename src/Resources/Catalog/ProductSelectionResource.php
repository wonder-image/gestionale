<?php

namespace Wonder\Plugin\Gestionale\Resources\Catalog;

use Throwable;
use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\RichText;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\App\ResourceSchema\Input;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductSelection;
use Wonder\Plugin\Gestionale\Models\Catalog\Tag;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Catalog\CategoryTree;
use Wonder\Plugin\Gestionale\Support\Catalog\Placements;
use Wonder\Plugin\Gestionale\Support\Catalog\PlacementsForm;
use Wonder\Plugin\Gestionale\Support\Catalog\RelatedProductsEditor;
use Wonder\Plugin\Gestionale\Support\Catalog\SelectionBlocks;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\RequestMemo;

/**
 * «Selezioni»: elenchi di articoli per i blocchi del sito, come «In evidenza»
 * della home o il prodotto del mese.
 *
 * Il sito ne prende una per url pubblico (`home-prodotti`), che nasce dal nome
 * e non cambia più: rinominare la selezione non rompe la home. Gli articoli
 * sono scelti a mano (`gst_product_placements`, {@see Placements}) o da una
 * fonte del catalogo (`source`, fino a `max_items`, senza gli articoli
 * esclusi). Il blocco dice al sito
 * come mostrarla ({@see SelectionBlocks}): titolo, sottotitolo, testo,
 * immagine e sfondo sono colonne, i campi in più del blocco stanno in `block_meta` e nel
 * form si chiamano `block_meta_<chiave>`. Esiste solo con la vendita online,
 * come il sito che la mostra.
 *
 * Si crea in locale e arriva con il deploy ({@see ProductSelection::syncSchema()}):
 * fuori dal locale la scheda è in sola lettura, tranne testi e foto del
 * blocco, articoli scelti a mano ed esclusi.
 */
final class ProductSelectionResource extends GestionaleResource
{
    use PlacementsForm {
        getInput as placementsInput;
    }

    public const PLACEMENT_CONTEXT = Placements::SELECTION;

    public const EXCLUDED_INPUT = 'excluded_model_ids';

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
            'background' => 'Sfondo',
            'sort_mode' => 'Ordine',
            'visible' => 'Stato',
            'placements' => 'Articoli',
            'block' => 'Blocco',
            'source' => 'Articoli da',
            'source_category_slugs' => 'Categorie',
            'source_tag_slugs' => 'Tag',
            'source_brand_slugs' => 'Marchi',
            self::EXCLUDED_INPUT => 'Articoli esclusi',
            'max_items' => 'Quanti articoli',
        ];
    }

    public static function formSchema(): array
    {
        $fields = [
            FormField::key('name')->text()->label('Nome')->required(),
            FormField::key('block')
                ->select(SelectionBlocks::options())
                ->value(SelectionBlocks::DEFAULT)
                ->label('Blocco')
                ->required(),
            static::forBlocks(FormField::key('title')->text()->label('Titolo'), 'title'),
            static::forBlocks(FormField::key('subtitle')->text()->label('Sottotitolo'), 'subtitle'),
            static::forBlocks(FormField::key('text')->textarea('plus')->label('Testo'), 'text'),
            static::forBlocks(FormField::key('image')->fileDragDrop('image')->label('Immagine'), 'image'),
            static::forBlocks(FormField::key('background')->fileDragDrop('image')->label('Sfondo'), 'background'),
            FormField::key('source')
                ->select(Placements::SELECTION_SOURCES)
                ->value('manual')
                ->label('Articoli da')
                ->required(),
            FormField::key('source_category_slugs')
                ->selectSearch(static::categoryOptions(), true)
                ->label('Categorie')
                ->visibleWhen('source', 'category'),
            FormField::key('source_tag_slugs')
                ->selectSearch(static::tagOptions(), true)
                ->label('Tag')
                ->visibleWhen('source', 'tag'),
            FormField::key('source_brand_slugs')
                ->selectSearch(static::brandOptions(), true)
                ->label('Marchi')
                ->visibleWhen('source', 'brand'),
            // Nella scheda lo disegna l'editor degli articoli ({@see excludedEditor()}).
            FormField::key(self::EXCLUDED_INPUT)->hidden(),
            FormField::key('max_items')
                ->number()
                ->decimal(0)
                ->value((string) Placements::SELECTION_MAX_ITEMS)
                ->label('Quanti articoli')
                ->hiddenWhen('source', 'manual'),
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

        foreach (SelectionBlocks::allMetaFields() as $key => $meta) {
            $fields[] = static::forBlocks(static::metaInput($key, $meta), $key);
        }

        return $fields;
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
                ->tooltip('«Scelti a mano»: un articolo con tutte le sue varianti, o una sola variante, trascinati nell\'ordine in cui il sito li deve mostrare; gli articoli nascosti al sito restano in elenco ma non compaiono finché non tornano visibili. Le altre fonti prendono dal catalogo gli articoli in vendita (tutti, quelli in offerta, o quelli di almeno una delle categorie, dei tag o dei marchi scelti), tolti gli esclusi, fino al numero indicato e nell\'ordine scelto. «Nell\'ordine dato» segue l\'elenco scelto a mano, o l\'ordine del catalogo, o della categoria o del tag quando ne scegli uno solo.')
                ->columnSpan(12),
            static::getInput('source')->columnSpan(4),
            static::getInput('sort_mode')->columnSpan(4),
            static::getInput('max_items')->columnSpan(4),
            static::getInput('source_category_slugs')->columnSpan(12),
            static::getInput('source_tag_slugs')->columnSpan(12),
            static::getInput('source_brand_slugs')->columnSpan(12),
            (new Container)->components([
                static::getInput(self::EXCLUDED_INPUT)->columnSpan(12),
            ])->columns(12)->columnSpan(12)->hiddenWhen('source', 'manual'),
            // L'elenco resta salvato anche con un'altra fonte: tornando a mano riappare.
            (new Container)->components([
                static::getInput(self::PLACEMENTS_INPUT)->columnSpan(12),
            ])->columns(12)->columnSpan(12)->visibleWhen('source', 'manual'),
        ])->columns(12)->columnSpan(12);

        $blockInputs = [];

        foreach (SelectionBlocks::allMetaFields() as $key => $meta) {
            $input = static::getInput(SelectionBlocks::META_INPUT_PREFIX.$key)->columnSpan($meta['type'] === 'textarea' || $meta['when'] !== [] ? 12 : 6);

            // La regola del blocco sta sull'input, quella del campo collegato sul contenitore.
            foreach ($meta['when'] as $whenKey => $values) {
                $input = (new Container)->components([$input])->columns(12)->columnSpan(12)
                    ->visibleWhen(SelectionBlocks::META_INPUT_PREFIX.$whenKey, $values);
            }

            $blockInputs[] = $input;
        }

        $block = (new Card)->components([
            SectionTitle::make('Blocco nel sito')
                ->tooltip('Il blocco dice al sito come mostrare la selezione; i campi cambiano con il blocco. Lasciati vuoti, il blocco usa i suoi testi.')
                ->columnSpan(12),
            static::getInput('block')->columnSpan(12),
            static::getInput('title')->columnSpan(6),
            static::getInput('subtitle')->columnSpan(6),
            static::getInput('text')->columnSpan(12),
            static::getInput('image')->columnSpan(6),
            static::getInput('background')->columnSpan(6),
            ...$blockInputs,
        ])->columns(12)->columnSpan(12);

        $side = (new Card)->components([
            SectionTitle::make('Pubblicazione')->columnSpan(12),
            static::getInput('visible')->columnSpan(12),
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
     * creazione e poi non si tocca; stato, ordine, blocco e fonte solo fra
     * quelli noti. Una fonte con riferimenti vuole almeno una categoria, un
     * tag o un marchio, salvati come JSON di slug, e gli esclusi arrivano
     * dall'editor come righe; i campi `block_meta_*` diventano il JSON `block_meta`, con le sole
     * chiavi del blocco scelto.
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

        if ($action === 'store' || array_key_exists('source', $values)) {
            $values['source'] = Placements::selectionSource($values['source'] ?? '');

            foreach (Placements::SELECTION_SOURCE_REFS as $source => $column) {
                $slugs = $values['source'] === $source ? Placements::selectionSlugs($values[$column] ?? null) : [];

                if ($values['source'] === $source && $slugs === []) {
                    throw UserError::make(match ($source) {
                        'category' => 'selection.source_category_required',
                        'tag' => 'selection.source_tag_required',
                        default => 'selection.source_brand_required',
                    });
                }

                $values[$column] = json_encode($slugs, JSON_UNESCAPED_UNICODE);
            }
        }

        // Un elenco svuotato non manda niente: vuol dire nessun escluso. In
        // sola lettura la fonte non arriva, gli esclusi sì.
        if ($action === 'store' || array_key_exists('source', $values) || static::isReadonly()) {
            $rows = is_array($values[self::EXCLUDED_INPUT] ?? null) ? $values[self::EXCLUDED_INPUT] : [];
            $values[self::EXCLUDED_INPUT] = json_encode(Placements::selectionIds(array_map(
                static fn (mixed $row): mixed => is_array($row) ? ($row['related_model_id'] ?? null) : $row,
                $rows
            )));
        }

        if ($action === 'store' || array_key_exists('max_items', $values)) {
            $values['max_items'] = Placements::selectionMaxItems($values['max_items'] ?? null);
        }

        if ($action === 'store' || array_key_exists('block', $values)) {
            $values['block'] = SelectionBlocks::normalize($values['block'] ?? '');
            $meta = [];

            foreach (array_keys(SelectionBlocks::metaFields($values['block'])) as $key) {
                $meta[$key] = $values[SelectionBlocks::META_INPUT_PREFIX.$key] ?? '';
            }

            $values['block_meta'] = json_encode((object) SelectionBlocks::cleanMeta($values['block'], $meta), JSON_UNESCAPED_UNICODE);
        }

        foreach (array_keys($values) as $key) {
            if (str_starts_with((string) $key, SelectionBlocks::META_INPUT_PREFIX)) {
                unset($values[$key]);
            }
        }

        return $values;
    }

    public static function mutateFormValues(array $values, string $mode, string $context = 'backend'): array
    {
        $values = static::placementFormValues($values);
        $meta = SelectionBlocks::decodeMeta($values['block_meta'] ?? null);

        foreach (SelectionBlocks::allMetaFields() as $key => $field) {
            $value = is_scalar($meta[$key] ?? null) ? (string) $meta[$key] : '';
            $values[SelectionBlocks::META_INPUT_PREFIX.$key] ??= $value !== '' ? $value : $field['default'];
        }

        foreach (array_keys(Placements::SELECTION_SOURCE_REFS) as $source) {
            $values[Placements::SELECTION_SOURCE_REFS[$source]] = Placements::selectionRefSlugs($values, $source);
        }

        return $values;
    }

    /** Fuori dal locale: testi e foto del blocco, articoli scelti a mano ed esclusi. */
    public static function editableWhenReadonly(): array
    {
        return [...ProductSelection::SITE_COLUMNS, self::PLACEMENTS_INPUT];
    }

    public static function readonlyNotice(): string
    {
        return 'La selezione si crea e si imposta in locale e arriva con il deploy: qui cambi testi e foto del blocco e gli articoli.';
    }

    public static function getInput(string $key): object
    {
        return $key === self::EXCLUDED_INPUT ? static::excludedEditor() : static::placementsInput($key);
    }

    /** «Cerca un articolo» e gli articoli esclusi della selezione aperta. */
    protected static function excludedEditor(): RichText
    {
        $id = (int) (static::currentId() ?? 0);

        try {
            $row = $id > 0 ? (static::rowsOf(ProductSelection::class, ['id' => $id])[0] ?? []) : [];
            $lines = RelatedProductsEditor::lines(array_map(
                static fn (int $modelId): array => ['id' => '', 'related_model_id' => $modelId],
                Placements::selectionExcluded($row)
            ));
        } catch (Throwable) {
            $lines = [];
        }

        return RichText::make(
            '<div class="row">'
            .RelatedProductsEditor::renderLines(self::EXCLUDED_INPUT, (string) static::getLabel(self::EXCLUDED_INPUT), $lines, 0, RelatedProductsEditor::searchUrl())
            .'</div>'
            .RelatedProductsEditor::scripts()
        );
    }

    /** Il campo resta solo per i blocchi che lo usano; se lo usano tutti, niente regola. */
    private static function forBlocks(Input $input, string $field): Input
    {
        $blocks = SelectionBlocks::blocksWith($field);

        if (count($blocks) === count(SelectionBlocks::all())) {
            return $input;
        }

        return $blocks === []
            ? $input->hiddenWhen('block', array_keys(SelectionBlocks::all()))
            : $input->visibleWhen('block', $blocks);
    }

    /** @param array<string, mixed> $meta */
    private static function metaInput(string $key, array $meta): Input
    {
        $field = FormField::key(SelectionBlocks::META_INPUT_PREFIX.$key);
        $field = match ($meta['type']) {
            'textarea' => $field->textarea(),
            'url' => $field->url(),
            'select' => $field->select(($meta['default'] !== '' ? [] : ['' => '']) + $meta['options']),
            'category' => $field->selectSearch(['' => ''] + static::categoryOptions()),
            'tag' => $field->selectSearch(['' => ''] + static::tagOptions()),
            'brand' => $field->selectSearch(['' => ''] + static::brandOptions()),
            'product' => $field->selectSearch(['' => ''] + static::productOptions()),
            default => $field->text(),
        };

        if ($meta['default'] !== '') {
            $field = $field->value($meta['default']);
        }

        return $field->label((string) $meta['label']);
    }

    /** @return array<string, string> le categorie indentate come nella scheda articolo, per slug */
    private static function categoryOptions(): array
    {
        $rows = static::rowsOf(Category::class, [], 'position');
        $slugs = array_column($rows, 'slug', 'id');
        $options = [];

        foreach (CategoryTree::options($rows) as $id => $label) {
            if (($slugs[$id] ?? '') !== '') {
                $options[(string) $slugs[$id]] = $label;
            }
        }

        return $options;
    }

    /** @return array<string, string> */
    private static function brandOptions(): array
    {
        $options = [];

        foreach (static::rowsOf(Brand::class, [], 'name') as $row) {
            $options[(string) $row['slug']] = html_entity_decode((string) ($row['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $options;
    }

    /** @return array<string, string> gli articoli per slug */
    private static function productOptions(): array
    {
        $options = [];

        foreach (static::rowsOf(ProductModel::class, [], 'name') as $row) {
            $options[(string) $row['slug']] = html_entity_decode((string) ($row['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $options;
    }

    /** @return array<string, string> */
    private static function tagOptions(): array
    {
        $options = [];

        foreach (static::rowsOf(Tag::class, [], 'name') as $row) {
            $options[(string) $row['slug']] = html_entity_decode((string) ($row['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $options;
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
