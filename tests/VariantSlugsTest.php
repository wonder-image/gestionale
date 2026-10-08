<?php
/** php tests/VariantSlugsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Catalog\VariantSlugs;

check('gli slug in stile vecchio diventano il valore della variante', fn () =>
    VariantSlugs::compute([
        ['id' => 10, 'label' => 'Blu', 'slug' => 'blu-51167-3'],
        ['id' => 11, 'label' => 'Rosso', 'slug' => 'rosso-51167-4'],
    ]) === [10 => 'blu', 11 => 'rosso']
);

check('due valori uguali nello stesso modello: il secondo per posizione prende -2', fn () =>
    VariantSlugs::compute([
        ['id' => 10, 'label' => 'Blu', 'slug' => ''],
        ['id' => 11, 'label' => 'Blu', 'slug' => ''],
    ]) === [10 => 'blu', 11 => 'blu-2']
);

check('chi ha già uno slug giusto lo tiene: niente scambi tra blu e blu-2', fn () =>
    VariantSlugs::compute([
        ['id' => 10, 'label' => 'Blu', 'slug' => 'blu-2'],
        ['id' => 11, 'label' => 'Blu', 'slug' => 'blu'],
    ]) === [10 => 'blu-2', 11 => 'blu']
);

check('lo scheletro senza valore resta vuoto, i simboli diventano variante', fn () =>
    VariantSlugs::compute([
        ['id' => 10, 'label' => '', 'slug' => 'maglietta-51167'],
        ['id' => 11, 'label' => '★', 'slug' => ''],
    ]) === [10 => '', 11 => 'variante']
);

check('gli slug delle varianti cancellate restano presi', fn () =>
    VariantSlugs::compute([['id' => 10, 'label' => 'Blu', 'slug' => 'blu']], ['blu']) === [10 => 'blu-2']
);

check('rifatto sul suo risultato non cambia nulla', function () {
    $varianti = [
        ['id' => 10, 'label' => 'Blu', 'slug' => 'x'],
        ['id' => 11, 'label' => 'Blu', 'slug' => 'y'],
        ['id' => 12, 'label' => 'Novit&agrave;', 'slug' => 'z'],
    ];
    $primo = VariantSlugs::compute($varianti, ['blu-2']);

    foreach ($varianti as $i => $variante) {
        $varianti[$i]['slug'] = $primo[$variante['id']];
    }

    return $primo === [10 => 'blu', 11 => 'blu-3', 12 => 'novita']
        && VariantSlugs::compute($varianti, ['blu-2']) === $primo;
});

summary();
