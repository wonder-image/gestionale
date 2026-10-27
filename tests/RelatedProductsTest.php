<?php
/** php tests/RelatedProductsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Catalog\ProductRelation;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;
use Wonder\Plugin\Gestionale\Support\Catalog\RelatedProducts;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

$riga = static fn (string|int $id): array => ['related_model_id' => (string) $id];

check('cleanRows tiene l\'ordine dato', function () use ($riga) {
    return array_column(RelatedProducts::cleanRows([$riga(9), $riga(3), $riga(5)], 1), 'related_model_id') === ['9', '3', '5'];
});

check('cleanRows toglie l\'articolo stesso', function () use ($riga) {
    return array_column(RelatedProducts::cleanRows([$riga(7), $riga(4)], 7), 'related_model_id') === ['4'];
});

check('cleanRows toglie i doppioni: vale la prima', function () use ($riga) {
    $righe = RelatedProducts::cleanRows([['related_model_id' => '4', 'id' => '1'], $riga(2), ['related_model_id' => '4', 'id' => '2']], 1);

    return array_column($righe, 'related_model_id') === ['4', '2'] && $righe[0]['id'] === '1';
});

check('cleanRows toglie le righe vuote', function () use ($riga) {
    return RelatedProducts::cleanRows([$riga(''), ['id' => '3'], 'x', $riga('0')], 1) === [];
});

check('la tabella ha articolo, collegato, tipo e posizione e non viaggia col deploy', function () {
    $colonne = array_map(static fn (object $c): string => (string) $c->name, ProductRelation::tableSchema());

    return ProductRelation::$table === 'gst_product_relations'
        && ProductRelation::syncSchema() === null
        && $colonne === ['product_model_id', 'related_model_id', 'type', 'position'];
});

check('il riquadro scrive due elenchi, uno per tipo', function () {
    return ProductModelResource::RELATED_INPUTS === ['related_suggested' => 'suggested', 'related_alternative' => 'alternative'];
});

check('assertRelatedRows ferma l\'articolo collegato a sé stesso', function () {
    try {
        ProductModelResource::assertRelatedRows(12, ['related_alternative' => [['related_model_id' => '3'], ['related_model_id' => '12']]]);
    } catch (UserError $e) {
        return $e->key() === 'catalog.related_self';
    }

    return false;
});

check('assertRelatedRows lascia passare gli altri e l\'articolo nuovo', function () {
    ProductModelResource::assertRelatedRows(12, ['related_suggested' => [['related_model_id' => '3']]]);
    ProductModelResource::assertRelatedRows(0, ['related_suggested' => [['related_model_id' => '0']]]);

    return true;
});

summary();
