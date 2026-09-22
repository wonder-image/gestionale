<?php
/** php tests/StockPagesTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Resources\Stock\StockAdjustmentResource;
use Wonder\Plugin\Gestionale\Support\Stock\Reasons;

$campi = static function (string $resource): array {
    $keys = [];

    foreach ($resource::formSchema() as $field) {
        $keys[] = (string) $field->name;
    }

    return $keys;
};

check('la rettifica ha il suo indirizzo ed è una pagina-form', fn () =>
    StockAdjustmentResource::path() === 'app/gestionale/rettifica'
    && StockAdjustmentResource::isFormPage() === true
);

check('la rettifica non sta nel menu: ci si arriva da una riga', fn () =>
    StockAdjustmentResource::navigationSchema()->toArray()['enabled'] === false
);

check('chiede quantità, causale e nota, e come leggerla', function () use ($campi) {
    $keys = $campi(StockAdjustmentResource::class);

    return in_array('mode', $keys, true)
        && in_array('quantity', $keys, true)
        && in_array('reason', $keys, true)
        && in_array('note', $keys, true);
});

check('le causali sono quelle vere, con l\'inventario già scelto', function () {
    foreach (StockAdjustmentResource::formSchema() as $field) {
        if ((string) $field->name === 'reason') {
            // Un select tiene le voci sotto `options`.
            return array_keys((array) $field->get('options')) === array_keys(Reasons::all())
                && $field->get('value') === Reasons::DEFAULT;
        }
    }

    return false;
});

check('il link porta la versione e la strada del ritorno', function () {
    $url = StockAdjustmentResource::urlFor(7, '/backend/app/gestionale/giacenze/?p=2');

    return str_contains($url, 'versione=7')
        && str_contains($url, 'torna=');
});

check('la strada del ritorno accetta solo indirizzi di questo backend', fn () =>
    // Un `torna=https://altrove.example` sarebbe un redirect aperto.
    StockAdjustmentResource::backUrlFrom('https://altrove.example/x') === ''
    && StockAdjustmentResource::backUrlFrom('/backend/app/gestionale/giacenze/?p=2')
        === '/backend/app/gestionale/giacenze/?p=2'
    && StockAdjustmentResource::backUrlFrom('//altrove.example') === ''
);

summary();
