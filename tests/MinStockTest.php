<?php
/** php tests/MinStockTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductResource;

// Lo stato delle funzionalità si forza senza database: `features()` è
// memoizzato. `null` torna a leggerlo (e senza database è tutto spento).
$forza = static function (?array $stato): void {
    (new ReflectionProperty(Gestionale::class, 'features'))->setValue(null, $stato);
};

$chiavi = static function (string $resource): array {
    $nomi = [];

    foreach ($resource::formSchema() as $field) {
        $nomi[] = (string) $field->name;
    }

    return $nomi;
};

$rifiutata = static function (mixed $valore): bool {
    try {
        ProductModelResource::minStockValue($valore);
    } catch (InvalidArgumentException $errore) {
        return str_contains($errore->getMessage(), 'scorta minima');
    }

    return false;
};

check('una scorta minima vuota vale zero, cioè nessun avviso', fn () =>
    ProductModelResource::minStockValue('') === '0.000'
    && ProductModelResource::minStockValue(null) === '0.000'
    && ProductModelResource::minStockValue('0') === '0.000'
);

check('la scorta minima si legge come la giacenza', fn () =>
    ProductModelResource::minStockValue('5') === '5.000'
    && ProductModelResource::minStockValue('2,5') === '2.500'
    && ProductModelResource::minStockValue('20.000') === '20.000'
    && ProductModelResource::minStockValue('1.234,5') === '1234.500'
    && ProductModelResource::minStockValue(' 3 ') === '3.000'
);

check('negativi, testo e liste non passano, e il messaggio dice cosa scrivere', fn () =>
    $rifiutata('-1') && $rifiutata('abc') && $rifiutata(['5'])
);

check('con gli avvisi bloccati la scorta minima non compare in nessuna scheda', function () use ($forza, $chiavi) {
    $forza(['low_stock_alerts' => false]);

    return !in_array('product_min_stock', $chiavi(ProductModelResource::class), true)
        && !in_array('min_stock_quantity', $chiavi(ProductResource::class), true);
});

check('con gli avvisi sbloccati la scorta minima c\'è in tutte e due', function () use ($forza, $chiavi) {
    $forza(['low_stock_alerts' => true]);

    return in_array('product_min_stock', $chiavi(ProductModelResource::class), true)
        && in_array('min_stock_quantity', $chiavi(ProductResource::class), true);
});

check('la scheda della versione salva la soglia col punto', function () use ($forza) {
    $forza(['low_stock_alerts' => true]);
    $valori = ProductResource::mutateRequestValues(['min_stock_quantity' => '2,5'], 'update', 'backend', ['id' => 1]);

    return ($valori['min_stock_quantity'] ?? null) === '2.500';
});

check('con gli avvisi bloccati la soglia non si scrive, nemmeno da un form vecchio', function () use ($forza) {
    $forza(['low_stock_alerts' => false]);
    $valori = ProductResource::mutateRequestValues(['min_stock_quantity' => '9'], 'update', 'backend', ['id' => 1]);

    return !array_key_exists('min_stock_quantity', $valori);
});

check('la scheda della versione rifiuta una soglia negativa', function () use ($forza) {
    $forza(['low_stock_alerts' => true]);

    try {
        ProductResource::mutateRequestValues(['min_stock_quantity' => '-2'], 'update', 'backend', ['id' => 1]);
    } catch (InvalidArgumentException $errore) {
        return str_contains($errore->getMessage(), 'scorta minima');
    }

    return false;
});

$forza(null);

summary();
