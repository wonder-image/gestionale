<?php

namespace Wonder\Plugin\Gestionale\Support\Promotions;

use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelCategory;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelTag;
use Wonder\Plugin\Gestionale\Models\Promotions\DiscountCampaign;
use Wonder\Plugin\Gestionale\Models\Promotions\DiscountCampaignBrand;
use Wonder\Plugin\Gestionale\Models\Promotions\DiscountCampaignCategory;
use Wonder\Plugin\Gestionale\Models\Promotions\DiscountCampaignProductModel;
use Wonder\Plugin\Gestionale\Models\Promotions\DiscountCampaignTag;

/**
 * Legge dal database «a chi si applica» una campagna (o un coupon) e i fatti
 * di un prodotto, nella forma che `ScopeMatcher` confronta.
 *
 * Una campagna e un coupon scelgono i prodotti allo stesso modo, quindi la
 * lettura è una sola: `OWNERS` dice, per ciascun proprietario, quale Model
 * tiene la testata e quali i ponti. Il coupon si aggiunge qui, senza
 * duplicare niente.
 */
final class ProductScope
{
    /** @var array<string, array{model: class-string, key: string, categories: class-string, tags: class-string, brands: class-string, product_models: class-string}> */
    private const OWNERS = [
        'campaign' => [
            'model' => DiscountCampaign::class,
            'key' => 'discount_campaign_id',
            'categories' => DiscountCampaignCategory::class,
            'tags' => DiscountCampaignTag::class,
            'brands' => DiscountCampaignBrand::class,
            'product_models' => DiscountCampaignProductModel::class,
        ],
    ];

    /**
     * Il selettore di una campagna o di un coupon.
     *
     * @return array{all: bool, categories: list<int>, tags: list<int>, brands: list<int>, models: list<int>, excluded_models: list<int>}
     */
    public static function of(string $owner, int $ownerId): array
    {
        $map = self::OWNERS[$owner] ?? throw new \InvalidArgumentException('Proprietario del selettore sconosciuto: '.$owner);
        $header = $map['model']::findById($ownerId);
        $where = [$map['key'] => $ownerId];

        $models = [];
        $excluded = [];

        foreach (self::rows($map['product_models']::find($where)) as $row) {
            if (($row['is_excluded'] ?? 'false') === 'true') {
                $excluded[] = (int) $row['product_model_id'];
            } else {
                $models[] = (int) $row['product_model_id'];
            }
        }

        return [
            'all' => is_array($header) && ($header['applies_to_all'] ?? 'false') === 'true',
            'categories' => self::column($map['categories']::find($where), 'category_id'),
            'tags' => self::column($map['tags']::find($where), 'tag_id'),
            'brands' => self::column($map['brands']::find($where), 'brand_id'),
            'models' => $models,
            'excluded_models' => $excluded,
        ];
    }

    /**
     * I fatti di un prodotto: categorie del suo articolo **con gli antenati**,
     * tag, marchio e articolo.
     *
     * @return array{categories: list<int>, tags: list<int>, brand_id: int, model_id: int}
     */
    public static function facts(int $productId): array
    {
        $product = Product::findById($productId);
        $modelId = is_array($product) ? (int) ($product['product_model_id'] ?? 0) : 0;
        $model = $modelId > 0 ? ProductModel::findById($modelId) : null;

        return [
            'categories' => self::withAncestors(
                self::column(ProductModelCategory::find(['product_model_id' => $modelId]), 'category_id'),
                self::parents()
            ),
            'tags' => self::column(ProductModelTag::find(['product_model_id' => $modelId]), 'tag_id'),
            'brand_id' => is_array($model) ? (int) ($model['brand_id'] ?? 0) : 0,
            'model_id' => $modelId,
        ];
    }

    /**
     * Tutti i prodotti attivi con i loro fatti, letti a blocchi: serve
     * all'anteprima e alle sovrapposizioni, che altrimenti farebbero una
     * serie di domande per ogni prodotto.
     *
     * @return array<int, array{name: string, price: float, sale_price: float, facts: array{categories: list<int>, tags: list<int>, brand_id: int, model_id: int}}>
     */
    public static function catalog(): array
    {
        $parents = self::parents();
        $brands = [];

        foreach (self::rows(ProductModel::all()) as $row) {
            $brands[(int) $row['id']] = (int) ($row['brand_id'] ?? 0);
        }

        $categories = self::groupBy(self::rows(ProductModelCategory::all()), 'product_model_id', 'category_id');
        $tags = self::groupBy(self::rows(ProductModelTag::all()), 'product_model_id', 'tag_id');
        $catalog = [];

        foreach (self::rows(Product::find(['active' => 'true'])) as $row) {
            $modelId = (int) ($row['product_model_id'] ?? 0);

            $catalog[(int) $row['id']] = [
                'name' => (string) ($row['name'] ?? ''),
                'price' => (float) ($row['price'] ?? 0),
                'sale_price' => (float) ($row['sale_price'] ?? 0),
                'facts' => [
                    'categories' => self::withAncestors($categories[$modelId] ?? [], $parents),
                    'tags' => $tags[$modelId] ?? [],
                    'brand_id' => $brands[$modelId] ?? 0,
                    'model_id' => $modelId,
                ],
            ];
        }

        return $catalog;
    }

    /**
     * Le righe di una `find()`, sempre come lista: con una riga sola il
     * framework può restituire la riga invece della lista che la contiene.
     *
     * @return list<array<string, mixed>>
     */
    private static function rows(mixed $found): array
    {
        if (!is_array($found) || $found === []) {
            return [];
        }

        return array_key_exists('id', $found) ? [$found] : array_values(array_filter($found, 'is_array'));
    }

    /** @return list<int> */
    private static function column(mixed $found, string $column): array
    {
        return array_values(array_unique(array_map(
            static fn (array $row): int => (int) ($row[$column] ?? 0),
            self::rows($found)
        )));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<int, list<int>>
     */
    private static function groupBy(array $rows, string $by, string $column): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(int) $row[$by]][] = (int) $row[$column];
        }

        return $grouped;
    }

    /** @return array<int, int> categoria → padre (0 se è in cima) */
    private static function parents(): array
    {
        $parents = [];

        foreach (self::rows(Category::all()) as $row) {
            $parents[(int) $row['id']] = (int) ($row['parent_id'] ?? 0);
        }

        return $parents;
    }

    /**
     * @param list<int> $ids
     * @param array<int, int> $parents
     * @return list<int>
     */
    private static function withAncestors(array $ids, array $parents): array
    {
        $all = [];

        foreach ($ids as $id) {
            // Il tetto sui passi ferma un albero che si morde la coda.
            for ($step = 0; $id > 0 && $step < 50 && !in_array($id, $all, true); $step++) {
                $all[] = $id;
                $id = $parents[$id] ?? 0;
            }
        }

        return $all;
    }
}
