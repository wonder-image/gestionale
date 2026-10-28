<?php
/** php tests/SelectionBlocksTest.php — blocco e fonte delle selezioni. */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Extensions\Extensions;
use Wonder\Plugin\Gestionale\Extensions\GestionaleExtension;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductSelection;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductSelectionResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Placements;
use Wonder\Plugin\Gestionale\Support\Catalog\SelectionBlocks;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

final class BloccoDelSito extends GestionaleExtension
{
    public function selectionBlocks(array $blocks): array
    {
        $blocks['carousel'] = [
            'label' => 'Carosello',
            'fields' => ['title', 'boh'],
            'meta' => [
                'autoplay' => ['label' => 'Scorrimento', 'type' => 'select', 'options' => ['no' => 'A mano', 'si' => 'Automatico']],
                'cta_note' => ['label' => 'Altra etichetta', 'type' => 'textarea'],
                'con-trattino' => ['label' => 'No'],
            ],
        ];

        return $blocks;
    }
}

$colonne = [];

foreach (ProductSelection::tableSchema() as $column) {
    $colonne[(string) $column->name] = $column;
}

$campi = static fn (): array => array_map(static fn ($field): string => (string) $field->name, ProductSelectionResource::formSchema());

check('la selezione ha blocco, campi del blocco, fonte e quanti articoli', function () use ($colonne) {
    return array_diff(['block', 'block_meta', 'source', 'source_category_slugs', 'source_tag_slugs', 'source_brand_slugs', 'excluded_model_ids', 'max_items'], array_keys($colonne)) === []
        && $colonne['block']->getSchema('default') === SelectionBlocks::DEFAULT
        && $colonne['source']->getSchema('default') === 'manual'
        && (string) $colonne['max_items']->getSchema('default') === (string) Placements::SELECTION_MAX_ITEMS;
});

check('fonti e quanti articoli: solo valori noti, al massimo 48', function () {
    return Placements::selectionSource('boh') === 'manual'
        && Placements::selectionSource(' brand ') === 'brand'
        && Placements::selectionSource('novelty') === 'catalog'
        && Placements::selectionSource('newest') === 'catalog'
        && Placements::selectionIds('[3,"3",0,"x",-1,7]') === [3, 7]
        && Placements::selectionSlugs('["estate"," novita ","",3,"estate"]') === ['estate', 'novita', '3']
        && Placements::selectionSlugs('x') === []
        && Placements::selectionRefSlugs(['source_tag_slugs' => '["estate"]', 'source_brand_slugs' => '["acme"]'], 'tag') === ['estate']
        && Placements::selectionRefSlugs(['source_tag_slugs' => '["estate"]'], 'manual') === []
        && Placements::selectionExcluded(['excluded_model_ids' => '[9]']) === [9]
        && Placements::selectionMaxItems('') === 8
        && Placements::selectionMaxItems('-3') === 8
        && Placements::selectionMaxItems('5') === 5
        && Placements::selectionMaxItems(500) === 48
        && array_key_first(Placements::SELECTION_SOURCES) === 'manual';
});

check('di serie tre blocchi, lo standard per primo; un nome ignoto torna lo standard', function () {
    Extensions::use([]);
    SelectionBlocks::reset(true);

    return array_keys(SelectionBlocks::options()) === ['default', 'swiper', 'spotlight']
        && SelectionBlocks::normalize('boh') === 'default'
        && SelectionBlocks::fields('default') === ['title', 'subtitle', 'text']
        && SelectionBlocks::fields('swiper') === ['title', 'subtitle', 'text']
        && SelectionBlocks::blocksWith('image') === ['spotlight']
        && SelectionBlocks::blocksWith('background') === ['spotlight']
        && SelectionBlocks::blocksWith('title') === ['default', 'swiper', 'spotlight']
        && array_keys(SelectionBlocks::metaFields('spotlight')) === ['image_side']
        && SelectionBlocks::cleanMeta('spotlight', ['image_side' => 'centro']) === ['image_side' => 'left']
        && SelectionBlocks::cleanMeta('spotlight', []) === ['image_side' => 'left']
        && SelectionBlocks::metaFields('spotlight')['image_side']['default'] === 'left'
        && SelectionBlocks::cleanMeta('spotlight', ['image_side' => 'right']) === ['image_side' => 'right'];
});

check('il sito aggiunge blocchi con la sua estensione, un modulo con register()', function () {
    Extensions::use([BloccoDelSito::class]);
    SelectionBlocks::reset(true);
    SelectionBlocks::register('griglia', 'Griglia', ['title', 'image']);

    $blocks = SelectionBlocks::all();
    $ok = isset($blocks['carousel'], $blocks['griglia'])
        && array_key_first($blocks) === 'default'
        && $blocks['carousel']['fields'] === ['title']
        && array_keys($blocks['carousel']['meta']) === ['autoplay', 'cta_note']
        && SelectionBlocks::allMetaFields()['cta_note']['type'] === 'textarea'
        && SelectionBlocks::blocksWith('cta_note') === ['carousel']
        && SelectionBlocks::cleanMeta('carousel', ['autoplay' => 'forse', 'cta_note' => ' Vai ', 'altro' => 'x']) === ['autoplay' => '', 'cta_note' => 'Vai']
        && SelectionBlocks::cleanMeta('carousel', ['autoplay' => 'si'])['autoplay'] === 'si';

    Extensions::use([]);
    SelectionBlocks::reset(true);

    return $ok;
});

check('il form ha blocco, fonte e i campi in più; immagine e sfondo solo per i blocchi che li usano', function () use ($campi, $colonne) {
    Extensions::use([]);
    SelectionBlocks::reset(true);
    $nomi = $campi();
    $immagini = [];

    foreach (ProductSelectionResource::formSchema() as $field) {
        if (in_array((string) $field->name, ['image', 'background'], true)) {
            $immagini[(string) $field->name] = $field;
        }
    }

    return array_diff(['block', 'source', 'source_category_slugs', 'source_tag_slugs', 'source_brand_slugs', 'excluded_model_ids', 'max_items', 'block_meta_image_side', 'placements'], $nomi) === []
        && isset($colonne['background'], $immagini['image'], $immagini['background'])
        && str_contains(json_encode($immagini['image']->conditionalAttributes()), 'spotlight')
        && !str_contains(json_encode($immagini['background']->conditionalAttributes()), 'swiper');
});

check('la fonte con riferimenti vuole almeno una categoria, un tag o un marchio', function () {
    foreach (['category' => 'selection.source_category_required', 'tag' => 'selection.source_tag_required', 'brand' => 'selection.source_brand_required'] as $source => $key) {
        try {
            ProductSelectionResource::mutateRequestValues(['name' => 'Home', 'source' => $source], 'update', 'backend', ['id' => 1]);

            return false;
        } catch (UserError $errore) {
            if ($errore->key() !== $key) {
                return false;
            }
        }
    }

    $valori = ProductSelectionResource::mutateRequestValues(
        ['name' => 'Home', 'source' => 'tag', 'source_tag_slugs' => ['estate', 'novita', 'estate', ''], 'source_category_slugs' => ['scarpe'], 'max_items' => '0',
            'excluded_model_ids' => [['id' => '', 'related_model_id' => '11'], ['id' => '', 'related_model_id' => '0']]],
        'update',
        'backend',
        ['id' => 1]
    );

    return $valori['source'] === 'tag'
        && $valori['source_tag_slugs'] === '["estate","novita"]'
        && $valori['source_category_slugs'] === '[]'
        && $valori['excluded_model_ids'] === '[11]'
        && $valori['max_items'] === 8;
});

check('i campi del blocco diventano block_meta, solo quelli del blocco scelto', function () {
    Extensions::use([]);
    SelectionBlocks::reset(true);

    $spot = ProductSelectionResource::mutateRequestValues(
        ['name' => 'Alba', 'block' => 'spotlight', 'block_meta_image_side' => ' right ', 'block_meta_altro' => 'x'],
        'update',
        'backend',
        ['id' => 1]
    );
    $standard = ProductSelectionResource::mutateRequestValues(
        ['name' => 'Alba', 'block' => 'boh', 'block_meta_image_side' => 'right'],
        'update',
        'backend',
        ['id' => 1]
    );
    $form = ProductSelectionResource::mutateFormValues(['block' => 'spotlight', 'block_meta' => $spot['block_meta'], 'source' => 'tag', 'source_tag_slugs' => '["estate","novita"]'], 'edit');

    return json_decode($spot['block_meta'], true) === ['image_side' => 'right']
        && !array_key_exists('block_meta_image_side', $spot)
        && !array_key_exists('block_meta_altro', $spot)
        && $standard['block'] === 'default'
        && json_decode($standard['block_meta'], true) === ['cta_label' => '', 'cta_link' => 'all', 'cta_url' => '', 'cta_category' => '', 'cta_tag' => '', 'cta_brand' => '', 'cta_product' => '']
        && $form['block_meta_image_side'] === 'right'
        && $form['source_tag_slugs'] === ['estate', 'novita']
        && $form['source_category_slugs'] === []
        && SelectionBlocks::decodeMeta('{&quot;a&quot;:&quot;b&quot;}') === ['a' => 'b']
        && SelectionBlocks::decodeMeta(['a' => 'b']) === ['a' => 'b'];
});

check('il bottone di elenco e swiper: testo, azione e solo la voce dell\'azione scelta', function () {
    Extensions::use([]);
    SelectionBlocks::reset(true);

    $meta = SelectionBlocks::cleanMeta('swiper', ['cta_label' => ' Scopri ', 'cta_link' => 'category', 'cta_category' => 'divani', 'cta_brand' => 'acme', 'cta_url' => '/x/']);
    $sconosciuta = SelectionBlocks::cleanMeta('default', ['cta_link' => 'boh', 'cta_url' => '/x/']);

    return SelectionBlocks::blocksWith('cta_label') === ['default', 'swiper']
        && SelectionBlocks::metaFields('default')['cta_category']['type'] === 'category'
        && SelectionBlocks::metaFields('default')['cta_url']['when'] === ['cta_link' => ['custom']]
        && $meta['cta_label'] === 'Scopri'
        && $meta['cta_category'] === 'divani'
        && $meta['cta_brand'] === ''
        && $meta['cta_url'] === ''
        && $sconosciuta['cta_link'] === 'all'
        && $sconosciuta['cta_url'] === ''
        && SelectionBlocks::metaFields('spotlight') !== [] && !isset(SelectionBlocks::metaFields('spotlight')['cta_label']);
});

check('la selezione nasce in locale: definizione sincronizzata, testi, foto ed esclusi restano del sito', function () {
    $schema = ProductSelection::syncSchema();

    return $schema->localOnly
        && $schema->keepIds
        && $schema->excludeColumns === ProductSelection::SITE_COLUMNS
        && !in_array('source', ProductSelection::SITE_COLUMNS, true)
        && array_diff([...ProductSelection::SITE_COLUMNS, 'placements'], ProductSelectionResource::editableWhenReadonly()) === []
        && !in_array('source_tag_slugs', ProductSelectionResource::editableWhenReadonly(), true);
});

check('in produzione arrivano solo testi, foto ed esclusi: la definizione non si tocca', function () {
    $valori = ProductSelectionResource::mutateRequestValues(
        ['title' => 'Estate', 'excluded_model_ids' => [['id' => '', 'related_model_id' => '11']]],
        'update',
        'backend',
        ['id' => 1, 'source' => 'tag', 'source_tag_slugs' => '["estate"]']
    );

    return $valori['title'] === 'Estate'
        && array_intersect(['name', 'slug', 'block', 'block_meta', 'max_items', 'source', 'sort_mode', 'visible', 'source_category_slugs', 'source_tag_slugs', 'source_brand_slugs'], array_keys($valori)) === [];
});

summary();
