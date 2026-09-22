<?php
/** php tests/QuickCreateTargetsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Resources\Catalog\BrandResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\CategoryResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\PackageResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;
use Wonder\Plugin\Gestionale\Resources\Tax\TaxCategoryResource;

$store = static function (string $resource): array {
    $schema = $resource::apiSchema()->toArray();

    return [
        'aperto' => ($schema['enabled'] ?? false) === true && ($schema['routes']['store'] ?? false) === true,
        'campi' => (array) ($schema['fields']['store'] ?? []),
        'altro' => array_keys(array_filter(
            (array) ($schema['routes'] ?? []),
            static fn ($on, $route) => $on && $route !== 'store',
            ARRAY_FILTER_USE_BOTH
        )),
    ];
};

foreach ([
    'marchi' => [BrandResource::class, ['name']],
    'categorie' => [CategoryResource::class, ['name']],
    'tipi fiscali' => [TaxCategoryResource::class, ['code', 'name']],
    'imballaggi' => [PackageResource::class, ['name', 'weight']],
] as $nome => [$resource, $campi]) {
    check("lo store dei {$nome} è aperto al \"+\", e solo quello", function () use ($store, $resource, $campi) {
        $schema = $store($resource);

        return $schema['aperto'] && $schema['campi'] === $campi && $schema['altro'] === [];
    });
}

check('i quattro campi collegati hanno il "+"', function () {
    $con = [];

    foreach (ProductModelResource::formSchema() as $campo) {
        $config = (array) (($campo->get('context')['quick_create'] ?? []) ?: []);

        if ($config !== []) {
            $con[(string) $campo->name] = (string) ($config['resource'] ?? '');
        }
    }

    return ($con['brand_id'] ?? '') === BrandResource::class
        && ($con['main_category'] ?? '') === CategoryResource::class
        && ($con['tax_category_id'] ?? '') === TaxCategoryResource::class
        && ($con['package_id'] ?? '') === PackageResource::class;
});

check('tag e categorie multiple restano senza', function () {
    foreach (ProductModelResource::formSchema() as $campo) {
        if (in_array((string) $campo->name, ['tags', 'categories'], true)) {
            if ((($campo->get('context')['quick_create'] ?? []) ?: []) !== []) {
                return false;
            }
        }
    }

    return true;
});

summary();
