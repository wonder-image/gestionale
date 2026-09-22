<?php
/** php tests/AttributeValueResourceTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Resources\Catalog\AttributeValueResource;

check('lo store API accetta attributo e valore, e solo quelli', function () {
    $schema = AttributeValueResource::apiSchema()->toArray();

    return ($schema['enabled'] ?? false) === true
        && ($schema['routes']['store'] ?? false) === true
        && ($schema['routes']['index'] ?? true) === false
        && ($schema['routes']['destroy'] ?? true) === false
        && ($schema['fields']['store'] ?? []) === ['attribute_id', 'label'];
});

check('la pagina non sta nel menu', function () {
    return (AttributeValueResource::navigationSchema()->toArray()['enabled'] ?? true) === false;
});

check('il form ha i due campi che servono al modal', function () {
    $chiavi = array_map(
        static fn ($campo) => (string) $campo->name,
        AttributeValueResource::formSchema()
    );

    return $chiavi === ['attribute_id', 'label'];
});

check('il valore si scrive in fondo all\'elenco del suo attributo', function () {
    $valori = AttributeValueResource::mutateRequestValues(
        ['attribute_id' => '3', 'label' => 'Blu notte'],
        'store'
    );

    return ($valori['position'] ?? 0) >= 1
        && $valori['label'] === 'Blu notte'
        && $valori['attribute_id'] === '3';
});

check('senza attributo non si inventa una posizione', function () {
    $valori = AttributeValueResource::mutateRequestValues(['label' => 'Orfano'], 'store');

    return !isset($valori['position']);
});

check('in aggiornamento la posizione non si tocca', function () {
    $valori = AttributeValueResource::mutateRequestValues(
        ['label' => 'Blu notte', 'position' => 9],
        'update'
    );

    return !isset($valori['position']);
});

summary();
