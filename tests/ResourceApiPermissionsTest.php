<?php
/** php tests/ResourceApiPermissionsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Api\Support\ResourceApiAccess;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Resources\Catalog\AttributeResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\AttributeValueResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\BrandResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\CategoryResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\CustomizationResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\PackageResource;
use Wonder\Plugin\Gestionale\Resources\Promotions\CouponResource;
use Wonder\Plugin\Gestionale\Resources\Promotions\DiscountCampaignResource;
use Wonder\Plugin\Gestionale\Resources\Shipping\CarrierResource;
use Wonder\Plugin\Gestionale\Resources\Shipping\ShippingMethodResource;
use Wonder\Plugin\Gestionale\Resources\Shipping\ShippingZoneResource;
use Wonder\Plugin\Gestionale\Resources\Tax\TaxCategoryResource;

/*
 * Ogni API di Resource del modulo dichiara chi la può chiamare: una rotta
 * accesa senza `permissionSchema()->api()` risponde 403 a tutti.
 */

$quickCreate = [
    BrandResource::class, AttributeResource::class, AttributeValueResource::class, CategoryResource::class,
    CustomizationResource::class, PackageResource::class, TaxCategoryResource::class, ShippingZoneResource::class,
];
$spente = [CouponResource::class, DiscountCampaignResource::class, CarrierResource::class, ShippingMethodResource::class];

$resources = [];

foreach (glob(__DIR__ . '/../src/Resources/*/*Resource.php') as $file) {
    $class = 'Wonder\\Plugin\\Gestionale\\Resources\\' . basename(dirname($file)) . '\\' . basename($file, '.php');

    if (class_exists($class) && !(new ReflectionClass($class))->isAbstract()) {
        $resources[] = $class;
    }
}

// Tutte le funzionalità accese: spenta, una Resource chiude le API e qui non si vedrebbero.
(new ReflectionProperty(Gestionale::class, 'features'))->setValue(null, array_fill_keys(
    array_filter(array_map(static fn (string $r): string => property_exists($r, 'feature') ? $r::$feature : '', $resources)),
    true
));

$attive = static function (string $resource): array {
    $schema = $resource::apiSchema()->toArray();

    return ($schema['enabled'] ?? false) === true
        ? array_keys(array_filter((array) ($schema['routes'] ?? [])))
        : [];
};

check('le Resource del modulo sono state trovate', fn () => count($resources) > 40);

check('ogni rotta API accesa dichiara le sue authority', function () use ($resources, $attive) {
    $senza = [];

    foreach ($resources as $resource) {
        foreach ($attive($resource) as $azione) {
            if (ResourceApiAccess::declared($resource, $azione) === []) {
                $senza[] = $resource . '::' . $azione;
            }
        }
    }

    if ($senza !== []) {
        echo '    senza: ' . implode(', ', $senza) . "\n";
    }

    return $senza === [];
});

check('api_internal_user solo sullo store degli 8 del quick-create', function () use ($resources, $attive, $quickCreate) {
    $errori = [];

    foreach ($resources as $resource) {
        $atteso = in_array($resource, $quickCreate, true) ? ['store'] : [];
        $con = array_values(array_filter(
            $attive($resource),
            static fn (string $azione): bool => in_array('api_internal_user', ResourceApiAccess::declared($resource, $azione), true)
        ));

        if ($con !== $atteso) {
            $errori[] = $resource . ' ' . json_encode($con);
        }
    }

    if ($errori !== []) {
        echo '    ' . implode(', ', $errori) . "\n";
    }

    return $errori === [];
});

check('nessuna Resource accetta il token di pagina', function () use ($resources) {
    return array_values(array_filter($resources, static fn (string $r): bool => ResourceApiAccess::allowsPageToken($r))) === [];
});

check('coupon, campagne, corrieri e metodi di spedizione: API spente', function () use ($spente) {
    foreach ($spente as $resource) {
        if (($resource::apiSchema()->toArray()['enabled'] ?? null) !== false) {
            return false;
        }
    }

    return true;
});

summary();
