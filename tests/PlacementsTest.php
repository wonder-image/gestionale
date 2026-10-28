<?php
/** php tests/PlacementsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductPlacement;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductSelection;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;
use Wonder\Plugin\Gestionale\Models\Catalog\Tag;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Resources\Catalog\CategoryResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductSelectionResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\TagResource;
use Wonder\Plugin\Gestionale\Resources\System\SettingResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Placements;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

$colonne = static function (string $model): array {
    $colonne = [];

    foreach ($model::tableSchema() as $column) {
        $colonne[(string) $column->name] = $column;
    }

    return $colonne;
};

// Lo stato delle funzionalità si forza senza database: `features()` è memoizzato.
$forza = static function (array $stato): void {
    (new ReflectionProperty(Gestionale::class, 'features'))->setValue(null, $stato);
};

$campi = static function (string $resource): array {
    return array_map(static fn ($field): string => (string) $field->name, $resource::formSchema());
};

check('le due tabelle hanno il prefisso del gestionale e non viaggiano con il deploy', function () {
    return ProductSelection::$table === 'gst_product_selections'
        && ProductPlacement::$table === 'gst_product_placements'
        && ProductSelection::syncSchema() === null
        && ProductPlacement::syncSchema() === null;
});

check('la selezione ha url pubblico, blocco, ordine e stato', function () use ($colonne) {
    $c = $colonne(ProductSelection::class);

    return array_diff(['code', 'name', 'slug', 'title', 'subtitle', 'text', 'image', 'sort_mode', 'visible'], array_keys($c)) === []
        && $c['sort_mode']->getSchema('default') === 'manual'
        && $c['sort_mode']->getSchema('enum') === array_keys(Placements::SELECTION_SORT_MODES)
        && $c['visible']->getSchema('default') === 'true'
        && Codes::SELECTION === 'sel_';
});

check('una riga di elenco punta a un contesto, a un articolo e, se vuole, a una variante', function () use ($colonne) {
    $c = $colonne(ProductPlacement::class);

    return array_diff(['context_type', 'context_id', 'product_model_id', 'product_variant_id', 'image', 'position'], array_keys($c)) === []
        && $c['context_type']->getSchema('enum') === Placements::CONTEXTS
        && $c['product_model_id']->getSchema('foreign_table') === ProductModel::$table
        && $c['product_variant_id']->getSchema('foreign_table') === ProductVariant::$table
        // Il contesto è di tre tabelle diverse: niente chiave esterna.
        && $c['context_id']->getSchema('foreign_table') === null;
});

check('categorie, tag e impostazioni hanno la loro regola, che parte da quella di oggi', function () use ($colonne) {
    $categoria = $colonne(Category::class)['sort_mode'] ?? null;
    $tag = $colonne(Tag::class)['sort_mode'] ?? null;
    $catalogo = $colonne(Setting::class)['catalog_sort'] ?? null;

    return $categoria !== null && $tag !== null && $catalogo !== null
        && $categoria->getSchema('default') === 'catalog'
        && $categoria->getSchema('enum') === array_keys(Placements::CONTEXT_SORT_MODES)
        && $tag->getSchema('enum') === array_keys(Placements::CONTEXT_SORT_MODES)
        // `position, name` è l'ordine che il sito usava prima delle regole.
        && $catalogo->getSchema('default') === 'position'
        && $catalogo->getSchema('enum') === array_keys(Placements::CATALOG_SORT_MODES);
});

check('le regole sconosciute tornano a quella di partenza', function () {
    return Placements::catalogSortMode('newest') === 'newest'
        && Placements::catalogSortMode('catalog') === 'position'
        && Placements::catalogSortMode(['x']) === 'position'
        && Placements::contextSortMode('price_desc') === 'price_desc'
        && Placements::contextSortMode('manual') === 'catalog'
        && Placements::selectionSortMode('position') === 'manual'
        && Placements::selectionSortMode(' name ') === 'name';
});

check('la voce del select: m per l\'articolo, v per la sola variante', function () {
    return Placements::itemKey(12) === 'm12'
        && Placements::itemKey(12, 34) === 'v34'
        && Placements::itemKey(0) === ''
        && Placements::parseItem('m12') === ['product_model_id' => 12, 'product_variant_id' => 0]
        && Placements::parseItem(' v34 ') === ['product_model_id' => 0, 'product_variant_id' => 34]
        && Placements::parseItem('m0') === null
        && Placements::parseItem('x12') === null
        && Placements::parseItem('m12; DROP') === null
        && Placements::parseItem(['m12']) === null;
});

check('le righe: variante col suo articolo, senza vuote, inesistenti e doppioni, nell\'ordine dato', function () {
    $articoloDi = static fn (int $variante): ?int => [34 => 12, 35 => 12, 50 => 7][$variante] ?? null;

    $righe = Placements::cleanRows([
        ['id' => '9', 'item' => 'v35', 'image' => '[]'],
        ['id' => '', 'item' => 'm12'],
        ['item' => ''],
        ['item' => 'v99'],
        ['item' => 'm12'],
        'non una riga',
        ['item' => 'v34'],
        ['item' => 'v35'],
        // Una riga già scomposta (rimandata dal form dopo un errore) vale lo stesso.
        ['product_model_id' => 7, 'product_variant_id' => 50],
    ], $articoloDi);

    $chiavi = array_map(
        static fn (array $riga): string => Placements::itemKey($riga['product_model_id'], $riga['product_variant_id']),
        $righe
    );

    return $chiavi === ['v35', 'm12', 'v34', 'v50']
        && $righe[0]['product_model_id'] === 12
        && $righe[0]['id'] === '9'
        && $righe[0]['image'] === '[]'
        && !array_key_exists('item', $righe[0]);
});

check('il form ritrova la voce delle righe salvate', function () {
    $righe = Placements::withItemKeys([
        ['id' => 1, 'product_model_id' => 12, 'product_variant_id' => null],
        ['id' => 2, 'product_model_id' => 12, 'product_variant_id' => 34],
        'non una riga',
    ]);

    return array_column($righe, 'item') === ['m12', 'v34'];
});

check('senza vendita online le Selezioni non esistono e categorie e tag non hanno «Ordine nel sito»', function () use ($forza, $campi) {
    $forza(['online_sales' => false]);

    $ok = ProductSelectionResource::$feature === 'online_sales'
        && !ProductSelectionResource::featureActive()
        && !in_array('sort_mode', $campi(CategoryResource::class), true)
        && !in_array('placements', $campi(CategoryResource::class), true)
        && !in_array('placements', $campi(TagResource::class), true)
        && !in_array('catalog_sort', $campi(SettingResource::class), true);

    $forza([]);

    return $ok;
});

check('con la vendita online i campi ci sono', function () use ($forza, $campi) {
    $forza(['online_sales' => true]);

    $ok = ProductSelectionResource::featureActive()
        && array_intersect(['sort_mode', 'placements'], $campi(CategoryResource::class)) === ['sort_mode', 'placements']
        && in_array('placements', $campi(TagResource::class), true)
        && in_array('placements', $campi(ProductSelectionResource::class), true)
        && in_array('catalog_sort', $campi(SettingResource::class), true);

    $forza([]);

    return $ok;
});

check('la regola di categoria e tag si salva solo con la vendita online, e solo se nota', function () use ($forza) {
    $forza(['online_sales' => false]);
    $spenta = TagResource::mutateRequestValues(['name' => 'Giorno', 'sort_mode' => 'newest'], 'update', 'backend', ['id' => 1]);

    $forza(['online_sales' => true]);
    $accesa = TagResource::mutateRequestValues(['name' => 'Giorno', 'sort_mode' => 'boh'], 'update', 'backend', ['id' => 1]);
    $nota = TagResource::mutateRequestValues(['name' => 'Giorno', 'sort_mode' => 'price_asc'], 'update', 'backend', ['id' => 1]);

    $forza([]);

    return !array_key_exists('sort_mode', $spenta)
        && $accesa['sort_mode'] === 'catalog'
        && $nota['sort_mode'] === 'price_asc';
});

check('la selezione: nome obbligatorio, url pubblico che non cambia, ordine e stato noti', function () {
    try {
        ProductSelectionResource::mutateRequestValues(['name' => '   '], 'update', 'backend', ['id' => 1]);

        return false;
    } catch (UserError $errore) {
        if ($errore->key() !== 'selection.name_required') {
            return false;
        }
    }

    $valori = ProductSelectionResource::mutateRequestValues(
        ['name' => ' Home prodotti ', 'slug' => 'altro', 'sort_mode' => 'catalog', 'visible' => 'boh'],
        'update',
        'backend',
        ['id' => 1]
    );

    return $valori['name'] === 'Home prodotti'
        && !array_key_exists('slug', $valori)
        && $valori['sort_mode'] === 'manual'
        && $valori['visible'] === 'true';
});

check('le righe si scrivono solo dalla scheda, mai dall\'API', function () {
    return ProductSelectionResource::syncRepeaterRelations(1, ['placements' => []], [], 'update', 'api') === []
        && CategoryResource::syncRepeaterRelations(1, [], [], 'update', 'api') === [];
});

check('ogni elenco scrive nella sua fetta della tabella', function () use ($forza) {
    $forza(['online_sales' => true]);
    $fette = [];

    foreach ([ProductSelectionResource::class, CategoryResource::class, TagResource::class] as $resource) {
        $relazione = $resource::repeaterRelations()['placements']['relation'] ?? null;
        $fette[] = $relazione === null ? null : [$relazione->table, $relazione->parentKey, $relazione->condition];
    }

    $forza([]);

    return $fette === [
        ['gst_product_placements', 'context_id', ['context_type' => 'selection']],
        ['gst_product_placements', 'context_id', ['context_type' => 'category']],
        ['gst_product_placements', 'context_id', ['context_type' => 'tag']],
    ];
});

summary();
