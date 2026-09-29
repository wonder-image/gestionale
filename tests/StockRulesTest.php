<?php
/** php tests/StockRulesTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Stock\Adjustment;
use Wonder\Plugin\Gestionale\Support\Stock\LowStock;

check('scrivere una quantità nuova diventa la differenza', fn () =>
    Adjustment::fromTarget(4.0, 10.0) === ['delta' => 6.0, 'before' => 4.0, 'after' => 10.0]
);

check('scrivere meno di quello che c\'è toglie pezzi', fn () =>
    Adjustment::fromTarget(10.0, 4.0) === ['delta' => -6.0, 'before' => 10.0, 'after' => 4.0]
);

check('scrivere la stessa quantità non muove niente', fn () =>
    Adjustment::fromTarget(7.0, 7.0)['delta'] === 0.0
);

check('il più e il meno partono da quello che c\'è', fn () =>
    Adjustment::fromDelta(7.0, -2.0) === ['delta' => -2.0, 'before' => 7.0, 'after' => 5.0]
);

check('i terzi decimali non lasciano briciole', fn () =>
    // 0.1 + 0.2 in virgola mobile fa 0.30000000000000004.
    Adjustment::fromDelta(0.1, 0.2)['after'] === 0.3
);

check('una quantità può scendere sotto zero: il rifiuto è di chi scrive', fn () =>
    Adjustment::fromDelta(1.0, -3.0)['after'] === -2.0
);

check('le tre azioni della rettifica sono quelle della scheda', fn () =>
    Adjustment::ACTIONS === ['add' => 'Aggiungi', 'subtract' => 'Sottrai', 'set' => 'Imposta']
);

check('aggiungere somma i pezzi a quelli che ci sono', fn () =>
    Adjustment::of('add', 7.0, 3.0) === ['delta' => 3.0, 'before' => 7.0, 'after' => 10.0]
);

check('sottrarre li toglie, senza chiedere il segno a chi scrive', fn () =>
    Adjustment::of('subtract', 7.0, 3.0) === ['delta' => -3.0, 'before' => 7.0, 'after' => 4.0]
);

check('impostare porta la giacenza a quel numero', fn () =>
    Adjustment::of('set', 7.0, 3.0) === ['delta' => -4.0, 'before' => 7.0, 'after' => 3.0]
);

check('il segno lo mette l\'azione, non la quantità', fn () =>
    // "Sottrai -3" toglie tre pezzi: chi scrive il meno voleva toglierli, non
    // aggiungerli. Vale anche per il contrario.
    Adjustment::of('subtract', 7.0, -3.0)['after'] === 4.0
    && Adjustment::of('add', 7.0, -3.0)['after'] === 10.0
    && Adjustment::of('set', 7.0, -3.0)['after'] === 3.0
);

check('un\'azione che non esiste aggiunge, come il predefinito della scheda', fn () =>
    Adjustment::of('boh', 7.0, 3.0)['after'] === 10.0
);

check('senza soglia non c\'è nessun avviso', fn () =>
    LowStock::decide(0.0, -5.0, false) === LowStock::NONE
);

check('togliere la soglia chiude un avviso già aperto', fn () =>
    LowStock::decide(0.0, -5.0, true) === LowStock::CLOSE
);

check('scendere sotto soglia apre l\'avviso', fn () =>
    LowStock::decide(5.0, 4.0, false) === LowStock::OPEN
);

check('la soglia esatta conta come sotto', fn () =>
    // "Scorta minima 5" vuol dire "sotto i cinque pezzi riordina": a cinque
    // siamo già al limite.
    LowStock::decide(5.0, 5.0, false) === LowStock::OPEN
);

check('un avviso aperto non si ripete', fn () =>
    LowStock::decide(5.0, 3.0, true) === LowStock::NONE
);

check('risalire sopra soglia chiude l\'avviso', fn () =>
    LowStock::decide(5.0, 6.0, true) === LowStock::CLOSE
);

check('sopra soglia senza avviso non succede niente', fn () =>
    LowStock::decide(5.0, 6.0, false) === LowStock::NONE
);

summary();
