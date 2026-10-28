<?php
/** php tests/CatalogOrderTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Attribute;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\Tag;
use Wonder\Plugin\Gestionale\Resources\Catalog\AttributeResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\BrandResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\CatalogOrderResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\CategoryResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\TagResource;
use Wonder\Plugin\Gestionale\Support\Catalog\CatalogOrder;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

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

    return array_column(CatalogOrder::LISTS, 'model') === [Brand::class, Category::class, Tag::class, Attribute::class];
});

check('le posizioni nuove vanno da 1, nell\'ordine dato', fn () =>
    CatalogOrder::positions([3, 1, 2], ['2', '3', '1']) === [2 => 1, 3 => 2, 1 => 3]
);

check('un elenco cambiato nel frattempo non si salva', fn () =>
    // una riga in più, una in meno, un doppione, un id che non è un numero
    CatalogOrder::positions([1, 2], ['1', '2', '3']) === null
    && CatalogOrder::positions([1, 2, 3], ['1', '2']) === null
    && CatalogOrder::positions([1, 2], ['1', '1']) === null
    && CatalogOrder::positions([1, 2], ['1', 'x']) === null
    && CatalogOrder::positions([1, 2], [['1'], '2']) === null
    && CatalogOrder::positions([], []) === []
);

check('un elenco sconosciuto non si tocca', function () {
    try {
        CatalogOrder::save('prodotti', 0, ['1']);
    } catch (UserError $errore) {
        return $errore->key() === 'catalog_order.unknown_list' && !CatalogOrder::known('prodotti');
    }

    return false;
});

check('un tag nuovo nasce in fondo, e la modifica non tocca la posizione', function () {
    $valori = TagResource::mutateRequestValues(['name' => 'Notte', 'position' => 1], 'update', 'backend', ['id' => 1]);

    return !array_key_exists('position', $valori) && TagResource::$orderColumn === 'position';
});

check('i quattro elenchi hanno il bottone «Ordina» verso il loro elenco', function () {
    $attese = [
        BrandResource::class => 'marchi',
        CategoryResource::class => 'categorie',
        TagResource::class => 'tag',
        AttributeResource::class => 'attributi',
    ];

    foreach ($attese as $resource => $elenco) {
        $azioni = $resource::pageSchema()->get('actions')['list'] ?? [];
        $href = (string) ($azioni[0]['href'] ?? '');

        if (($azioni[0]['label'] ?? '') !== 'Ordina' || !str_ends_with($href, '?elenco='.$elenco)) {
            return false;
        }
    }

    return true;
});

check('le categorie si ordinano per livello, gli altri elenchi no', fn () =>
    str_ends_with(CatalogOrderResource::urlFor('categorie', 7), '?elenco=categorie&padre=7')
    && str_ends_with(CatalogOrderResource::urlFor('marchi', 7), '?elenco=marchi')
);

check('il titolo dice cosa si ordina', fn () =>
    CatalogOrderResource::heading('marchi') === 'Ordina i marchi'
    && CatalogOrderResource::heading('tag') === 'Ordina i tag'
    && CatalogOrderResource::heading('categorie') === 'Ordina le categorie in cima all\'albero'
);

summary();
