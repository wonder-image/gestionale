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
                'cta_label' => ['label' => 'Altra etichetta', 'type' => 'textarea'],
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
    return array_diff(['block', 'block_meta', 'source', 'source_category_id', 'source_tag_id', 'max_items'], array_keys($colonne)) === []
        && $colonne['block']->getSchema('default') === SelectionBlocks::DEFAULT
        && $colonne['source']->getSchema('default') === 'manual'
        && (string) $colonne['max_items']->getSchema('default') === (string) Placements::SELECTION_MAX_ITEMS;
});

check('fonti e quanti articoli: solo valori noti, al massimo 48', function () {
    return Placements::selectionSource('boh') === 'manual'
        && Placements::selectionSource(' novelty ') === 'novelty'
        && Placements::selectionMaxItems('') === 8
        && Placements::selectionMaxItems('-3') === 8
        && Placements::selectionMaxItems('5') === 5
        && Placements::selectionMaxItems(500) === 48
        && array_key_first(Placements::SELECTION_SOURCES) === 'manual';
});

check('di serie due blocchi, lo standard per primo; un nome ignoto torna lo standard', function () {
    Extensions::use([]);
    SelectionBlocks::reset(true);

    return array_keys(SelectionBlocks::options()) === ['default', 'spotlight']
        && SelectionBlocks::normalize('boh') === 'default'
        && SelectionBlocks::fields('default') === ['title', 'subtitle', 'text']
        && SelectionBlocks::blocksWith('image') === ['spotlight']
        && SelectionBlocks::blocksWith('title') === ['default', 'spotlight']
        && array_keys(SelectionBlocks::metaFields('spotlight')) === ['cta_label', 'cta_url'];
});

check('il sito aggiunge blocchi con la sua estensione, un modulo con register()', function () {
    Extensions::use([BloccoDelSito::class]);
    SelectionBlocks::reset(true);
    SelectionBlocks::register('griglia', 'Griglia', ['title', 'image']);

    $blocks = SelectionBlocks::all();
    $ok = isset($blocks['carousel'], $blocks['griglia'])
        && array_key_first($blocks) === 'default'
        && $blocks['carousel']['fields'] === ['title']
        && array_keys($blocks['carousel']['meta']) === ['autoplay', 'cta_label']
        // Stessa chiave in due blocchi: nel form vale la prima definizione.
        && SelectionBlocks::allMetaFields()['cta_label']['type'] === 'text'
        && SelectionBlocks::blocksWith('cta_label') === ['spotlight', 'carousel']
        && SelectionBlocks::cleanMeta('carousel', ['autoplay' => 'forse', 'cta_label' => ' Vai ', 'altro' => 'x']) === ['autoplay' => '', 'cta_label' => 'Vai']
        && SelectionBlocks::cleanMeta('carousel', ['autoplay' => 'si'])['autoplay'] === 'si';

    Extensions::use([]);
    SelectionBlocks::reset(true);

    return $ok;
});

check('il form ha blocco, fonte e i campi in più; l\'immagine solo per i blocchi che la usano', function () use ($campi) {
    Extensions::use([]);
    SelectionBlocks::reset(true);
    $nomi = $campi();
    $immagine = null;

    foreach (ProductSelectionResource::formSchema() as $field) {
        if ((string) $field->name === 'image') {
            $immagine = $field;
        }
    }

    return array_diff(['block', 'source', 'source_category_id', 'source_tag_id', 'max_items', 'block_meta_cta_label', 'block_meta_cta_url', 'placements'], $nomi) === []
        && $immagine !== null
        && str_contains(json_encode($immagine->conditionalAttributes()), 'spotlight');
});

check('la fonte con riferimento vuole la sua categoria o il suo tag', function () {
    foreach (['category' => 'selection.source_category_required', 'tag' => 'selection.source_tag_required'] as $source => $key) {
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
        ['name' => 'Home', 'source' => 'tag', 'source_tag_id' => '7', 'source_category_id' => '3', 'max_items' => '0'],
        'update',
        'backend',
        ['id' => 1]
    );

    return $valori['source'] === 'tag'
        && $valori['source_tag_id'] === 7
        && $valori['source_category_id'] === 0
        && $valori['max_items'] === 8;
});

check('i campi del blocco diventano block_meta, solo quelli del blocco scelto', function () {
    Extensions::use([]);
    SelectionBlocks::reset(true);

    $spot = ProductSelectionResource::mutateRequestValues(
        ['name' => 'Alba', 'block' => 'spotlight', 'block_meta_cta_label' => ' Scopri ', 'block_meta_cta_url' => '/negozio/', 'block_meta_altro' => 'x'],
        'update',
        'backend',
        ['id' => 1]
    );
    $standard = ProductSelectionResource::mutateRequestValues(
        ['name' => 'Alba', 'block' => 'boh', 'block_meta_cta_label' => 'Scopri'],
        'update',
        'backend',
        ['id' => 1]
    );
    $form = ProductSelectionResource::mutateFormValues(['block' => 'spotlight', 'block_meta' => $spot['block_meta'], 'source_tag_id' => 0], 'edit');

    return json_decode($spot['block_meta'], true) === ['cta_label' => 'Scopri', 'cta_url' => '/negozio/']
        && !array_key_exists('block_meta_cta_label', $spot)
        && !array_key_exists('block_meta_altro', $spot)
        && $standard['block'] === 'default'
        && $standard['block_meta'] === '{}'
        && $form['block_meta_cta_label'] === 'Scopri'
        && $form['source_tag_id'] === ''
        && SelectionBlocks::decodeMeta('{&quot;a&quot;:&quot;b&quot;}') === ['a' => 'b']
        && SelectionBlocks::decodeMeta(['a' => 'b']) === ['a' => 'b'];
});

summary();
