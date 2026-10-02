<?php
/** php tests/BundlesTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Catalog\Bundles;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

/** La chiave dell'errore che la prova fa scattare, '' se non ne scatta nessuno. */
$chiave = static function (callable $corpo): string {
    try {
        $corpo();
    } catch (UserError $e) {
        return $e->key();
    }

    return '';
};

$c = static fn (int $prodotto, float $quantita = 1.0): array => ['product_id' => $prodotto, 'quantity' => $quantita];
$o = static fn (int $prodotto, float $sovrapprezzo = 0.0): array => ['product_id' => $prodotto, 'surcharge' => $sovrapprezzo];
$g = static fn (string $nome, int $min, int $max, array $opzioni): array => ['name' => $nome, 'min' => $min, 'max' => $max, 'options' => $opzioni];

check('assertComposition: modalità sconosciuta', function () use ($chiave, $c) {
    return $chiave(fn () => Bundles::assertComposition('boh', [$c(1)], [])) === 'bundle.unknown_mode'
        && $chiave(fn () => Bundles::assertComposition('', [$c(1)], [])) === 'bundle.unknown_mode'
        && $chiave(fn () => Bundles::assertComposition('fixed', [$c(1)], [])) === '';
});

check('assertComposition: ogni modalità vuole la sua composizione', function () use ($chiave, $c, $o, $g) {
    $gruppo = $g('Colore', 1, 1, [$o(1), $o(2)]);

    return $chiave(fn () => Bundles::assertComposition('fixed', [], [])) === 'bundle.no_components'
        && $chiave(fn () => Bundles::assertComposition('choice', [], [])) === 'bundle.no_groups'
        && $chiave(fn () => Bundles::assertComposition('mixed', [], [$gruppo])) === 'bundle.no_components'
        && $chiave(fn () => Bundles::assertComposition('mixed', [$c(1)], [])) === 'bundle.no_groups'
        && $chiave(fn () => Bundles::assertComposition('choice', [], [$gruppo])) === ''
        && $chiave(fn () => Bundles::assertComposition('mixed', [$c(1)], [$gruppo])) === '';
});

check('assertComposition: i riquadri nascosti dalla modalità non si guardano', function () use ($chiave, $c, $o, $g) {
    $storto = $g('', 5, 1, []);

    return $chiave(fn () => Bundles::assertComposition('fixed', [$c(1)], [$storto])) === ''
        && $chiave(fn () => Bundles::assertComposition('choice', [$c(1, 0.0)], [$g('Colore', 1, 1, [$o(1)])])) === '';
});

check('assertComposition: quantità zero o negativa', function () use ($chiave, $c) {
    return $chiave(fn () => Bundles::assertComposition('fixed', [$c(1, 0.0)], [])) === 'bundle.zero_quantity'
        && $chiave(fn () => Bundles::assertComposition('fixed', [$c(1, -1.0)], [])) === 'bundle.zero_quantity'
        && $chiave(fn () => Bundles::assertComposition('fixed', [$c(1, 0.5)], [])) === '';
});

check('assertComposition: prodotto ripetuto fra i fissi o dentro un gruppo, non fra fisso e opzione', function () use ($chiave, $c, $o, $g) {
    return $chiave(fn () => Bundles::assertComposition('fixed', [$c(1), $c(1)], [])) === 'bundle.duplicate_product'
        && $chiave(fn () => Bundles::assertComposition('choice', [], [$g('A', 1, 1, [$o(1), $o(1)])])) === 'bundle.duplicate_product'
        && $chiave(fn () => Bundles::assertComposition('mixed', [$c(1)], [$g('A', 1, 1, [$o(1), $o(2)])])) === '';
});

check('assertComposition: il gruppo ha un nome', function () use ($chiave, $o, $g) {
    return $chiave(fn () => Bundles::assertComposition('choice', [], [$g('  ', 1, 1, [$o(1), $o(2)])])) === 'bundle.group_name';
});

check('assertComposition: minimo e massimo coerenti con le opzioni', function () use ($chiave, $o, $g) {
    $due = [$o(1), $o(2)];
    $prova = static fn (int $min, int $max) => $chiave(fn () => Bundles::assertComposition('choice', [], [$g('A', $min, $max, $due)]));

    return $prova(-1, 1) === 'bundle.group_range'
        && $prova(0, 0) === 'bundle.group_range'
        && $prova(2, 1) === 'bundle.group_range'
        && $prova(1, 3) === 'bundle.group_range'
        && $prova(0, 1) === ''
        && $prova(2, 2) === ''
        && $chiave(fn () => Bundles::assertComposition('choice', [], [$g('A', 0, 1, [])])) === 'bundle.group_range';
});

check('assertComposition: sovrapprezzo negativo', function () use ($chiave, $o, $g) {
    return $chiave(fn () => Bundles::assertComposition('choice', [], [$g('A', 1, 1, [$o(1, -0.5), $o(2)])])) === 'bundle.negative_surcharge'
        && $chiave(fn () => Bundles::assertComposition('choice', [], [$g('A', 1, 1, [$o(1, 0.0), $o(2, 2.5)])])) === '';
});

$gruppi = static fn (): array => [
    ['id' => 1, 'name' => 'Colore', 'min' => 1, 'max' => 1, 'options' => [
        ['id' => 11, 'product_id' => 101, 'surcharge' => '0.00'],
        ['id' => 12, 'product_id' => 102, 'surcharge' => '2.50'],
    ]],
    ['id' => 2, 'name' => 'Extra', 'min' => 0, 'max' => 2, 'options' => [
        ['id' => 21, 'product_id' => 201, 'surcharge' => '1.00'],
        ['id' => 22, 'product_id' => 202, 'surcharge' => '0.00'],
    ]],
];

check('choose: restituisce le scelte nell\'ordine dei gruppi, anche con id come stringa e doppi', function () use ($gruppi) {
    $scelte = Bundles::choose($gruppi(), ['21', 12, 21, '12']);

    return array_column($scelte, 'option_id') === [12, 21]
        && array_column($scelte, 'product_id') === [102, 201]
        && array_column($scelte, 'surcharge') === ['2.50', '1.00'];
});

check('choose: un\'opzione che non è di nessun gruppo si rifiuta', function () use ($chiave, $gruppi) {
    return $chiave(fn () => Bundles::choose($gruppi(), [11, 999])) === 'bundle.unknown_option'
        && $chiave(fn () => Bundles::choose($gruppi(), ['x'])) === 'bundle.unknown_option';
});

check('choose: minimo e massimo per gruppo, con il nome', function () use ($chiave, $gruppi) {
    $poche = null;
    $troppe = null;

    try {
        Bundles::choose($gruppi(), []);
    } catch (UserError $e) {
        $poche = [$e->key(), $e->getMessage()];
    }

    try {
        Bundles::choose($gruppi(), [11, 12]);
    } catch (UserError $e) {
        $troppe = [$e->key(), $e->getMessage()];
    }

    return $poche !== null && $poche[0] === 'bundle.too_few' && str_contains($poche[1], 'Colore')
        && $troppe !== null && $troppe[0] === 'bundle.too_many' && str_contains($troppe[1], 'Colore')
        && $chiave(fn () => Bundles::choose($gruppi(), [11])) === ''
        && $chiave(fn () => Bundles::choose($gruppi(), [11, 21, 22])) === '';
});

check('choose: min 2 con una sola scelta è troppo poco; min 0 senza scelte va bene', function () use ($chiave) {
    $due = [['id' => 1, 'name' => 'A', 'min' => 2, 'max' => 3, 'options' => [
        ['id' => 1, 'product_id' => 1, 'surcharge' => '0'], ['id' => 2, 'product_id' => 2, 'surcharge' => '0'], ['id' => 3, 'product_id' => 3, 'surcharge' => '0'],
    ]]];
    $zero = [['id' => 1, 'name' => 'A', 'min' => 0, 'max' => 1, 'options' => [['id' => 1, 'product_id' => 1, 'surcharge' => '0']]]];

    return $chiave(fn () => Bundles::choose($due, [1])) === 'bundle.too_few'
        && $chiave(fn () => Bundles::choose($due, [1, 2])) === ''
        && Bundles::choose($zero, []) === [];
});

check('pieces: fissi per quantità, scelte da uno, sommati per prodotto', function () use ($c) {
    $pezzi = Bundles::pieces([$c(1, 0.5), $c(2, 2.0)], [['product_id' => 1], ['product_id' => 3]], 3.0);

    return $pezzi === [1 => 1.5 + 3.0, 2 => 6.0, 3 => 3.0];
});

check('pieces: tre decimali', function () use ($c) {
    return Bundles::pieces([$c(1, 0.333)], [], 3.0) === [1 => 0.999];
});

check('shortfall: il primo prodotto che non basta, D60 non limita', function () {
    $pezzi = [1 => 2.0, 2 => 3.0, 3 => 1.0];

    return Bundles::shortfall($pezzi, [1 => 5.0, 2 => 3.0, 3 => 1.0], []) === null
        && Bundles::shortfall($pezzi, [1 => 5.0, 2 => 2.0, 3 => 0.0], []) === ['product_id' => 2, 'available' => 2.0]
        && Bundles::shortfall($pezzi, [1 => 5.0, 2 => 2.0, 3 => 0.0], [2 => true, 3 => true]) === null
        && Bundles::shortfall([9 => 1.0], [], []) === ['product_id' => 9, 'available' => 0.0];
});

check('capacity: fisso esaurito 0, fisso da 2 su 5 disponibili 2', function () use ($c) {
    return Bundles::capacity([$c(1, 1.0)], [], [1 => 0.0], []) === 0.0
        && Bundles::capacity([$c(1, 2.0)], [], [1 => 5.0], []) === 2.0
        && Bundles::capacity([$c(1, 2.0), $c(2, 1.0)], [], [1 => 5.0, 2 => 1.0], []) === 1.0;
});

check('capacity: un gruppo con min 2 e una sola opzione disponibile vale 0', function () use ($o, $g) {
    $gruppo = $g('A', 2, 2, [$o(1), $o(2), $o(3)]);

    return Bundles::capacity([], [$gruppo], [1 => 4.0, 2 => 0.0, 3 => 0.0], []) === 0.0
        && Bundles::capacity([], [$gruppo], [1 => 4.0, 2 => 3.0, 3 => 0.0], []) === 3.0;
});

check('capacity: un gruppo con min 0 non limita, e senza altro limite torna il tetto', function () use ($c, $o, $g) {
    $libero = $g('A', 0, 1, [$o(1), $o(2)]);

    return Bundles::capacity([], [$libero], [1 => 0.0, 2 => 0.0], []) === Bundles::UNLIMITED
        && Bundles::capacity([$c(5, 2.0)], [$libero], [5 => 7.0, 1 => 0.0], []) === 3.0;
});

check('capacity: un componente vendibile senza giacenza (D60) non limita', function () use ($c, $o, $g) {
    return Bundles::capacity([$c(1, 1.0)], [], [1 => 0.0], [1 => true]) === Bundles::UNLIMITED
        && Bundles::capacity([$c(1, 1.0), $c(2, 1.0)], [], [1 => 0.0, 2 => 4.0], [1 => true]) === 4.0
        && Bundles::capacity([], [$g('A', 1, 1, [$o(1), $o(2)])], [1 => 0.0, 2 => 0.0], [1 => true]) === Bundles::UNLIMITED;
});

check('capacity: lo stesso prodotto fisso e opzione si somma', function () use ($c, $o, $g) {
    $gruppo = $g('A', 1, 1, [$o(1)]);

    // Ogni confezione vuole 1 pezzo fisso e 1 scelto: 4 pezzi bastano per 2 confezioni.
    return Bundles::capacity([$c(1, 1.0)], [$gruppo], [1 => 4.0], []) === 2.0;
});

check('capacity: senza composizione vale zero', function () {
    return Bundles::capacity([], [], [], []) === 0.0;
});

check('value: fissi per quantità e le min opzioni più economiche', function () use ($c, $o, $g) {
    $prezzi = [1 => 10.0, 2 => 3.5, 11 => 5.0, 12 => 2.0, 13 => 9.0];
    $gruppo = $g('A', 2, 3, [$o(11), $o(12), $o(13)]);

    return Bundles::value([$c(1, 1.0), $c(2, 2.0)], [], $prezzi) === '17.00'
        && Bundles::value([$c(1, 1.0)], [$gruppo], $prezzi) === '17.00'
        && Bundles::value([], [], $prezzi) === '0.00'
        && Bundles::value([], [$g('A', 0, 1, [$o(11)])], $prezzi) === '0.00';
});

summary();
