<?php
/** php tests/CatalogResourcesTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Attribute;
use Wonder\Plugin\Gestionale\Models\Catalog\AttributeValue;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\Tag;
use Wonder\Plugin\Gestionale\Resources\Catalog\AttributeResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\BrandResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\CategoryResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\TagResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

check('ogni pagina ha il suo model e il suo percorso', fn () =>
    BrandResource::$model === Brand::class
    && CategoryResource::$model === Category::class
    && TagResource::$model === Tag::class
    && BrandResource::path() === 'app/gestionale/marchi'
    && CategoryResource::path() === 'app/gestionale/categorie'
    && TagResource::path() === 'app/gestionale/tag'
);

check('il catalogo sta nella sua sezione, per tutti e due i ruoli', function () {
    foreach ([BrandResource::class, CategoryResource::class, TagResource::class] as $resource) {
        if (($resource::navigationSchema()->toArray()['section_key'] ?? '') !== 'catalogo') {
            return false;
        }

        foreach ($resource::permissionSchema()->toArray()['backend'] ?? [] as $authorities) {
            if ($authorities !== [] && $authorities !== ['admin', 'administrator']) {
                return false;
            }
        }
    }

    return true;
});

check('le pagine del catalogo sono sempre attive', fn () =>
    BrandResource::$feature === '' && CategoryResource::$feature === '' && TagResource::$feature === ''
);

check('lo slug non si scrive a mano', function () {
    foreach ([BrandResource::class, CategoryResource::class, TagResource::class] as $resource) {
        foreach ($resource::formSchema() as $field) {
            if ((string) $field->name === 'slug') {
                return false;
            }
        }
    }

    return true;
});

check('lo slug nasce dal nome alla creazione e poi non cambia', function () {
    $creazione = BrandResource::mutateRequestValues(['name' => 'Nike Italia'], 'store');
    $modifica = BrandResource::mutateRequestValues(['name' => 'Nike Italia'], 'update', 'backend', ['slug' => 'nike']);

    return ($creazione['slug'] ?? '') === 'nike-italia' && !isset($modifica['slug']);
});

check('il select del padre non contiene la categoria stessa', function () {
    $righe = [
        ['id' => 1, 'parent_id' => 0, 'name' => 'Abbigliamento', 'position' => 1],
        ['id' => 2, 'parent_id' => 1, 'name' => 'Magliette', 'position' => 1],
    ];

    $opzioni = CategoryResource::parentOptions($righe, 1);

    return !isset($opzioni['1'], $opzioni['2']) && isset($opzioni['']);
});

check('un ciclo viene rifiutato con parole comprensibili', function () {
    $righe = [
        ['id' => 1, 'parent_id' => 0, 'name' => 'Abbigliamento', 'position' => 1],
        ['id' => 2, 'parent_id' => 1, 'name' => 'Magliette', 'position' => 1],
    ];

    try {
        CategoryResource::assertNoLoop($righe, 1, 2);
    } catch (UserError $errore) {
        return str_contains(strtolower($errore->getMessage()), 'categoria');
    }

    return false;
});

check('la posizione non si scrive a mano', function () {
    foreach ([BrandResource::class, CategoryResource::class] as $resource) {
        foreach ($resource::formSchema() as $field) {
            if ((string) $field->name === 'position') {
                return false;
            }
        }
    }

    $creazione = BrandResource::mutateRequestValues(['name' => 'Nike'], 'store');
    $modifica = BrandResource::mutateRequestValues(['name' => 'Nike', 'position' => 9], 'update');

    return (int) ($creazione['position'] ?? 0) >= 1 && !isset($modifica['position']);
});

check('la categoria padre si sceglie dall\'albero', function () {
    foreach (CategoryResource::formSchema() as $field) {
        if ((string) $field->name !== 'parent_id') {
            continue;
        }

        return $field instanceof Wonder\App\ResourceSchema\Inputs\InputCheckTree
            && ($field->get('context')['input_type'] ?? '') === 'radio';
    }

    return false;
});

check('l\'albero del padre è annidato e senza i discendenti', function () {
    $righe = [
        ['id' => 1, 'parent_id' => 0, 'name' => 'Abbigliamento', 'position' => 1],
        ['id' => 2, 'parent_id' => 1, 'name' => 'Magliette', 'position' => 1],
        ['id' => 3, 'parent_id' => 0, 'name' => 'Accessori', 'position' => 2],
    ];

    $albero = CategoryResource::parentTree($righe, 1);
    $radici = $albero['0']['child'] ?? [];

    // PHP riporta a intero le chiavi numeriche di un array.
    return isset($albero['0'])
        && array_keys($radici) === [3]
        && ($radici[3]['child'] ?? null) === [];
});

check('l\'albero annida i figli sotto il padre', function () {
    $righe = [
        ['id' => 1, 'parent_id' => 0, 'name' => 'Abbigliamento', 'position' => 1],
        ['id' => 2, 'parent_id' => 1, 'name' => 'Magliette', 'position' => 1],
    ];

    $figli = CategoryResource::parentTree($righe, null)['0']['child'] ?? [];

    return array_keys($figli) === [1] && array_keys($figli[1]['child'] ?? []) === [2];
});

check('la pagina degli attributi sta nel catalogo', fn () =>
    AttributeResource::$model === Attribute::class
    && AttributeResource::path() === 'app/gestionale/attributi'
    && (AttributeResource::navigationSchema()->toArray()['section_key'] ?? '') === 'catalogo'
);

check('i valori servono a elenco, colore, fantasia e icona', fn () =>
    AttributeResource::usesValues(['type' => 'select'])
    && AttributeResource::usesValues(['type' => 'color'])
    && AttributeResource::usesValues(['type' => 'pattern'])
    && AttributeResource::usesValues(['type' => 'icon'])
    && !AttributeResource::usesValues(['type' => 'text'])
    && !AttributeResource::usesValues(null)
);

check('il riquadro dei valori c\'è sempre e segue il tipo', function () {
    $contenitore = (AttributeResource::formLayoutSchema()->components ?? [])[0] ?? null;
    $riquadri = $contenitore->components ?? [];
    $valori = $riquadri[1] ?? null;

    return count($riquadri) === 2
        && $valori !== null
        && $valori->getAttr('data-visible-when') === 'type'
        && $valori->getAttr('data-visible-when-values') === 'select,color,pattern,icon'
        && $valori->getAttr('data-wi-conditional-container') === 'true';
});

check('le colonne dei valori chiedono quello che serve al tipo', function () {
    $colonne = [];

    $contesto = (array) (AttributeResource::getInput('values')->get('context') ?? []);

    foreach ((array) ($contesto['columns'] ?? []) as $colonna) {
        $colonne[(string) $colonna->name] = $colonna;
    }

    return array_keys($colonne) === ['id', 'image', 'label', 'color', 'description']
        && $colonne['image']->conditionalAttributes() === [
            'data-visible-when' => 'type',
            'data-visible-when-values' => 'pattern,icon',
        ]
        && $colonne['color']->conditionalAttributes() === [
            'data-visible-when' => 'type',
            'data-visible-when-values' => 'color',
        ]
        // Il valore c'è per tutti e si allarga dove le altre mancano.
        && $colonne['label']->conditionalAttributes() === []
        && $colonne['label']->get('column_fill') === true;
});

check('l\'unità si vede solo su testo e numero', fn () =>
    AttributeResource::getInput('unit')->conditionalAttributes() === [
        'data-visible-when' => 'type',
        'data-visible-when-values' => 'number,text',
    ]
);

check('l\'unità nascosta si svuota al salvataggio', function () {
    $resource = new class extends AttributeResource {
        public static function valueCount(int $id): int { return 0; }
    };
    $salva = static fn (array $valori): array => $resource::mutateRequestValues(
        $valori,
        'update',
        'backend',
        ['id' => 1, 'type' => 'number', 'unit' => 'g']
    );

    return ($salva(['name' => 'Peso', 'type' => 'color', 'unit' => 'g'])['unit'] ?? null) === ''
        && ($salva(['name' => 'Peso', 'type' => 'pattern', 'unit' => 'g'])['unit'] ?? null) === ''
        && ($salva(['name' => 'Peso', 'type' => 'select'])['unit'] ?? null) === ''
        && ($salva(['name' => 'Peso', 'type' => 'number', 'unit' => 'g'])['unit'] ?? null) === 'g'
        && ($salva(['name' => 'Peso', 'type' => 'text', 'unit' => 'cm'])['unit'] ?? null) === 'cm';
});

check('da un tipo con valori a un altro i valori restano', function () {
    // Colori e immagini non si toccano: tornando indietro si ritrovano.
    $resource = new class extends AttributeResource {
        public static function valueCount(int $id): int { return 3; }
    };

    $valori = $resource::mutateRequestValues(
        ['name' => 'Tessuto', 'type' => 'pattern'],
        'update',
        'backend',
        ['id' => 1, 'type' => 'color']
    );

    return ($valori['type'] ?? '') === 'pattern' && !array_key_exists('values', $valori);
});

check('i valori si salvano nella loro tabella', function () {
    $relazione = AttributeResource::repeaterRelations()['values']['relation'] ?? null;

    return $relazione !== null
        && $relazione->table === AttributeValue::$table
        && $relazione->parentKey === 'attribute_id'
        && $relazione->positionKey === 'position';
});

check('il nome macchina dell\'attributo nasce dal nome e non si tocca più', function () {
    $nuovo = AttributeResource::mutateRequestValues(['name' => 'Colore'], 'store');
    $modifica = AttributeResource::mutateRequestValues(
        ['name' => 'Colore', 'slug' => 'altro'],
        'update',
        'backend',
        ['id' => 1]
    );

    return ($nuovo['slug'] ?? '') !== '' && !isset($modifica['slug']);
});

check('il tipo non cambia mentre ci sono dei valori', function () {
    // `valueCount()` è sovrascritto qui: conta la regola, non il database.
    $resource = new class extends AttributeResource {
        public static function valueCount(int $id): int { return 3; }
    };

    try {
        $resource::mutateRequestValues(
            ['name' => 'Colore', 'type' => 'text'],
            'update',
            'backend',
            ['id' => 1, 'type' => 'select']
        );
    } catch (UserError $errore) {
        return str_contains($errore->getMessage(), 'valori');
    }

    return false;
});

check('senza valori il tipo si cambia', function () {
    $resource = new class extends AttributeResource {
        public static function valueCount(int $id): int { return 0; }
    };

    $valori = $resource::mutateRequestValues(
        ['name' => 'Colore', 'type' => 'text'],
        'update',
        'backend',
        ['id' => 1, 'type' => 'select']
    );

    return ($valori['type'] ?? '') === 'text';
});

check('l\'uso si legge dalla casella che il tipo mostra', function () {
    $salva = static fn (array $valori, array $prima = ['id' => 1, 'type' => 'select', 'level' => 'product']): array
        => AttributeResource::withLevel($valori, (string) ($valori['type'] ?? ''), $prima);

    return ($salva(['type' => 'select', 'level' => 'variant', 'level_text' => 'model'])['level'] ?? '') === 'variant'
        && ($salva(['type' => 'number', 'level' => 'variant', 'level_text' => 'product'])['level'] ?? '') === 'product'
        && !array_key_exists('level_text', $salva(['type' => 'select', 'level' => 'model', 'level_text' => 'model']));
});

check('un testo con foto proprie si rifiuta, uno su ogni opzione passa', function () {
    try {
        AttributeResource::withLevel(['level_text' => 'variant'], 'text', ['id' => 1, 'type' => 'text', 'level' => 'model']);
    } catch (UserError $errore) {
        return str_contains($errore->getMessage(), 'non crea opzioni')
            && (AttributeResource::withLevel(['level_text' => 'product'], 'number', null)['level'] ?? '') === 'product';
    }

    return false;
});

check('l\'uso di un attributo già sugli articoli non cambia', function () {
    $resource = new class extends AttributeResource {
        protected static function isUsed(int $id): bool { return true; }
    };

    // Le caselle sono spente e non arrivano: resta l'uso di prima.
    $fermo = $resource::withLevel([], 'select', ['id' => 1, 'type' => 'select', 'level' => 'variant']);

    try {
        $resource::withLevel(['level' => 'product'], 'select', ['id' => 1, 'type' => 'select', 'level' => 'variant']);
    } catch (UserError $errore) {
        return ($fermo['level'] ?? '') === 'variant'
            && str_contains($errore->getMessage(), 'crea un attributo nuovo');
    }

    return false;
});

check('le due caselle dell\'uso seguono il tipo', fn () =>
    AttributeResource::getInput('level')->conditionalAttributes()['data-visible-when-values'] === 'select,color,pattern,icon'
    && AttributeResource::getInput('level_text')->conditionalAttributes()['data-visible-when-values'] === 'number,text'
    && array_keys((array) AttributeResource::getInput('level_text')->get('options')) === ['model', 'product']
);

check('un padre che non esiste più si ferma con una frase', function () {
    $righe = [
        ['id' => 1, 'parent_id' => 0, 'name' => 'Abbigliamento', 'position' => 1],
        ['id' => 3, 'parent_id' => 1, 'name' => 'Scarpe', 'position' => 1],
    ];

    try {
        CategoryResource::assertParentExists($righe, 2);
    } catch (UserError $errore) {
        return str_contains($errore->getMessage(), 'non esiste');
    }

    return false;
});

check('un padre che c\'è passa', function () {
    $righe = [['id' => 1, 'parent_id' => 0, 'name' => 'Abbigliamento', 'position' => 1]];

    CategoryResource::assertParentExists($righe, 1);

    return true;
});

summary();
