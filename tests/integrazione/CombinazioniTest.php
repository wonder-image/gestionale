<?php
/** php tests/integrazione/CombinazioniTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Attribute;
use Wonder\Plugin\Gestionale\Models\Catalog\AttributeValue;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\Generator;
use Wonder\Plugin\Gestionale\Support\Catalog\Skeleton;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

$conta = static function (string $model): int {
    $rows = $model::find(['deleted' => 'false']);

    if (!is_array($rows) || $rows === []) {
        return 0;
    }

    return isset($rows['id']) ? 1 : count(array_filter($rows, 'is_array'));
};

$prima = [$conta(ProductModel::class), $conta(ProductVariant::class), $conta(Product::class)];

/** Un attributo con i suoi valori. @return array{id: int, values: list<int>} */
$attributo = static function (string $name, string $level, string $type, array $labels): array {
    $creato = Attribute::create([
        'code' => Code::make(Attribute::class, Codes::ATTRIBUTE),
        'name' => $name,
        'slug' => Slug::make($name.'-'.uniqid()),
        'type' => $type,
        'level' => $level,
        'unit' => '',
        'group_name' => '',
        'is_filterable' => 'true',
        'is_visible' => 'true',
        'position' => 90,
    ]);
    $id = (int) ($creato->insert_id ?? 0);
    $values = [];
    $position = 1;

    foreach ($labels as $label) {
        $valore = AttributeValue::create([
            'attribute_id' => $id,
            'label' => $label,
            'color' => '',
            'position' => $position++,
        ]);
        $values[] = (int) ($valore->insert_id ?? 0);
    }

    return ['id' => $id, 'values' => $values];
};

try {
    Transaction::run(static function () use ($conta, $prima, $attributo): void {
        $colore = $attributo('Prova colore', 'variant', 'color', ['Blu', 'Rosso']);
        $taglia = $attributo('Prova taglia', 'product', 'select', ['S', 'M', 'L']);

        $modello = ProductModel::create([
            'code' => Code::make(ProductModel::class, Codes::MODEL),
            'name' => 'Prova combinazioni',
            'slug' => Slug::make('prova-combinazioni-'.uniqid()),
            'sku' => 'CMB-1',
            'unit' => 'pz',
            'type' => 'simple',
            'visible' => 'true',
            'visible_online' => 'true',
            'position' => 1,
        ]);
        $modelId = (int) ($modello->insert_id ?? 0);
        Skeleton::forModel($modelId, 'Prova combinazioni', 'CMB-1');

        // Un elenco solo: chi compila spunta i valori e basta, il livello lo
        // ritrova `chosenAxes()` leggendo l'attributo.
        $spunte = [
            'option_'.$colore['id'] => array_map('strval', $colore['values']),
            'option_'.$taglia['id'] => array_map('strval', $taglia['values']),
        ];

        // Il catalogo si legge una volta per richiesta, e la lettura è già
        // avvenuta all'avvio del sito: qui gli attributi nascono dopo.
        ProductModelResource::forgetCatalogCache();
        $genera = static function (array $spunte) use ($modelId): void {
            $scelte = ProductModelResource::chosenAxes($spunte);
            Generator::run($modelId, $scelte['variant'], $scelte['axes'], 'CMB-1');
        };
        $genera($spunte);

        check('due colori e tre taglie fanno due varianti', fn () =>
            ProductModelResource::variantCount($modelId) === 2
        );

        check('e sei prodotti', fn () =>
            ProductModelResource::productCount($modelId) === 6
        );

        check('lo scheletro è stato riusato, non lasciato in giro', function () use ($modelId) {
            // Le due varianti sono Blu e Rosso: nessuna porta ancora il nome
            // del modello, perché la prima ha preso il posto dello scheletro.
            $nomi = array_column(ProductModelResource::variants($modelId), 'name');
            sort($nomi);

            return $nomi === ['Blu', 'Rosso'];
        });

        check('ogni prodotto ha lo SKU proposto', function () use ($modelId) {
            $sku = array_column(ProductModelResource::products($modelId), 'sku');
            sort($sku);

            return $sku === [
                'CMB-1-BLU-L', 'CMB-1-BLU-M', 'CMB-1-BLU-S',
                'CMB-1-ROSSO-L', 'CMB-1-ROSSO-M', 'CMB-1-ROSSO-S',
            ];
        });

        check('ogni prodotto ha il nome della sua combinazione', function () use ($modelId) {
            $nomi = array_column(ProductModelResource::products($modelId), 'name');
            sort($nomi);

            return $nomi === [
                'Blu / L', 'Blu / M', 'Blu / S',
                'Rosso / L', 'Rosso / M', 'Rosso / S',
            ];
        });

        check('rifarlo non crea niente', function () use ($modelId, $spunte, $genera) {
            $genera($spunte);

            return ProductModelResource::variantCount($modelId) === 2
                && ProductModelResource::productCount($modelId) === 6;
        });

        check('una taglia in più fa solo i due prodotti che mancano', function () use ($modelId, $spunte, $taglia, $genera) {
            $nuovo = AttributeValue::create([
                'attribute_id' => $taglia['id'],
                'label' => 'XL',
                'color' => '',
                'position' => 4,
            ]);

            $spunte['option_'.$taglia['id']][] = (string) ($nuovo->insert_id ?? 0);
            // La cache degli attributi vale per richiesta: qui si rilegge.
            ProductModelResource::forgetCatalogCache();
            $genera($spunte);

            return ProductModelResource::variantCount($modelId) === 2
                && ProductModelResource::productCount($modelId) === 8;
        });

        check('due opzioni con pagina propria vengono rifiutate', function () use ($attributo, $colore) {
            $gusto = $attributo('Prova gusto', 'variant', 'select', ['Fragola']);
            ProductModelResource::forgetCatalogCache();

            try {
                ProductModelResource::chosenAxes([
                    'option_'.$colore['id'] => [(string) $colore['values'][0]],
                    'option_'.$gusto['id'] => [(string) $gusto['values'][0]],
                ]);
            } catch (UserError $errore) {
                return $errore->key() === 'product.one_page_option';
            }

            return false;
        });

        throw new Annulla();
    });
} catch (Annulla) {
}

check('dopo l\'annullamento il catalogo è come prima', fn () =>
    [$conta(ProductModel::class), $conta(ProductVariant::class), $conta(Product::class)] === $prima
);

summary();
