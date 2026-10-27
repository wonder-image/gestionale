<?php
/** php tests/VatCollectabilityTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Custom\Fattura\Fattura;
use Wonder\Plugin\Gestionale\Support\Tax\VatCollectability;

check('solo I, D e S sono valide, anche scritte in minuscolo', fn () =>
    VatCollectability::valid(' s ') === 'S'
    && VatCollectability::valid('D') === 'D'
    && VatCollectability::valid('') === null
    && VatCollectability::valid('X') === null
);

check('con la scissione si incassa il totale meno l\'IVA, bollo compreso', fn () =>
    VatCollectability::payable(['vat_collectability' => 'S', 'total' => '124.00', 'tax_total' => '22.00']) === 102.0
);

check('immediata e differita incassano il totale', fn () =>
    VatCollectability::payable(['vat_collectability' => 'I', 'total' => '122.00', 'tax_total' => '22.00']) === 122.0
    && VatCollectability::payable(['vat_collectability' => 'D', 'total' => '122.00', 'tax_total' => '22.00']) === 122.0
);

check('l\'esigibilità dell\'ordine arriva in fattura', function () {
    $fattura = VatCollectability::toInvoice(new Fattura(), ['vat_collectability' => 'S']);
    $dati = (new ReflectionProperty($fattura, 'default'))->getValue($fattura);

    return ($dati['EsigibilitaIVA'] ?? null) === 'S';
});

summary();
