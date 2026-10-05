<?php
/** php tests/PromotionModelsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\Tag;
use Wonder\Plugin\Gestionale\Models\Promotions\DiscountCampaign;
use Wonder\Plugin\Gestionale\Models\Promotions\DiscountCampaignBrand;
use Wonder\Plugin\Gestionale\Models\Promotions\DiscountCampaignCategory;
use Wonder\Plugin\Gestionale\Models\Promotions\DiscountCampaignProductModel;
use Wonder\Plugin\Gestionale\Models\Promotions\DiscountCampaignTag;
use Wonder\Plugin\Gestionale\Support\Codes;

$colonne = static function (string $model): array {
    $colonne = [];

    foreach ($model::tableSchema() as $column) {
        $colonne[(string) $column->name] = $column;
    }

    return $colonne;
};

$campo = static function (string $model, string $key): ?object {
    foreach ($model::dataSchema() as $field) {
        if ((string) $field->key === $key) {
            return $field;
        }
    }

    return null;
};

$tutti = [
    DiscountCampaign::class,
    DiscountCampaignCategory::class,
    DiscountCampaignTag::class,
    DiscountCampaignBrand::class,
    DiscountCampaignProductModel::class,
];

check('le tabelle hanno il prefisso del gestionale e la loro cartella', fn () =>
    DiscountCampaign::$table === 'gst_discount_campaigns'
    && DiscountCampaignCategory::$table === 'gst_discount_campaign_categories'
    && DiscountCampaignTag::$table === 'gst_discount_campaign_tags'
    && DiscountCampaignBrand::$table === 'gst_discount_campaign_brands'
    && DiscountCampaignProductModel::$table === 'gst_discount_campaign_product_models'
    && DiscountCampaign::$folder === 'gestionale/models'
);

check('le campagne non viaggiano con il deploy', function () use ($tutti) {
    foreach ($tutti as $model) {
        if ($model::syncSchema() !== null) {
            return false;
        }
    }

    return true;
});

check('ogni tabella ha le sue colonne', function () use ($colonne) {
    $attese = [
        DiscountCampaign::class => [
            'code', 'name', 'discount_type', 'discount_value', 'starts_at', 'ends_at', 'active',
            'applies_to_all', 'exclude_sale_products', 'applies_online', 'applies_office', 'applies_pos', 'note',
        ],
        DiscountCampaignCategory::class => ['discount_campaign_id', 'category_id'],
        DiscountCampaignTag::class => ['discount_campaign_id', 'tag_id'],
        DiscountCampaignBrand::class => ['discount_campaign_id', 'brand_id'],
        DiscountCampaignProductModel::class => ['discount_campaign_id', 'product_model_id', 'is_excluded'],
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

check('il tipo di sconto è percentuale o importo e lo sconto ha due decimali veri', function () use ($colonne) {
    $c = $colonne(DiscountCampaign::class);

    return $c['discount_type']->getSchema('enum') === ['percent', 'amount']
        && $c['discount_value']->getSchema('type') === 'DECIMAL'
        && $c['discount_value']->getSchema('length') === '12,2';
});

check('le date sono data e ora e la fine può mancare', function () use ($colonne) {
    $c = $colonne(DiscountCampaign::class);

    return $c['starts_at']->getSchema('type') === 'DATETIME'
        && $c['ends_at']->getSchema('type') === 'DATETIME'
        && $c['ends_at']->getSchema('null') !== false;
});

check('i default: attiva e online sì, il resto no', function () use ($colonne) {
    $c = $colonne(DiscountCampaign::class);

    return $c['active']->getSchema('default') === 'true'
        && $c['applies_online']->getSchema('default') === 'true'
        && $c['applies_office']->getSchema('default') === 'false'
        && $c['applies_pos']->getSchema('default') === 'false'
        && $c['applies_to_all']->getSchema('default') === 'false'
        && $c['exclude_sale_products']->getSchema('default') === 'false'
        && $colonne(DiscountCampaignProductModel::class)['is_excluded']->getSchema('default') === 'false';
});

check('le chiavi esterne puntano alla campagna e a ciò che selezionano', function () use ($colonne) {
    return $colonne(DiscountCampaignCategory::class)['discount_campaign_id']->getSchema('foreign_table') === DiscountCampaign::$table
        && $colonne(DiscountCampaignCategory::class)['category_id']->getSchema('foreign_table') === Category::$table
        && $colonne(DiscountCampaignTag::class)['tag_id']->getSchema('foreign_table') === Tag::$table
        && $colonne(DiscountCampaignBrand::class)['brand_id']->getSchema('foreign_table') === Brand::$table
        && $colonne(DiscountCampaignProductModel::class)['product_model_id']->getSchema('foreign_table') === ProductModel::$table
        && $colonne(DiscountCampaignProductModel::class)['discount_campaign_id']->getSchema('foreign_table') === DiscountCampaign::$table;
});

check('gli indici dei collegamenti ci sono', function () {
    return array_key_exists('ind_campaign', DiscountCampaignCategory::tablePseudos())
        && array_key_exists('ind_category', DiscountCampaignCategory::tablePseudos())
        && array_key_exists('ind_campaign', DiscountCampaignTag::tablePseudos())
        && array_key_exists('ind_tag', DiscountCampaignTag::tablePseudos())
        && array_key_exists('ind_campaign', DiscountCampaignBrand::tablePseudos())
        && array_key_exists('ind_brand', DiscountCampaignBrand::tablePseudos())
        && array_key_exists('ind_campaign', DiscountCampaignProductModel::tablePseudos())
        && array_key_exists('ind_model', DiscountCampaignProductModel::tablePseudos());
});

check('il codice della campagna ha il suo prefisso', function () use ($campo) {
    return ($campo(DiscountCampaign::class, 'code')?->getSchema('unique_code')['prefix'] ?? null)
        === Codes::DISCOUNT_CAMPAIGN
        && Codes::DISCOUNT_CAMPAIGN === 'dsc_';
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
