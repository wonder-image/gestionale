<?php
/** php tests/CatalogResourcesTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\Tag;
use Wonder\Plugin\Gestionale\Resources\Catalog\BrandResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\CategoryResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\TagResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

check('ogni pagina ha il suo model e il suo percorso', fn () =>
    BrandResource::$model === Brand::class
    && CategoryResource::$model === Category::class
    && TagResource::$model === Tag::class
    && BrandResource::path() === 'app/gestionale/marchi'
    && CategoryResource::path() === 'app/gestionale/categorie'
    && TagResource::path() === 'app/gestionale/tag'
);

check('il catalogo sta nella sua sezione, per tutti e due i ruoli', function () {
    foreach ([BrandResource::class, CategoryResource::class, TagResource::class] as $resource) {
        if (($resource::navigationSchema()->toArray()['section_key'] ?? '') !== 'catalogo') {
            return false;
        }

        foreach ($resource::permissionSchema()->toArray()['backend'] ?? [] as $authorities) {
            if ($authorities !== [] && $authorities !== ['admin', 'administrator']) {
                return false;
            }
        }
    }

    return true;
});

check('le pagine del catalogo sono sempre attive', fn () =>
    BrandResource::$feature === '' && CategoryResource::$feature === '' && TagResource::$feature === ''
);

check('lo slug non si scrive a mano', function () {
    foreach ([BrandResource::class, CategoryResource::class, TagResource::class] as $resource) {
        foreach ($resource::formSchema() as $field) {
            if ((string) $field->name === 'slug') {
                return false;
            }
        }
    }

    return true;
});

check('lo slug nasce dal nome alla creazione e poi non cambia', function () {
    $creazione = BrandResource::mutateRequestValues(['name' => 'Nike Italia'], 'store');
    $modifica = BrandResource::mutateRequestValues(['name' => 'Nike Italia'], 'update', 'backend', ['slug' => 'nike']);

    return ($creazione['slug'] ?? '') === 'nike-italia' && !isset($modifica['slug']);
});

check('il select del padre non contiene la categoria stessa', function () {
    $righe = [
        ['id' => 1, 'parent_id' => 0, 'name' => 'Abbigliamento', 'position' => 1],
        ['id' => 2, 'parent_id' => 1, 'name' => 'Magliette', 'position' => 1],
    ];

    $opzioni = CategoryResource::parentOptions($righe, 1);

    return !isset($opzioni['1'], $opzioni['2']) && isset($opzioni['']);
});

check('un ciclo viene rifiutato con parole comprensibili', function () {
    $righe = [
        ['id' => 1, 'parent_id' => 0, 'name' => 'Abbigliamento', 'position' => 1],
        ['id' => 2, 'parent_id' => 1, 'name' => 'Magliette', 'position' => 1],
    ];

    try {
        CategoryResource::assertNoLoop($righe, 1, 2);
    } catch (UserError $errore) {
        return str_contains(strtolower($errore->getMessage()), 'categoria');
    }

    return false;
});

summary();
