<?php
/** php tests/StockValueTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Backend\Widgets\StockValueWidget;
use Wonder\Plugin\Gestionale\Support\Stock\LevelsSql;
use Wonder\Plugin\Gestionale\Support\Stock\StockValue;

check('pezzi per costo, solo i secchi sopra zero, a due decimali', fn () =>
    StockValue::of([
        ['quantity' => 3, 'cost' => 2.5],
        ['quantity' => 1.5, 'cost' => 1.0],
        ['quantity' => -4, 'cost' => 9.0],
        ['quantity' => 0, 'cost' => 9.0],
    ]) === ['value' => 9.0, 'pieces' => 4.5, 'uncosted' => 0.0]
);

check('i pezzi senza costo non valgono zero in silenzio: si contano a parte', fn () =>
    StockValue::of([['quantity' => 2, 'cost' => null], ['quantity' => 1, 'cost' => 4.0]])
        === ['value' => 4.0, 'pieces' => 3.0, 'uncosted' => 2.0]
);

check('niente merce, niente valore', fn () =>
    StockValue::of([]) === ['value' => 0.0, 'pieces' => 0.0, 'uncosted' => 0.0]
);

check('gli importi si scrivono come nella scheda ordine', fn () =>
    StockValue::money(1234.5) === '1.234,50 €' && StockValue::money(0) === '0,00 €'
);

check('la formula SQL somma solo i secchi sopra zero e non ha alias dentro', function () {
    $sql = LevelsSql::value();

    return str_contains($sql, 's.quantity > 0') && str_contains($sql, 'MIN(pz.cost)') && stripos($sql, ' AS ') === false;
});

check('il riquadro: totale, sedi solo da due in su, e i pezzi senza costo con il link', function () {
    $una = StockValueWidget::markup([0 => ['value' => 10.0, 'pieces' => 4.0, 'uncosted' => 0.0]], [], '/giacenze');
    $due = StockValueWidget::markup(
        [0 => ['value' => 30.0, 'pieces' => 9.0, 'uncosted' => 2.0], 1 => ['value' => 10.0, 'pieces' => 4.0, 'uncosted' => 0.0], 2 => ['value' => 20.0, 'pieces' => 5.0, 'uncosted' => 2.0]],
        [['id' => 1, 'label' => 'Milano'], ['id' => 2, 'label' => 'Roma <b>']],
        '/giacenze'
    );

    return str_contains($una, '10,00 €') && !str_contains($una, 'senza costo') && !str_contains($una, '<li')
        && str_contains($due, '30,00 €') && str_contains($due, 'Milano') && str_contains($due, 'Roma &lt;b&gt;')
        && str_contains($due, '2 pezzi senza costo') && str_contains($due, 'href="/giacenze"');
});

summary();
