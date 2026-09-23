<?php
/** php tests/integrazione/ProductModelTest.php */
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
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductAttributes;
use Wonder\Plugin\Gestionale\Support\Catalog\Skeleton;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
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

try {
    Transaction::run(static function () use ($conta, $prima): void {
        $modello = ProductModel::create([
            'code' => Code::make(ProductModel::class, Codes::MODEL),
            'name' => 'Prova integrazione',
            'slug' => Slug::make('prova-integrazione-'.uniqid()),
            'sku' => 'INT-1',
            'unit' => 'pz',
            'type' => 'simple',
            'visible' => 'true',
            'visible_online' => 'true',
            'position' => 1,
        ]);

        $modelId = (int) ($modello->insert_id ?? 0);

        check('il modello nasce', fn () => $modelId > 0);

        $scheletro = Skeleton::forModel($modelId, 'Prova integrazione', 'INT-1');

        check('con il modello nascono una variante e un prodotto', fn () =>
            $scheletro['variant_id'] > 0 && $scheletro['product_id'] > 0
        );

        check('il prodotto punta al modello e alla sua variante', function () use ($scheletro, $modelId) {
            $prodotto = Product::find(['id' => $scheletro['product_id']], 1);

            return (int) ($prodotto['product_model_id'] ?? 0) === $modelId
                && (int) ($prodotto['product_variant_id'] ?? 0) === $scheletro['variant_id'];
        });

        check('il prodotto eredita lo SKU del modello', function () use ($scheletro) {
            $prodotto = Product::find(['id' => $scheletro['product_id']], 1);

            return ($prodotto['sku'] ?? '') === 'INT-1';
        });

        // La giacenza scritta alla creazione entra come carico iniziale
        // (P59); dopo, la stessa casella rettifica.
        ProductModelResource::forgetCatalogCache();
        ProductModelResource::saveExtras($modelId, ['has_variants' => 'false', 'product_stock' => '7'], 'INT-1', [], true);

        check('la giacenza scritta in creazione è un carico iniziale', fn () =>
            abs((float) (Levels::of($scheletro['product_id'])['quantity'] ?? 0) - 7.0) < 0.001
        );

        $postPrima = $_POST;
        $_POST = ['product_stock' => '-2'];
        $rifiutato = false;

        try {
            ProductModelResource::assertStockWritable(0, false);
        } catch (UserError) {
            $rifiutato = true;
        }

        $_POST = $postPrima;

        check('una giacenza negativa scritta a mano si rifiuta', fn () => $rifiutato);

        // Un attributo di modello, scritto e riletto.
        $attributo = Attribute::create([
            'code' => Code::make(Attribute::class, Codes::ATTRIBUTE),
            'name' => 'Prova materiale',
            'slug' => 'prova-materiale-'.uniqid(),
            'type' => 'text',
            'level' => 'model',
            'unit' => '',
            'group_name' => '',
            'is_filterable' => 'false',
            'is_visible' => 'true',
            'position' => 90,
        ]);
        $attributeId = (int) ($attributo->insert_id ?? 0);
        $attributi = [[
            'id' => $attributeId,
            'name' => 'Prova materiale',
            'type' => 'text',
            'unit' => '',
        ]];

        check('un attributo di modello si scrive', fn () =>
            ProductAttributes::save('model', $modelId, $attributi, [$attributeId => 'Cotone']) === 1
        );

        check('e si rilegge', function () use ($modelId, $attributeId) {
            $collegamenti = ProductAttributes::read('model', $modelId);

            return ($collegamenti[$attributeId]['value_text'] ?? '') === 'Cotone';
        });

        check('e si racconta con nome e valore', function () use ($modelId, $attributi) {
            $collegamenti = ProductAttributes::read('model', $modelId);

            return ProductAttributes::describe($attributi, $collegamenti, []) === ['Prova materiale' => 'Cotone'];
        });

        check('svuotarlo lo toglie', function () use ($modelId, $attributi, $attributeId) {
            ProductAttributes::save('model', $modelId, $attributi, [$attributeId => '']);

            return ProductAttributes::read('model', $modelId) === [];
        });

        check('un attributo a elenco scrive l\'id del valore', function () use ($modelId, $attributeId) {
            $valore = AttributeValue::create([
                'attribute_id' => $attributeId,
                'label' => 'Blu',
                'color' => '#1f4ed8',
                'position' => 1,
            ]);
            $valueId = (int) ($valore->insert_id ?? 0);
            $elenco = [['id' => $attributeId, 'name' => 'Prova materiale', 'type' => 'select', 'unit' => '']];

            ProductAttributes::save('model', $modelId, $elenco, [$attributeId => (string) $valueId]);
            $collegamenti = ProductAttributes::read('model', $modelId);

            return (int) ($collegamenti[$attributeId]['attribute_value_id'] ?? 0) === $valueId
                && ($collegamenti[$attributeId]['value_text'] ?? '') === '';
        });

        throw new Annulla();
    });
} catch (Annulla) {
}

check('dopo l\'annullamento il catalogo è come prima', fn () =>
    [$conta(ProductModel::class), $conta(ProductVariant::class), $conta(Product::class)] === $prima
);

summary();
