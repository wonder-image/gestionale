<?php
/** php tests/CouponModelsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\Tag;
use Wonder\Plugin\Gestionale\Models\Promotions\Coupon;
use Wonder\Plugin\Gestionale\Models\Promotions\CouponBrand;
use Wonder\Plugin\Gestionale\Models\Promotions\CouponCategory;
use Wonder\Plugin\Gestionale\Models\Promotions\CouponCustomer;
use Wonder\Plugin\Gestionale\Models\Promotions\CouponProductModel;
use Wonder\Plugin\Gestionale\Models\Promotions\CouponRedemption;
use Wonder\Plugin\Gestionale\Models\Promotions\CouponTag;
use Wonder\Plugin\Gestionale\Models\Sales\Order;

$colonne = static function (string $model): array {
    $colonne = [];

    foreach ($model::tableSchema() as $column) {
        $colonne[(string) $column->name] = $column;
    }

    return $colonne;
};

$tutti = [
    Coupon::class,
    CouponCategory::class,
    CouponTag::class,
    CouponBrand::class,
    CouponProductModel::class,
    CouponCustomer::class,
    CouponRedemption::class,
];

check('le tabelle hanno il prefisso del gestionale e la loro cartella', fn () =>
    Coupon::$table === 'gst_coupons'
    && CouponCategory::$table === 'gst_coupon_categories'
    && CouponTag::$table === 'gst_coupon_tags'
    && CouponBrand::$table === 'gst_coupon_brands'
    && CouponProductModel::$table === 'gst_coupon_product_models'
    && CouponCustomer::$table === 'gst_coupon_customers'
    && CouponRedemption::$table === 'gst_coupon_redemptions'
    && Coupon::$folder === 'gestionale/models'
);

check('i coupon non viaggiano con il deploy', function () use ($tutti) {
    foreach ($tutti as $model) {
        if ($model::syncSchema() !== null) {
            return false;
        }
    }

    return true;
});

check('ogni tabella ha le sue colonne', function () use ($colonne) {
    $attese = [
        Coupon::class => [
            'code', 'name', 'discount_type', 'discount_value', 'min_order_amount', 'applies_to_all',
            'exclude_discounted_products', 'first_order_only', 'usage_limit', 'usage_limit_per_customer',
            'starts_at', 'ends_at', 'applies_online', 'applies_office', 'applies_pos', 'active', 'note',
        ],
        CouponCategory::class => ['coupon_id', 'category_id'],
        CouponTag::class => ['coupon_id', 'tag_id'],
        CouponBrand::class => ['coupon_id', 'brand_id'],
        CouponProductModel::class => ['coupon_id', 'product_model_id', 'is_excluded'],
        CouponCustomer::class => ['coupon_id', 'customer_id'],
        CouponRedemption::class => ['coupon_id', 'order_id', 'customer_id', 'email', 'discount_amount', 'redeemed_at', 'released_at'],
    ];

    foreach ($attese as $model => $nomi) {
        $presenti = array_keys($colonne($model));

        foreach ($nomi as $nome) {
            if (!in_array($nome, $presenti, true)) {
                return false;
            }
        }
    }

    return true;
});

check('il tipo di sconto ha i quattro valori e gli importi due decimali veri', function () use ($colonne) {
    $c = $colonne(Coupon::class);

    return $c['discount_type']->getSchema('enum') === ['percent', 'amount', 'free_shipping', 'store_credit']
        && $c['discount_value']->getSchema('type') === 'DECIMAL'
        && $c['discount_value']->getSchema('length') === '12,2'
        && $c['min_order_amount']->getSchema('length') === '12,2'
        && $colonne(CouponRedemption::class)['discount_amount']->getSchema('length') === '12,2';
});

check('i limiti sono numeri interi e le date sono data e ora', function () use ($colonne) {
    $c = $colonne(Coupon::class);

    return $c['usage_limit']->getSchema('type') === 'INT'
        && $c['usage_limit_per_customer']->getSchema('type') === 'INT'
        && $c['starts_at']->getSchema('type') === 'DATETIME'
        && $c['ends_at']->getSchema('type') === 'DATETIME'
        && $colonne(CouponRedemption::class)['redeemed_at']->getSchema('type') === 'DATETIME'
        && $colonne(CouponRedemption::class)['released_at']->getSchema('null') !== false;
});

check('i default: attivo e online sì, limiti a zero, il resto no', function () use ($colonne) {
    $c = $colonne(Coupon::class);

    return $c['active']->getSchema('default') === 'true'
        && $c['applies_online']->getSchema('default') === 'true'
        && $c['applies_office']->getSchema('default') === 'false'
        && $c['applies_pos']->getSchema('default') === 'false'
        && $c['applies_to_all']->getSchema('default') === 'false'
        && $c['exclude_discounted_products']->getSchema('default') === 'false'
        && $c['first_order_only']->getSchema('default') === 'false'
        && (string) $c['usage_limit']->getSchema('default') === '0'
        && (string) $c['usage_limit_per_customer']->getSchema('default') === '0'
        && $colonne(CouponProductModel::class)['is_excluded']->getSchema('default') === 'false'
        && (string) $colonne(CouponRedemption::class)['customer_id']->getSchema('default') === '0';
});

check('il codice del coupon è unico', function () use ($colonne) {
    $code = $colonne(Coupon::class)['code'];

    return $code->getSchema('unique') === true && $code->getSchema('null') === false;
});

check('le chiavi esterne puntano al coupon e a ciò che selezionano', function () use ($colonne) {
    return $colonne(CouponCategory::class)['coupon_id']->getSchema('foreign_table') === Coupon::$table
        && $colonne(CouponCategory::class)['category_id']->getSchema('foreign_table') === Category::$table
        && $colonne(CouponTag::class)['tag_id']->getSchema('foreign_table') === Tag::$table
        && $colonne(CouponBrand::class)['brand_id']->getSchema('foreign_table') === Brand::$table
        && $colonne(CouponProductModel::class)['product_model_id']->getSchema('foreign_table') === ProductModel::$table
        && $colonne(CouponProductModel::class)['coupon_id']->getSchema('foreign_table') === Coupon::$table
        && $colonne(CouponCustomer::class)['coupon_id']->getSchema('foreign_table') === Coupon::$table
        && $colonne(CouponRedemption::class)['coupon_id']->getSchema('foreign_table') === Coupon::$table
        && $colonne(CouponRedemption::class)['order_id']->getSchema('foreign_table') === Order::$table;
});

check('gli indici ci sono', function () {
    return array_key_exists('ind_coupon', CouponCategory::tablePseudos())
        && array_key_exists('ind_category', CouponCategory::tablePseudos())
        && array_key_exists('ind_coupon', CouponTag::tablePseudos())
        && array_key_exists('ind_tag', CouponTag::tablePseudos())
        && array_key_exists('ind_coupon', CouponBrand::tablePseudos())
        && array_key_exists('ind_brand', CouponBrand::tablePseudos())
        && array_key_exists('ind_coupon', CouponProductModel::tablePseudos())
        && array_key_exists('ind_model', CouponProductModel::tablePseudos())
        && array_key_exists('ind_coupon', CouponCustomer::tablePseudos())
        && array_key_exists('ind_customer', CouponCustomer::tablePseudos())
        && array_key_exists('ind_coupon', CouponRedemption::tablePseudos())
        && array_key_exists('ind_order', CouponRedemption::tablePseudos())
        && array_key_exists('ind_customer', CouponRedemption::tablePseudos());
});

check('nessuna colonna usa una parola riservata di MySQL', function () use ($colonne, $tutti) {
    $riservate = ['key', 'group', 'order', 'index', 'default'];

    foreach ($tutti as $model) {
        foreach (array_keys($colonne($model)) as $nome) {
            if (in_array(strtolower((string) $nome), $riservate, true)) {
                return false;
            }
        }
    }

    return true;
});

summary();
