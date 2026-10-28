<?php
/** php tests/CatalogReorderTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Attribute;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\Tag;
use Wonder\Plugin\Gestionale\Resources\Catalog\AttributeResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\BrandResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\CategoryResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\TagResource;

$colonne = static function (string $model): array {
    $colonne = [];

    foreach ($model::tableSchema() as $column) {
        $colonne[(string) $column->name] = $column;
    }

    return $colonne;
};

check('marchi, categorie, tag e attributi hanno tutti una posizione', function () use ($colonne) {
    foreach ([Brand::class, Category::class, Tag::class, Attribute::class] as $model) {
        if (!isset($colonne($model)['position'])) {
            return false;
        }
    }

    return true;
});

check('le quattro liste si riordinano trascinando, ordinate per posizione', function () {
    foreach ([BrandResource::class, TagResource::class, AttributeResource::class] as $resource) {
        if ($resource::tableLayoutSchema()->get('reorder') !== ['column' => 'position', 'group' => null]
            || $resource::$orderColumn !== 'position'
            || $resource::$orderDirection !== 'ASC') {
            return false;
        }
    }

    return CategoryResource::$orderColumn === 'position';
});

check('le categorie si riordinano fra sorelle, e si filtrano per livello', function () {
    $layout = CategoryResource::tableLayoutSchema();
    $livello = array_values(array_filter(
        (array) $layout->get('custom_filters'),
        static fn ($filtro): bool => is_array($filtro) && ($filtro['column'] ?? '') === 'livello'
    ))[0] ?? null;

    return $layout->get('reorder') === ['column' => 'position', 'group' => 'parent_id']
        && $livello !== null
        && ($livello['array']['0'] ?? '') === 'In cima all\'albero';
});

check('il filtro «Livello»: in cima, dentro una o più categorie, o nessun filtro', fn () =>
    CategoryResource::levelCondition(['0']) === '((`parent_id` IS NULL OR `parent_id` = 0))'
    && CategoryResource::levelCondition(['3', '7', '3']) === '(`parent_id` IN (3, 7))'
    && CategoryResource::levelCondition(['0', '3']) === '((`parent_id` IS NULL OR `parent_id` = 0) OR `parent_id` IN (3))'
    && CategoryResource::levelCondition(['']) === ''
);

check('un tag nuovo nasce in fondo, e la modifica non tocca la posizione', function () {
    $valori = TagResource::mutateRequestValues(['name' => 'Notte', 'position' => 1], 'update', 'backend', ['id' => 1]);

    return !array_key_exists('position', $valori) && TagResource::$orderColumn === 'position';
});

summary();
