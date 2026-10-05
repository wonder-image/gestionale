<?php

namespace Wonder\Plugin\Gestionale\Support\Promotions;

use Wonder\App\ResourceSchema\FormField;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\Tag;
use Wonder\Plugin\Gestionale\Support\Catalog\CategoryTree;
use Wonder\Sql\Transaction;

/**
 * Il selettore dei prodotti, uguale per campagne e coupon: «Tutto il
 * catalogo» oppure «Solo la selezione» di categorie, tag, marchi e articoli,
 * più gli articoli da escludere (che battono tutto).
 *
 * Sta in un trait perché il modulo è di chi lo usa, ma i campi, la lettura
 * della richiesta e la scrittura dei ponti sono una cosa sola. La forma
 * dell'array è quella di `ScopeMatcher`.
 */
trait ScopeForm
{
    /**
     * I campi del selettore: categorie, tag, marchi e articoli scelti si vedono
     * solo con «Solo la selezione»; gli esclusi sempre, perché «tutto il
     * catalogo meno questi» è una scelta legittima.
     *
     * @return list<\Wonder\App\ResourceSchema\Input>
     */
    public static function scopeFields(): array
    {
        return [
            FormField::key('applies_to_all')
                ->select(['true' => 'Tutto il catalogo', 'false' => 'Solo la selezione'])
                ->value('true')
                ->label('Si applica a')
                ->required(),
            FormField::key('categories')
                ->selectSearch(static::scopeCategoryOptions(), true)
                ->label('Categorie')
                ->visibleWhen('applies_to_all', 'false'),
            FormField::key('tags')
                ->selectSearch(static::scopeOptions(Tag::class), true)
                ->label('Tag')
                ->visibleWhen('applies_to_all', 'false'),
            FormField::key('brands')
                ->selectSearch(static::scopeOptions(Brand::class), true)
                ->label('Marchi')
                ->visibleWhen('applies_to_all', 'false'),
            FormField::key('models')
                ->selectSearch(static::scopeOptions(ProductModel::class), true)
                ->label('Articoli')
                ->visibleWhen('applies_to_all', 'false'),
            FormField::key('excluded_models')
                ->selectSearch(static::scopeOptions(ProductModel::class), true)
                ->label('Articoli esclusi'),
        ];
    }

    /**
     * Il selettore scritto nella richiesta: id interi positivi, senza doppioni.
     * Un valore che non è una lista, o un tipo storto, vale «nessuno».
     *
     * @param array<string, mixed> $post
     * @return array{all: bool, categories: list<int>, tags: list<int>, brands: list<int>, models: list<int>, excluded_models: list<int>}
     */
    public static function readScope(array $post): array
    {
        return [
            'all' => ($post['applies_to_all'] ?? '') === 'true',
            'categories' => static::scopeIds($post['categories'] ?? null),
            'tags' => static::scopeIds($post['tags'] ?? null),
            'brands' => static::scopeIds($post['brands'] ?? null),
            'models' => static::scopeIds($post['models'] ?? null),
            'excluded_models' => static::scopeIds($post['excluded_models'] ?? null),
        ];
    }

    /**
     * Riscrive i ponti del proprietario: via quelli vecchi, dentro quelli
     * nuovi, in una sola transazione (a metà strada non resta niente).
     *
     * @param array{all?: bool, categories?: list<int>, tags?: list<int>, brands?: list<int>, models?: list<int>, excluded_models?: list<int>} $scope
     */
    public static function saveScope(string $owner, int $ownerId, array $scope): void
    {
        $map = ProductScope::OWNERS[$owner] ?? throw new \InvalidArgumentException('Proprietario del selettore sconosciuto: '.$owner);

        Transaction::run(static function () use ($map, $ownerId, $scope): void {
            foreach (['categories', 'tags', 'brands', 'product_models'] as $bridge) {
                foreach (static::rowsOf($map[$bridge], [$map['key'] => $ownerId]) as $row) {
                    $map[$bridge]::delete((int) $row['id']);
                }
            }

            $write = static function (string $bridge, string $column, array $ids, array $extra = []) use ($map, $ownerId): void {
                foreach (array_values(array_unique($ids)) as $id) {
                    $map[$bridge]::create([$map['key'] => $ownerId, $column => (int) $id] + $extra);
                }
            };

            $write('categories', 'category_id', (array) ($scope['categories'] ?? []));
            $write('tags', 'tag_id', (array) ($scope['tags'] ?? []));
            $write('brands', 'brand_id', (array) ($scope['brands'] ?? []));

            $excluded = (array) ($scope['excluded_models'] ?? []);
            // Incluso ed escluso insieme: vale l'esclusione, e il ponte è uno solo.
            $write('product_models', 'product_model_id', array_diff((array) ($scope['models'] ?? []), $excluded), ['is_excluded' => 'false']);
            $write('product_models', 'product_model_id', $excluded, ['is_excluded' => 'true']);
        });
    }

    /**
     * Il selettore salvato, nella forma dei campi del form (liste di stringhe).
     *
     * @return array<string, string|list<string>>
     */
    public static function loadScope(string $owner, int $ownerId): array
    {
        $scope = ProductScope::of($owner, $ownerId);
        $text = static fn (array $ids): array => array_map('strval', $ids);

        return [
            'applies_to_all' => $scope['all'] ? 'true' : 'false',
            'categories' => $text($scope['categories']),
            'tags' => $text($scope['tags']),
            'brands' => $text($scope['brands']),
            'models' => $text($scope['models']),
            'excluded_models' => $text($scope['excluded_models']),
        ];
    }

    /** @return list<int> */
    protected static function scopeIds(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $ids = [];

        foreach ($value as $item) {
            if (is_int($item) || (is_string($item) && ctype_digit($item))) {
                $id = (int) $item;

                if ($id > 0 && !in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }

    /** @return array<string, string> */
    protected static function scopeCategoryOptions(): array
    {
        $options = CategoryTree::options(static::rowsOf(Category::class, [], 'position'));
        unset($options['']);

        return $options;
    }

    /**
     * @param class-string $model
     * @return array<string, string>
     */
    protected static function scopeOptions(string $model): array
    {
        $options = [];

        foreach (static::rowsOf($model, [], 'name') as $row) {
            $options[(string) $row['id']] = (string) ($row['name'] ?? '');
        }

        return $options;
    }
}
