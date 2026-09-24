<?php
/** php tests/AttributeQuickCreateTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Backend\Support\ResourceFormLayoutRenderer;
use Wonder\Elements\Components\Container;
use Wonder\Plugin\Gestionale\Resources\Catalog\AttributeResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

/*
 * «Nuova caratteristica» dalla scheda prodotto: il modal parla allo store API
 * degli attributi. Da lì nasce solo una caratteristica della scheda tecnica —
 * un Testo o un Numero, sull'articolo, visibile e fuori dai filtri — qualunque
 * cosa arrivi nella richiesta.
 */

$rapido = static fn (array $valori): array => AttributeResource::mutateRequestValues($valori, 'store', 'api');

/** La chiave dell'errore con cui la creazione rapida si ferma, '' se passa. */
$rifiuto = static function (array $valori) use ($rapido): string {
    try {
        $rapido($valori);
    } catch (UserError $errore) {
        return $errore->key();
    }

    return '';
};

check('lo store API degli attributi è aperto al modal, e solo quello', function () {
    $schema = AttributeResource::apiSchema()->toArray();

    return ($schema['enabled'] ?? false) === true
        && ($schema['routes']['store'] ?? false) === true
        && array_keys(array_filter((array) ($schema['routes'] ?? []))) === ['store']
        && ($schema['fields']['store'] ?? []) === ['name', 'type', 'unit'];
});

check('la risorsa si riconosce dal suo nome nell\'evento della creazione rapida', fn () =>
    AttributeResource::slug() === 'app-gestionale-attributi'
);

check('il modal propone solo Testo e Numero', fn () =>
    AttributeResource::QUICK_TYPES === ['text', 'number']
    && AttributeResource::quickTypes() === ['text' => 'Testo', 'number' => 'Numero']
);

check('una caratteristica nasce sull\'articolo, visibile e fuori dai filtri', function () use ($rapido) {
    $valori = $rapido(['name' => 'Diametro', 'type' => 'number', 'unit' => 'mm']);

    return $valori['name'] === 'Diametro'
        && $valori['type'] === 'number'
        && $valori['unit'] === 'mm'
        && $valori['level'] === 'model'
        && $valori['is_filterable'] === 'false'
        && $valori['is_visible'] === 'true'
        && ($valori['slug'] ?? '') !== ''
        && ($valori['position'] ?? 0) >= 1
        // La casella dell'uso del form non è una colonna.
        && !array_key_exists('level_text', $valori);
});

check('senza tipo è un Testo, senza unità non ne ha', function () use ($rapido) {
    $valori = $rapido(['name' => 'Fodera']);

    return $valori['type'] === 'text' && $valori['unit'] === '' && $valori['level'] === 'model';
});

check('uso, filtro e stato li decide il server, non la richiesta', function () use ($rapido) {
    $valori = $rapido([
        'name' => 'Materiale',
        'type' => 'text',
        'level' => 'variant',
        'level_text' => 'product',
        'is_filterable' => 'true',
        'is_visible' => 'false',
        'group_name' => 'Altro',
        'position' => 99,
    ]);

    return $valori['level'] === 'model'
        && $valori['is_filterable'] === 'false'
        && $valori['is_visible'] === 'true'
        && !array_key_exists('group_name', $valori)
        && $valori['position'] !== 99;
});

check('un attributo con dei valori non nasce dalla scheda prodotto', function () use ($rifiuto) {
    foreach (['select', 'color', 'pattern', 'icon', 'altro'] as $tipo) {
        if ($rifiuto(['name' => 'Colore', 'type' => $tipo]) !== 'attribute.quick_type') {
            return false;
        }
    }

    return true;
});

check('il rifiuto del tipo dice dove si crea un elenco', function () {
    try {
        AttributeResource::mutateRequestValues(['name' => 'Colore', 'type' => 'color'], 'store', 'api');
    } catch (UserError $errore) {
        return str_contains($errore->getMessage(), 'Catalogo → Attributi');
    }

    return false;
});

check('senza nome non nasce niente', function () use ($rifiuto) {
    return $rifiuto(['type' => 'text']) === 'attribute.quick_name'
        && $rifiuto(['name' => '   ', 'type' => 'number']) === 'attribute.quick_name';
});

check('un\'unità fuori dall\'elenco si rifiuta', function () use ($rifiuto) {
    return $rifiuto(['name' => 'Peso', 'type' => 'number', 'unit' => 'libbre']) === 'attribute.quick_unit'
        && $rifiuto(['name' => 'Peso', 'type' => 'number', 'unit' => 'g']) === '';
});

check('il nome arriva senza spazi attorno', function () use ($rapido) {
    return $rapido(['name' => '  Diametro  ', 'type' => 'number'])['name'] === 'Diametro';
});

check('dalla scheda dell\'attributo tutto resta come prima', function () {
    $valori = AttributeResource::mutateRequestValues(
        ['name' => 'Colore', 'type' => 'color', 'level' => 'variant', 'is_filterable' => 'true'],
        'store',
        'backend'
    );

    return $valori['type'] === 'color'
        && $valori['level'] === 'variant'
        && $valori['is_filterable'] === 'true';
});

check('dallo store API non nascono valori, anche se la richiesta li porta', function () {
    return AttributeResource::syncRepeaterRelations(
        5,
        ['values' => [['label' => 'Rosso'], ['label' => 'Blu']]],
        [],
        'store',
        'api'
    ) === [];
});

check('il modal chiede nome, tipo e unità, e porta nascosto il resto', function () {
    $campi = [];

    foreach (AttributeResource::quickCreateFields() as $campo) {
        $campi[(string) $campo->name] = $campo;
    }

    $tipo = $campi['type'] ?? null;

    return array_keys($campi) === ['name', 'type', 'unit', 'level_text', 'is_filterable', 'is_visible']
        && $tipo !== null
        && (array) $tipo->get('options') === AttributeResource::quickTypes();
});

check('il modal si disegna con i soli tipi Testo e Numero e l\'uso nascosto sull\'articolo', function () {
    $html = ResourceFormLayoutRenderer::renderLayout(
        (new Container)->columns(12)->components(AttributeResource::quickCreateFields())
    );

    preg_match('/<select[^>]*name="type"[^>]*>(.*?)<\/select>/s', $html, $tipo);
    preg_match_all('/<option value="([^"]*)"/', $tipo[1] ?? '', $opzioni);

    return ($opzioni[1] ?? []) === ['text', 'number']
        && str_contains($html, 'name="unit"')
        && preg_match('/<input type="hidden" name="level_text" value="model"/', $html) === 1
        && preg_match('/<input type="hidden" name="is_filterable" value="false"/', $html) === 1
        && preg_match('/<input type="hidden" name="is_visible" value="true"/', $html) === 1
        && !str_contains($html, '<textarea');
});

summary();
