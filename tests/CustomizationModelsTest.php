<?php
/** php tests/CustomizationModelsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Customization;
use Wonder\Plugin\Gestionale\Models\Catalog\CustomizationOption;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Plan;
use Wonder\Plugin\Gestionale\Models\Catalog\CustomizationLink;
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

check('le tabelle hanno il prefisso del gestionale e la loro cartella', fn () =>
    Customization::$table === 'gst_customizations'
    && Customization::$folder === 'gestionale/customizations'
    && CustomizationOption::$table === 'gst_customization_options'
    && CustomizationLink::$table === 'gst_customization_links'
    && CustomizationLink::$folder === 'gestionale/models'
);

check('le personalizzazioni non viaggiano con il deploy', fn () =>
    Customization::syncSchema() === null
    && CustomizationOption::syncSchema() === null
    && CustomizationLink::syncSchema() === null
);

check('ogni tabella ha le sue colonne', function () use ($colonne) {
    $attese = [
        Customization::class => ['code', 'name', 'label', 'help_text', 'kind', 'max_length', 'decimals', 'surcharge', 'active', 'for_products', 'for_subscriptions', 'position'],
        CustomizationOption::class => ['customization_id', 'label', 'surcharge', 'position'],
        CustomizationLink::class => ['product_model_id', 'plan_id', 'customization_id', 'is_required', 'surcharge', 'position'],
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

check('il tipo è testo, numero o scelta e parte da testo', function () use ($colonne) {
    $kind = $colonne(Customization::class)['kind'];

    return $kind->getSchema('enum') === ['text', 'number', 'choice'] && $kind->getSchema('default') === 'text';
});

check('il sovrapprezzo per articolo può mancare: vuoto vale quello della personalizzazione', function () use ($colonne) {
    $colonna = $colonne(CustomizationLink::class)['surcharge'];

    return $colonna->getSchema('type') === 'DECIMAL'
        && $colonna->getSchema('length') === '12,2'
        && $colonna->getSchema('null') !== false;
});

check('attiva parte da vera e obbligatoria da falsa', function () use ($colonne) {
    return $colonne(Customization::class)['active']->getSchema('default') === 'true'
        && $colonne(CustomizationLink::class)['is_required']->getSchema('default') === 'false';
});

check('il sovrapprezzo ha due decimali veri', function () use ($colonne) {
    foreach ([Customization::class, CustomizationOption::class] as $model) {
        $colonna = $colonne($model)['surcharge'];

        if ($colonna->getSchema('type') !== 'DECIMAL' || $colonna->getSchema('length') !== '12,2') {
            return false;
        }
    }

    return true;
});

check('le chiavi esterne puntano alla personalizzazione e all\'articolo', function () use ($colonne) {
    return $colonne(CustomizationOption::class)['customization_id']->getSchema('foreign_table') === Customization::$table
        && $colonne(CustomizationLink::class)['customization_id']->getSchema('foreign_table') === Customization::$table
        && $colonne(CustomizationLink::class)['product_model_id']->getSchema('foreign_table') === ProductModel::$table;
});

check('gli indici dei collegamenti ci sono', function () {
    return array_key_exists('ind_customization', CustomizationOption::tablePseudos())
        && array_key_exists('ind_model', CustomizationLink::tablePseudos())
        && array_key_exists('ind_customization', CustomizationLink::tablePseudos());
});

check('il codice della personalizzazione ha il suo prefisso', function () use ($campo) {
    return ($campo(Customization::class, 'code')?->getSchema('unique_code')['prefix'] ?? null)
        === Codes::CUSTOMIZATION
        && Codes::CUSTOMIZATION === 'cus_';
});

check('nessuna colonna usa una parola riservata di MySQL', function () use ($colonne) {
    $riservate = ['key', 'group', 'order', 'index', 'default'];

    foreach ([Customization::class, CustomizationOption::class, CustomizationLink::class] as $model) {
        foreach (array_keys($colonne($model)) as $nome) {
            if (in_array(strtolower((string) $nome), $riservate, true)) {
                return false;
            }
        }
    }

    return true;
});

check('un legame va a un articolo o a un piano: le due chiavi possono mancare', function () use ($colonne) {
    $legame = $colonne(CustomizationLink::class);

    return $legame['plan_id']->getSchema('foreign_table') === Plan::$table
        && Plan::$table === 'gst_plans'
        && $legame['plan_id']->getSchema('null') !== false
        && $legame['product_model_id']->getSchema('null') !== false
        && $legame['customization_id']->getSchema('null') === false
        && array_key_exists('ind_plan', CustomizationLink::tablePseudos());
});

check('una personalizzazione nasce per i prodotti e non per gli abbonamenti', function () use ($colonne, $campo) {
    $colonna = $colonne(Customization::class);

    return $colonna['for_products']->getSchema('enum') === ['true', 'false']
        && $colonna['for_products']->getSchema('default') === 'true'
        && $colonna['for_subscriptions']->getSchema('default') === 'false'
        && $campo(Customization::class, 'for_products') !== null
        && $campo(Customization::class, 'for_subscriptions') !== null
        && $campo(CustomizationLink::class, 'plan_id') !== null;
});

summary();
