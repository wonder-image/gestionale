<?php
/** php tests/ProductSuppliersTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Purchasing\ProductSuppliers;

// La chiave dell'errore che `assertValid()` lancia, '' se non ne lancia.
$rifiuto = static function (array $righe, array $ammessi, array $nomi = []): string {
    try {
        ProductSuppliers::assertValid($righe, $ammessi, $nomi);
    } catch (UserError $errore) {
        // Una chiave senza testo si vedrebbe così com'è: vale come fallito.
        return $errore->getMessage() !== $errore->key() ? $errore->key() : 'senza testo: '.$errore->key();
    }

    return '';
};

check('una riga senza fornitore, codice e costo non è un legame', function () {
    // Il repeater posta anche l'`id` della riga: da solo non tiene in piedi
    // una riga.
    return ProductSuppliers::normalize([
        ['supplier_id' => '', 'supplier_sku' => ' ', 'cost' => '', 'id' => '12'],
        ['supplier_id' => '0', 'supplier_sku' => '', 'cost' => null],
        'non è una riga',
    ]) === [];
});

check('le righe arrivano nella forma che si salva', function () {
    // Come le manda il repeater: stringhe, con gli spazi di chi scrive.
    $righe = [
        ['supplier_id' => '5', 'supplier_sku' => ' FN-12 ', 'cost' => '12,50', 'id' => '3'],
        ['supplier_id' => 6, 'cost' => 7],
    ];

    return ProductSuppliers::normalize($righe) === [
        ['supplier_id' => 5, 'supplier_sku' => 'FN-12', 'cost' => 12.5],
        ['supplier_id' => 6, 'supplier_sku' => '', 'cost' => 7.0],
    ];
});

check('il costo si legge come lo scrive una persona, e vuoto vuol dire «non lo so»', function () {
    $costi = array_column(ProductSuppliers::normalize([
        ['supplier_id' => 1, 'cost' => '1.234,50 €'],
        ['supplier_id' => 2, 'cost' => '12.5'],
        ['supplier_id' => 3, 'cost' => 3.25],
        ['supplier_id' => 4, 'cost' => ''],
        ['supplier_id' => 5],
    ]), 'cost');

    // Uno zero farebbe del fornitore il più conveniente.
    return $costi === [1234.5, 12.5, 3.25, null, null];
});

check('codice o costo senza fornitore non si salvano', fn () =>
    $rifiuto([['supplier_id' => '', 'supplier_sku' => 'FN-12']], [5]) === 'product.supplier_missing'
    && $rifiuto([['supplier_id' => '', 'cost' => '12,00']], [5]) === 'product.supplier_missing'
    // Le righe grezze del repeater: l'`id` della riga non è un dato.
    && $rifiuto([['supplier_id' => '', 'supplier_sku' => '', 'cost' => '', 'id' => '4']], [5]) === ''
);

check('un fornitore che la pagina non propone viene rifiutato', fn () =>
    $rifiuto([['supplier_id' => '9', 'cost' => '1,00']], [5, 6]) === 'product.supplier_invalid'
);

check('un costo sotto zero viene rifiutato', fn () =>
    $rifiuto([['supplier_id' => 5, 'cost' => '-3,00']], [5]) === 'product.supplier_cost_negative'
    && $rifiuto([['supplier_id' => 5, 'cost' => '0']], [5]) === ''
);

// Un costo che non è un numero non diventa «non lo so» in silenzio: chi ha
// scritto «12..5» crede di aver salvato un costo.
check('un costo che non è un numero viene rifiutato, non svuotato', fn () =>
    $rifiuto([['supplier_id' => 5, 'cost' => '12..5']], [5]) === 'product.supplier_cost_invalid'
    && $rifiuto([['supplier_id' => 5, 'cost' => 'dodici']], [5]) === 'product.supplier_cost_invalid'
    && $rifiuto([['supplier_id' => 5, 'cost' => ['12']]], [5]) === 'product.supplier_cost_invalid'
    && $rifiuto([['supplier_id' => 5, 'cost' => '12,50 €']], [5]) === ''
    && $rifiuto([['supplier_id' => 5, 'cost' => '  ']], [5]) === ''
);

// La colonna è DECIMAL(12,4): oltre, MySQL rifiuta la riga a metà
// salvataggio, dopo che la scheda è già stata scritta.
check('un costo oltre quello che la colonna tiene viene rifiutato prima di salvare', fn () =>
    $rifiuto([['supplier_id' => 5, 'cost' => '123456789']], [5]) === 'product.supplier_cost_too_high'
    && $rifiuto([['supplier_id' => 5, 'cost' => '1e20']], [5]) === 'product.supplier_cost_too_high'
    && $rifiuto([['supplier_id' => 5, 'cost' => '99999999,99995']], [5]) === 'product.supplier_cost_too_high'
    && $rifiuto([['supplier_id' => 5, 'cost' => '99.999.999,99']], [5]) === ''
    && $rifiuto([['supplier_id' => 5, 'cost' => '99999999,9999']], [5]) === ''
);

// Anche il codice ha una colonna da cento caratteri.
check('un codice del fornitore oltre cento caratteri viene rifiutato', fn () =>
    $rifiuto([['supplier_id' => 5, 'supplier_sku' => str_repeat('A', 101)]], [5]) === 'product.supplier_sku_too_long'
    && $rifiuto([['supplier_id' => 5, 'supplier_sku' => str_repeat('è', 100)]], [5]) === ''
    && $rifiuto([['supplier_id' => 5, 'supplier_sku' => '  '.str_repeat('A', 100).'  ']], [5]) === ''
);

check('lo stesso fornitore due volte viene rifiutato, con il suo nome', function () use ($rifiuto) {
    $righe = [['supplier_id' => 5, 'cost' => '1,00'], ['supplier_id' => '5', 'supplier_sku' => 'X']];

    try {
        ProductSuppliers::assertValid($righe, [5], [5 => 'Filati Nord']);
    } catch (UserError $errore) {
        $conNome = $errore->key() === 'product.supplier_duplicate'
            && str_contains($errore->getMessage(), 'Filati Nord')
            && !str_contains($errore->getMessage(), '{{');
    }

    try {
        ProductSuppliers::assertValid($righe, [5]);
    } catch (UserError $errore) {
        // Senza nome, almeno il numero: meglio di un segnaposto.
        $senzaNome = str_contains($errore->getMessage(), '5') && !str_contains($errore->getMessage(), '{{');
    }

    return ($conNome ?? false) && ($senzaNome ?? false);
});

check('righe corrette passano', fn () =>
    $rifiuto([
        ['supplier_id' => 5, 'supplier_sku' => 'FN-12', 'cost' => '12,00'],
        ['supplier_id' => 6, 'supplier_sku' => '', 'cost' => ''],
    ], [5, 6]) === ''
);

// Con un fornitore solo la scheda ha due campi, senza tendina: il fornitore
// è quello, e i due campi vuoti vogliono dire «non si compra da lui».
check('i due campi vuoti non sono un legame, uno compilato sì', fn () =>
    ProductSuppliers::fromFields(5, '', '') === []
    && ProductSuppliers::fromFields(5, '  ', null) === []
    && ProductSuppliers::fromFields(5, ' FN-12 ', '') === [['supplier_id' => 5, 'supplier_sku' => ' FN-12 ', 'cost' => '']]
    && ProductSuppliers::fromFields(5, '', '12,50') === [['supplier_id' => 5, 'supplier_sku' => '', 'cost' => '12,50']]
    // Senza fornitore non c'è niente da legare.
    && ProductSuppliers::fromFields(0, 'FN-12', '12,50') === []
);

check('i due campi arrivano grezzi, così un costo sbagliato si vede', fn () =>
    $rifiuto(ProductSuppliers::fromFields(5, '', '12..5'), [5]) === 'product.supplier_cost_invalid'
    && $rifiuto(ProductSuppliers::fromFields(5, str_repeat('A', 101), ''), [5]) === 'product.supplier_sku_too_long'
    && $rifiuto(ProductSuppliers::fromFields(5, 'FN-12', '12,50'), [5]) === ''
);

// I due campi sono del fornitore unico: gli altri legami dell'opzione non
// si toccano.
check('i due campi scrivono solo il loro fornitore, al suo posto', fn () =>
    ProductSuppliers::replaceOne([
        ['supplier_id' => 5, 'supplier_sku' => 'FN-12', 'cost' => 12.0],
        ['supplier_id' => 6, 'supplier_sku' => '', 'cost' => 11.5],
    ], 5, ProductSuppliers::fromFields(5, 'FN-13', '13,00')) === [
        ['supplier_id' => 5, 'supplier_sku' => 'FN-13', 'cost' => 13.0],
        ['supplier_id' => 6, 'supplier_sku' => '', 'cost' => 11.5],
    ]
);

check('i due campi vuoti tolgono solo il loro fornitore', fn () =>
    ProductSuppliers::replaceOne([
        ['supplier_id' => 5, 'supplier_sku' => 'FN-12', 'cost' => 12.0],
        ['supplier_id' => 6, 'supplier_sku' => '', 'cost' => 11.5],
    ], 5, []) === [['supplier_id' => 6, 'supplier_sku' => '', 'cost' => 11.5]]
    && ProductSuppliers::replaceOne([], 5, []) === []
);

check('un fornitore che l\'opzione non aveva si accoda', fn () =>
    ProductSuppliers::replaceOne(
        [['supplier_id' => 6, 'supplier_sku' => '', 'cost' => 11.5]],
        5,
        ProductSuppliers::fromFields(5, '', '2')
    ) === [
        ['supplier_id' => 6, 'supplier_sku' => '', 'cost' => 11.5],
        ['supplier_id' => 5, 'supplier_sku' => '', 'cost' => 2.0],
    ]
);

// La finestra manda le righe in un campo nascosto.
check('il JSON della finestra diventa righe, e vuoto vuol dire «non toccare»', fn () =>
    ProductSuppliers::fromJson('[{"supplier_id":5,"supplier_sku":"FN-12","cost":"12.00"},"x",{"supplier_id":"6"}]') === [
        ['supplier_id' => 5, 'supplier_sku' => 'FN-12', 'cost' => '12.00'],
        ['supplier_id' => '6'],
    ]
    && ProductSuppliers::fromJson('[]') === []
    && ProductSuppliers::fromJson('') === null
    && ProductSuppliers::fromJson('  ') === null
    && ProductSuppliers::fromJson(null) === null
    && ProductSuppliers::fromJson(['supplier_id' => 5]) === null
    && ProductSuppliers::fromJson('{"supplier_id":5}') === null
    && ProductSuppliers::fromJson('[{') === null
);

// Il costo si scrive con due decimali e si tiene con quattro (P91).
check('il costo non toccato resta quello salvato, con i suoi quattro decimali', fn () =>
    ProductSuppliers::keepStoredCosts([
        ['supplier_id' => '5', 'supplier_sku' => 'FN-12', 'cost' => '12.35'],
        ['supplier_id' => 6, 'cost' => '11,50'],
        ['supplier_id' => 7, 'cost' => ''],
        ['supplier_id' => 8, 'cost' => '3,00'],
    ], [
        ['supplier_id' => 5, 'supplier_sku' => 'FN-12', 'cost' => 12.3456],
        ['supplier_id' => 6, 'supplier_sku' => '', 'cost' => 11.4],
        ['supplier_id' => 7, 'supplier_sku' => '', 'cost' => 9.1234],
    ]) === [
        ['supplier_id' => '5', 'supplier_sku' => 'FN-12', 'cost' => '12.3456'],
        ['supplier_id' => 6, 'cost' => '11,50'],
        // Svuotato vuol dire «non lo so»: non torna quello di prima.
        ['supplier_id' => 7, 'cost' => ''],
        ['supplier_id' => 8, 'cost' => '3,00'],
    ]
);

// Il riassunto accanto al bottone «Fornitori».
check('il riassunto dice i fornitori, con il costo solo dove c\'è', fn () =>
    ProductSuppliers::summary([
        ['supplier_id' => 5, 'supplier_sku' => 'FN-12', 'cost' => 12.0],
        ['supplier_id' => 6, 'supplier_sku' => '', 'cost' => null],
    ], [5 => 'Filati Nord', 6 => 'Lana Sud']) === 'Filati Nord 12,00 € · Lana Sud'
    && ProductSuppliers::summary([['supplier_id' => '5', 'cost' => '1.234,5']], [5 => 'Filati Nord']) === 'Filati Nord 1.234,50 €'
    && ProductSuppliers::summary([['supplier_id' => 5, 'cost' => '0']], [5 => 'Filati Nord']) === 'Filati Nord 0,00 €'
);

check('senza righe il riassunto lo dice, e un fornitore senza nome ha il numero', fn () =>
    ProductSuppliers::summary([], [5 => 'Filati Nord']) === 'Nessun fornitore'
    && ProductSuppliers::summary([['supplier_id' => '', 'cost' => '']], []) === 'Nessun fornitore'
    && ProductSuppliers::summary([['supplier_id' => 9, 'cost' => '']], [5 => 'Filati Nord']) === 'Fornitore n. 9'
    // Lo stesso fornitore due volte non arriva al salvataggio: vale il primo.
    && ProductSuppliers::summary([['supplier_id' => 5, 'cost' => 1], ['supplier_id' => 5, 'cost' => 2]], [5 => 'Filati Nord']) === 'Filati Nord 1,00 €'
);

// I fornitori sono dell'opzione (P107): i due livelli non ci sono più.
check('non c\'è più niente da sommare fra articolo e opzione', function () {
    foreach (['effective', 'modelLinksFor', 'syncModel', 'dropForModels', 'dropForRemovedModels'] as $metodo) {
        if (method_exists(ProductSuppliers::class, $metodo)) {
            return false;
        }
    }

    return !class_exists(\Wonder\Plugin\Gestionale\Models\Catalog\ProductModelSupplier::class);
});

check('senza database non c\'è niente da leggere né da togliere', fn () =>
    ProductSuppliers::linksFor([1, 2]) === []
    && ProductSuppliers::linksFor([]) === []
    && ProductSuppliers::dropFor([1]) === 0
    && ProductSuppliers::dropFor([0, -3]) === 0
    && ProductSuppliers::dropRemovedOptions(1) === 0
    && ProductSuppliers::dropForRemovedProducts(1) === 0
    && ProductSuppliers::dropForRemovedProducts(0) === 0
    && ProductSuppliers::countForSupplier(1) === 0
    && ProductSuppliers::countForSupplier(0) === 0
);

summary();
