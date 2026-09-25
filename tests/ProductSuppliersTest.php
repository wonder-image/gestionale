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
    // Il preferito posta sempre il suo «No»: da solo non tiene in piedi una
    // riga.
    return ProductSuppliers::normalize([
        ['supplier_id' => '', 'supplier_sku' => ' ', 'cost' => '', 'is_preferred' => 'false'],
        ['supplier_id' => '0', 'supplier_sku' => '', 'cost' => null, 'is_preferred' => 'true'],
        'non è una riga',
    ]) === [];
});

check('le righe arrivano nella forma che si salva', function () {
    // Come le manda la finestra dei costi: JSON, con numeri e booleani veri.
    $righe = json_decode('[{"supplier_id":"5","supplier_sku":" FN-12 ","cost":"12,50","is_preferred":true},'
        .'{"supplier_id":6,"cost":7,"is_preferred":false}]', true);

    return ProductSuppliers::normalize($righe) === [
        ['supplier_id' => 5, 'supplier_sku' => 'FN-12', 'cost' => 12.5, 'is_preferred' => 'true'],
        ['supplier_id' => 6, 'supplier_sku' => '', 'cost' => 7.0, 'is_preferred' => 'false'],
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

check('un preferito solo: vince il primo segnato', function () {
    $righe = ProductSuppliers::normalize([
        ['supplier_id' => 1, 'is_preferred' => 'false'],
        ['supplier_id' => 2, 'is_preferred' => 'true'],
        ['supplier_id' => 3, 'is_preferred' => true],
    ]);

    return array_column($righe, 'is_preferred') === ['false', 'true', 'false'];
});

check('senza un preferito diventa preferito il primo legato', function () {
    $righe = ProductSuppliers::normalize([
        // Il preferito di una riga vuota se ne va con la riga.
        ['supplier_id' => '', 'is_preferred' => 'true'],
        ['supplier_id' => 7, 'cost' => '4,00'],
        ['supplier_id' => 8, 'cost' => '3,00'],
    ]);

    return array_column($righe, 'is_preferred') === ['true', 'false']
        && array_column($righe, 'supplier_id') === [7, 8];
});

check('il preferito si sistema senza perdere le altre colonne della riga', fn () =>
    ProductSuppliers::preferOne([
        ['id' => '12', 'supplier_id' => 1, 'is_preferred' => 'false'],
        ['id' => '', 'supplier_id' => 2, 'is_preferred' => 'false'],
    ]) === [
        ['id' => '12', 'supplier_id' => 1, 'is_preferred' => 'true'],
        ['id' => '', 'supplier_id' => 2, 'is_preferred' => 'false'],
    ]
);

check('il preferito di un elenco è quello segnato, o il primo', fn () =>
    ProductSuppliers::preferred([
        ['supplier_id' => 1, 'is_preferred' => 'false'],
        ['supplier_id' => 2, 'is_preferred' => 'true'],
    ])['supplier_id'] === 2
    && ProductSuppliers::preferred([['supplier_id' => 3, 'is_preferred' => 'false']])['supplier_id'] === 3
    && ProductSuppliers::preferred([]) === null
);

check('codice o costo senza fornitore non si salvano', fn () =>
    $rifiuto([['supplier_id' => '', 'supplier_sku' => 'FN-12']], [5]) === 'product.supplier_missing'
    && $rifiuto([['supplier_id' => '', 'cost' => '12,00']], [5]) === 'product.supplier_missing'
    // Le righe grezze del form: il «No» del preferito non è un dato.
    && $rifiuto([['supplier_id' => '', 'supplier_sku' => '', 'cost' => '', 'is_preferred' => 'false']], [5]) === ''
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
        ['supplier_id' => 5, 'supplier_sku' => 'FN-12', 'cost' => '12,00', 'is_preferred' => 'true'],
        ['supplier_id' => 6, 'supplier_sku' => '', 'cost' => '', 'is_preferred' => 'false'],
    ], [5, 6]) === ''
);

check('il riassunto accanto al bottone dice chi è il preferito e quanto costa', function () {
    $nomi = [5 => 'Filati Nord'];

    return ProductSuppliers::summary(['supplier_id' => 5, 'cost' => 12.0], $nomi) === 'Filati Nord · 12,00 €'
        && ProductSuppliers::summary(['supplier_id' => 5, 'cost' => '1234.5'], $nomi) === 'Filati Nord · 1.234,50 €'
        && ProductSuppliers::summary(['supplier_id' => 5, 'cost' => null], $nomi) === 'Filati Nord'
        && ProductSuppliers::summary(null, $nomi) === 'Nessun fornitore';
});

check('senza database non c\'è niente da leggere né da togliere', fn () =>
    ProductSuppliers::linksFor([1, 2]) === []
    && ProductSuppliers::linksFor([]) === []
    && ProductSuppliers::dropFor([1]) === 0
    && ProductSuppliers::dropFor([0, -3]) === 0
    && ProductSuppliers::dropRemovedOptions(1) === 0
    && ProductSuppliers::dropForRemovedProducts(1) === 0
    && ProductSuppliers::countForSupplier(1) === 0
    && ProductSuppliers::countForSupplier(0) === 0
);

summary();
