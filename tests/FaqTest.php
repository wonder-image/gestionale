<?php
/** php tests/FaqTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\Faq;
use Wonder\Plugin\Gestionale\Models\Catalog\FaqBrand;
use Wonder\Plugin\Gestionale\Models\Catalog\FaqCategory;
use Wonder\Plugin\Gestionale\Models\Catalog\FaqProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\FaqTag;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\Tag;
use Wonder\Plugin\Gestionale\Resources\Catalog\FaqResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Faqs;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Promotions\ProductScope;

$colonne = static function (string $model): array {
    $colonne = [];

    foreach ($model::tableSchema() as $column) {
        $colonne[(string) $column->name] = $column;
    }

    return $colonne;
};

$tutti = [Faq::class, FaqCategory::class, FaqTag::class, FaqBrand::class, FaqProductModel::class];

check('le tabelle hanno il prefisso del gestionale e non viaggiano con il deploy', function () use ($tutti) {
    foreach ($tutti as $model) {
        if (!str_starts_with($model::$table, 'gst_faq') || $model::syncSchema() !== null) {
            return false;
        }
    }

    return Faq::$table === 'gst_faqs' && Faq::$folder === 'gestionale/models';
});

check('ogni tabella ha le sue colonne', function () use ($colonne) {
    $attese = [
        Faq::class => ['code', 'question', 'answer', 'importance', 'position', 'visible', 'show_on_home', 'applies_to_all'],
        FaqCategory::class => ['faq_id', 'category_id'],
        FaqTag::class => ['faq_id', 'tag_id'],
        FaqBrand::class => ['faq_id', 'brand_id'],
        FaqProductModel::class => ['faq_id', 'product_model_id', 'is_excluded'],
    ];

    foreach ($attese as $model => $nomi) {
        if (array_diff($nomi, array_keys($colonne($model))) !== []) {
            return false;
        }
    }

    return true;
});

check('i default: visibile, normale, fuori dalla home e solo la selezione', function () use ($colonne) {
    $c = $colonne(Faq::class);

    return $c['visible']->getSchema('default') === 'true'
        && $c['importance']->getSchema('default') === 'normal'
        && $c['importance']->getSchema('enum') === ['high', 'normal', 'low']
        && $c['show_on_home']->getSchema('default') === 'false'
        && $c['applies_to_all']->getSchema('default') === 'false'
        && $colonne(FaqProductModel::class)['is_excluded']->getSchema('default') === 'false';
});

check('le chiavi esterne puntano alla FAQ e a ciò che selezionano', function () use ($colonne) {
    return $colonne(FaqCategory::class)['faq_id']->getSchema('foreign_table') === Faq::$table
        && $colonne(FaqCategory::class)['category_id']->getSchema('foreign_table') === Category::$table
        && $colonne(FaqTag::class)['tag_id']->getSchema('foreign_table') === Tag::$table
        && $colonne(FaqBrand::class)['brand_id']->getSchema('foreign_table') === Brand::$table
        && $colonne(FaqProductModel::class)['product_model_id']->getSchema('foreign_table') === ProductModel::$table;
});

check('il codice ha il suo prefisso', fn () => Codes::FAQ === 'faq_');

check('il selettore conosce le FAQ e le loro tabelle', function () {
    $owner = ProductScope::OWNERS['faq'] ?? null;

    return $owner !== null && $owner['model'] === Faq::class && $owner['key'] === 'faq_id';
});

check('l\'ordine: in cima, normali, in fondo; poi posizione e creazione', function () {
    $righe = [
        ['id' => 1, 'importance' => 'low', 'position' => 1],
        ['id' => 2, 'importance' => 'normal', 'position' => 3],
        ['id' => 3, 'importance' => 'high', 'position' => 9],
        ['id' => 4, 'importance' => 'normal', 'position' => 2],
        ['id' => 5, 'importance' => 'normal', 'position' => 2],
    ];

    return array_column(Faqs::sort($righe), 'id') === [3, 4, 5, 2, 1];
});

check('senza domanda o risposta non si salva, nemmeno di soli spazi o tag vuoti', function () {
    $chiave = static function (array $values, string $action): string {
        try {
            FaqResource::mutateRequestValues($values, $action, 'backend', $action === 'update' ? ['id' => 1] : null);
        } catch (UserError $errore) {
            return $errore->getMessage() === $errore->key() ? 'senza frase' : $errore->key();
        }

        return '';
    };

    return $chiave([], 'store') === 'faq.question_required'
        && $chiave(['question' => '   ', 'answer' => '<p>Sì</p>'], 'store') === 'faq.question_required'
        && $chiave(['question' => 'Spedite?', 'answer' => '<p> </p>'], 'store') === 'faq.answer_required'
        && $chiave(['question' => ''], 'update') === 'faq.question_required';
});

check('la modifica non tocca la posizione né salva il selettore come colonne', function () {
    $valori = FaqResource::mutateRequestValues(
        ['question' => ' Spedite all\'estero? ', 'position' => 7, 'importance' => 'boh', 'categories' => [1], 'applies_to_all' => 'true'],
        'update',
        'backend',
        ['id' => 1]
    );

    return $valori['question'] === 'Spedite all\'estero?'
        && $valori['importance'] === 'normal'
        && !array_key_exists('position', $valori)
        && !array_key_exists('categories', $valori)
        // «Tutti i prodotti» è una colonna della FAQ, non un ponte.
        && $valori['applies_to_all'] === 'true';
});

summary();
