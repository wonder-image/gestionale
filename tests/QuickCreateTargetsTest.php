<?php
/** php tests/QuickCreateTargetsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Resources\Catalog\BrandResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\CategoryResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\PackageResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;
use Wonder\App\ResourceSchema\Inputs\InputHidden;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Resources\Tax\TaxCategoryResource;
use Wonder\Plugin\Gestionale\Support\Tax\TaxCategories;

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
    // Il padre serve: una categoria nata dalla scheda prodotto va sotto
    // quella scelta, non in cima.
    'categorie' => [CategoryResource::class, ['name', 'parent_id', 'visible']],
    'tipi fiscali' => [TaxCategoryResource::class, ['code', 'name']],
    'imballaggi' => [PackageResource::class, ['name', 'weight']],
] as $nome => [$resource, $campi]) {
    check("lo store dei {$nome} è aperto al \"+\", e solo quello", function () use ($store, $resource, $campi) {
        $schema = $store($resource);

        return $schema['aperto'] && $schema['campi'] === $campi && $schema['altro'] === [];
    });
}

$quickCreate = static function (): array {
    $con = [];

    foreach (ProductModelResource::formSchema() as $campo) {
        $config = (array) (($campo->get('context')['quick_create'] ?? []) ?: []);

        if ($config !== []) {
            $con[(string) $campo->name] = $config;
        }
    }

    return $con;
};

check('marchio, categorie e imballaggio hanno il "+"', function () use ($quickCreate) {
    // L'imballaggio c'è solo con le spedizioni accese.
    (new ReflectionProperty(Gestionale::class, 'features'))->setValue(null, ['shipping' => true]);

    try {
        $con = $quickCreate();
    } finally {
        (new ReflectionProperty(Gestionale::class, 'features'))->setValue(null, null);
    }

    return ($con['brand_id']['resource'] ?? '') === BrandResource::class
        && ($con['categories']['resource'] ?? '') === CategoryResource::class
        && ($con['package_id']['resource'] ?? '') === PackageResource::class;
});

check('una categoria nuova chiede nome e padre, e il bottone lo dice', function () use ($quickCreate) {
    $categorie = $quickCreate()['categories'] ?? [];

    return ($categorie['fields'] ?? null) === ['name', 'parent_id']
        && ($categorie['button'] ?? '') === 'Aggiungi categoria';
});

check('il tipo fiscale si vede sempre, con il "+"', function () use ($quickCreate) {
    $campo = null;

    foreach (ProductModelResource::formSchema() as $voce) {
        if ((string) $voce->name === 'tax_category_id') {
            $campo = $voce;
        }
    }

    // Anche con un tipo solo il campo resta un select (P58); senza nessun
    // tipo (o senza database) dice che vale l'aliquota di ripiego, e il «+»
    // crea il primo.
    if ($campo === null || $campo instanceof InputHidden) {
        return false;
    }

    return ($quickCreate()['tax_category_id']['resource'] ?? '') === TaxCategoryResource::class;
});

check('la categoria principale e i tag restano senza', function () use ($quickCreate) {
    $con = $quickCreate();

    // La principale la scrive la stella dell'albero; i tag si scrivono nel
    // campo stesso.
    return !isset($con['main_category']) && !isset($con['tags']);
});

summary();
