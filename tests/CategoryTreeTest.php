<?php
/** php tests/CategoryTreeTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Catalog\CategoryTree;

$righe = [
    ['id' => 1, 'parent_id' => 0, 'name' => 'Abbigliamento', 'position' => 1],
    ['id' => 2, 'parent_id' => 1, 'name' => 'Magliette', 'position' => 1],
    ['id' => 3, 'parent_id' => 2, 'name' => 'A maniche corte', 'position' => 1],
    ['id' => 4, 'parent_id' => 0, 'name' => 'Accessori', 'position' => 2],
    ['id' => 5, 'parent_id' => 1, 'name' => 'Pantaloni', 'position' => 2],
];

check('l\'albero esce in ordine, figli sotto il loro padre', fn () =>
    array_column(CategoryTree::sorted($righe), 'id') === [1, 2, 3, 5, 4]
);

check('ogni riga sa quanto è profonda', function () use ($righe) {
    $profondita = array_column(CategoryTree::sorted($righe), 'depth', 'id');

    return $profondita === [1 => 0, 2 => 1, 3 => 2, 5 => 1, 4 => 0];
});

check('il percorso porta i nomi dei padri', function () use ($righe) {
    $percorsi = array_column(CategoryTree::sorted($righe), 'path', 'id');

    return $percorsi[3] === 'Abbigliamento › Magliette › A maniche corte'
        && $percorsi[1] === 'Abbigliamento';
});

check('i discendenti comprendono i nipoti', fn () =>
    CategoryTree::descendants($righe, 1) === [2, 3, 5]
    && CategoryTree::descendants($righe, 2) === [3]
    && CategoryTree::descendants($righe, 4) === []
);

check('il select del padre non propone sé stessa né i suoi figli', function () use ($righe) {
    $opzioni = CategoryTree::options($righe, 1);

    return !isset($opzioni['1'], $opzioni['2'], $opzioni['3'], $opzioni['5'])
        && isset($opzioni['4']);
});

check('il select indenta i figli e ha la voce "nessuna"', function () use ($righe) {
    $opzioni = CategoryTree::options($righe);

    return ($opzioni[''] ?? null) === 'Nessuna, sta in cima'
        && ($opzioni['1'] ?? '') === 'Abbigliamento'
        && str_starts_with($opzioni['2'] ?? '', '—');
});

check('un ciclo si riconosce prima di salvarlo', fn () =>
    CategoryTree::wouldLoop($righe, 1, 1) === true
    && CategoryTree::wouldLoop($righe, 1, 3) === true
    && CategoryTree::wouldLoop($righe, 4, 1) === false
    && CategoryTree::wouldLoop($righe, 1, 0) === false
);

check('un padre che non esiste non fa sparire la riga', function () {
    $orfana = [
        ['id' => 9, 'parent_id' => 77, 'name' => 'Orfana', 'position' => 1],
        ['id' => 1, 'parent_id' => 0, 'name' => 'Radice', 'position' => 2],
    ];

    return array_column(CategoryTree::sorted($orfana), 'id') === [9, 1];
});

summary();
