<?php
/** php tests/CustomizationsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Catalog\Customizations;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

/** Una definizione come la dà forModel(). */
$def = static fn (int $id, string $kind = 'text', array $extra = []): array => $extra + [
    'id' => $id,
    'name' => 'Campo '.$id,
    'label' => 'Campo '.$id,
    'help_text' => '',
    'kind' => $kind,
    'max_length' => $kind === 'text' ? 20 : 0,
    'surcharge' => '0.00',
    'required' => false,
    'options' => [],
];

$rifiuto = static function (callable $corpo): ?UserError {
    try {
        $corpo();
    } catch (UserError $e) {
        return $e;
    }

    return null;
};

$scelta = static fn (int $id, array $extra = []): array => $def($id, 'choice', $extra + [
    'surcharge' => '3.00',
    'options' => [
        ['id' => 11, 'label' => 'Rossa', 'surcharge' => '2.00'],
        ['id' => 12, 'label' => 'Blu', 'surcharge' => '0.00'],
    ],
]);

check('un testo valido porta il suo sovrapprezzo', function () use ($def) {
    $r = Customizations::check([$def(1, 'text', ['surcharge' => '5.00'])], [1 => 'Marco']);

    return $r['surcharge'] === '5.00'
        && $r['fields'] === [['customization_id' => 1, 'label' => 'Campo 1', 'value' => 'Marco', 'option_id' => 0, 'surcharge' => '5.00']];
});

check('oltre i caratteri massimi si rifiuta, e si contano le lettere non i byte', function () use ($def, $rifiuto) {
    $def5 = $def(4, 'text', ['max_length' => 5]);
    $ok = Customizations::check([$def5], [4 => 'àèìòù']);
    $e = $rifiuto(fn () => Customizations::check([$def5], [4 => 'àèìòùx']));

    return $ok['fields'][0]['value'] === 'àèìòù'
        && $e?->key() === 'customization.too_long'
        && $e->field() === 4
        && str_contains($e->getMessage(), '5');
});

check('il testo si ripulisce: spazi, a capo e controlli', function () use ($def) {
    $r = Customizations::check([$def(1)], [1 => "  Ciao\r\nmondo\t! "]);

    return $r['fields'][0]['value'] === "Ciao\nmondo!";
});

check('un byte UTF-8 rotto non fa esplodere niente', function () use ($def) {
    $r = Customizations::check([$def(1)], [1 => "ab\xC3"]);

    return mb_check_encoding($r['fields'][0]['value'], 'UTF-8') && str_starts_with($r['fields'][0]['value'], 'ab');
});

check('un campo obbligatorio vuoto, anche di soli spazi, si rifiuta', function () use ($def, $rifiuto) {
    $d = $def(2, 'text', ['required' => true]);

    return $rifiuto(fn () => Customizations::check([$d], []))?->key() === 'customization.required'
        && $rifiuto(fn () => Customizations::check([$d], [2 => '   ']))?->field() === 2;
});

check('un campo facoltativo vuoto non entra e non costa', function () use ($def) {
    $r = Customizations::check([$def(1, 'text', ['surcharge' => '5.00'])], [1 => '  ']);

    return $r['fields'] === [] && $r['surcharge'] === '0.00';
});

check('una scelta vale l\'etichetta dell\'opzione e somma i due sovrapprezzi', function () use ($scelta) {
    foreach ([11, '11'] as $id) {
        $r = Customizations::check([$scelta(7)], [7 => $id]);

        if ($r['fields'][0]['value'] !== 'Rossa' || $r['fields'][0]['option_id'] !== 11 || $r['surcharge'] !== '5.00') {
            return false;
        }
    }

    return true;
});

check('un\'opzione di un\'altra personalizzazione non vale', function () use ($scelta, $rifiuto) {
    return $rifiuto(fn () => Customizations::check([$scelta(7)], [7 => 99]))?->key() === 'customization.bad_option';
});

check('un campo che non è dell\'articolo si rifiuta', function () use ($def, $rifiuto) {
    $e = $rifiuto(fn () => Customizations::check([$def(1)], [5 => 'x']));

    return $e?->key() === 'customization.unknown' && $e->field() === 5;
});

check('il totale somma i campi', function () use ($def, $scelta) {
    $r = Customizations::check([$def(1, 'text', ['surcharge' => '5.00']), $scelta(7)], [1 => 'A', 7 => 11]);

    return $r['surcharge'] === '10.00' && count($r['fields']) === 2;
});

check('encode e decode riportano il testo identico', function () {
    foreach (['Café ☕ 😀', 'A & B', '&amp;', '&#128512;', '<b>x</b>', '"virgolette" e \'apici\'', 'ß'] as $testo) {
        $campi = [['customization_id' => 1, 'label' => 'Incisione è', 'value' => $testo, 'option_id' => 0, 'surcharge' => '5.00']];
        $salvato = Customizations::encode($campi);
        $letto = Customizations::decode($salvato);

        if (!mb_check_encoding($salvato, 'ASCII') || ($letto[0]['value'] ?? null) !== $testo || $letto[0]['label'] !== 'Incisione è') {
            return false;
        }
    }

    return true;
});

check('senza campi non si salva niente e si legge niente', function () {
    return Customizations::encode([]) === ''
        && Customizations::decode('') === []
        && Customizations::decode('non json') === []
        && Customizations::decode(null) === [];
});

check('una lista già letta si lascia com\'è', function () {
    $lista = [['customization_id' => 1, 'label' => 'L', 'value' => '&amp;', 'option_id' => 0, 'surcharge' => '1.00']];

    return Customizations::decode($lista)[0]['value'] === '&amp;';
});

check('la firma non dipende dall\'ordine né dal tipo degli id', function () {
    $a = ['customization_id' => 1, 'label' => 'A', 'value' => 'x', 'option_id' => 0, 'surcharge' => '0.00'];
    $b = ['customization_id' => 2, 'label' => 'B', 'value' => 'Rossa', 'option_id' => 11, 'surcharge' => '0.00'];
    $strB = ['customization_id' => '2', 'label' => 'B', 'value' => 'Rossa', 'option_id' => '11', 'surcharge' => '0.00'];

    return Customizations::signature([$a, $b]) === Customizations::signature([$strB, $a])
        && Customizations::signature([$a]) !== Customizations::signature([['value' => 'y'] + $a])
        && Customizations::signature([]) === '';
});

check('valuesOf ridà l\'opzione per una scelta e il testo per un testo', function () {
    $campi = [
        ['customization_id' => 1, 'label' => 'A', 'value' => 'Marco', 'option_id' => 0, 'surcharge' => '0.00'],
        ['customization_id' => 2, 'label' => 'B', 'value' => 'Rossa', 'option_id' => 11, 'surcharge' => '0.00'],
    ];

    return Customizations::valuesOf($campi) === [1 => 'Marco', 2 => 11];
});

check('lines scrive «Etichetta: valore» e basta', function () {
    $item = ['customization' => Customizations::encode([
        ['customization_id' => 1, 'label' => 'Incisione', 'value' => 'Marco', 'option_id' => 0, 'surcharge' => '5.00'],
    ])];

    return Customizations::lines($item) === ['Incisione: Marco'] && Customizations::lines([]) === [];
});

check('la definizione di un testo vuole da 1 a 1000 caratteri', function () use ($rifiuto) {
    $prova = static fn ($n) => $rifiuto(fn () => Customizations::assertDefinition(['kind' => 'text', 'max_length' => $n, 'surcharge' => '0'], []));

    return $prova(0)?->key() === 'customization.max_length'
        && $prova(1001)?->key() === 'customization.max_length'
        && $prova('x')?->key() === 'customization.max_length'
        && $prova(1) === null
        && $prova(1000) === null;
});

check('il sovrapprezzo non è negativo, né della personalizzazione né di un\'opzione', function () use ($rifiuto) {
    $neg = $rifiuto(fn () => Customizations::assertDefinition(['kind' => 'text', 'max_length' => 10, 'surcharge' => '-1'], []));
    $negOpz = $rifiuto(fn () => Customizations::assertDefinition(
        ['kind' => 'choice', 'surcharge' => '0'],
        [['label' => 'A', 'surcharge' => '0'], ['label' => 'B', 'surcharge' => '-2']]
    ));

    return $neg?->key() === 'customization.surcharge' && $negOpz?->key() === 'customization.surcharge';
});

check('un sovrapprezzo lasciato vuoto o scritto con la virgola non è un errore', function () use ($rifiuto) {
    $vuoto = $rifiuto(fn () => Customizations::assertDefinition(
        ['kind' => 'choice', 'surcharge' => ''],
        [['label' => 'A', 'surcharge' => ''], ['label' => 'B', 'surcharge' => '1,50']]
    ));

    return $vuoto === null;
});

check('una scelta ha bisogno di due opzioni con l\'etichetta', function () use ($rifiuto) {
    $uno = $rifiuto(fn () => Customizations::assertDefinition(['kind' => 'choice', 'surcharge' => '0'], [['label' => 'A', 'surcharge' => '0']]));
    $vuota = $rifiuto(fn () => Customizations::assertDefinition(['kind' => 'choice', 'surcharge' => '0'], [['label' => 'A', 'surcharge' => '0'], ['label' => '  ', 'surcharge' => '0']]));
    $due = $rifiuto(fn () => Customizations::assertDefinition(['kind' => 'choice', 'surcharge' => '0'], [['label' => 'A', 'surcharge' => '0'], ['label' => 'B', 'surcharge' => '1']]));

    return $uno?->key() === 'customization.few_options' && $vuota?->key() === 'customization.few_options' && $due === null;
});

check('un tipo fuori elenco si rifiuta', function () use ($rifiuto) {
    return $rifiuto(fn () => Customizations::assertDefinition(['kind' => 'file', 'surcharge' => '0'], []))?->key() === 'customization.kind';
});

check('l\'errore sa il suo campo, e senza campo vale zero', function () {
    return UserError::make('customization.required')->field() === 0
        && UserError::make('customization.required')->withField(7)->field() === 7;
});

$numero = static fn (int $id, array $extra = []): array => $def($id, 'number', $extra + ['decimals' => 2]);

check('un numero si scrive con la virgola e vale con i suoi decimali', function () use ($numero) {
    foreach (['12,5', '12.5', ' 12,50 ', '12,500'] as $scritto) {
        $r = Customizations::check([$numero(1, ['decimals' => 3])], [1 => $scritto]);

        if ($r['fields'][0]['value'] !== '12,500') {
            return false;
        }
    }

    $r = Customizations::check([$numero(1)], [1 => '1250.5']);

    return $r['fields'][0]['value'] === '1250,50';
});

check('un numero porta il sovrapprezzo fisso della personalizzazione', function () use ($numero) {
    $r = Customizations::check([$numero(1, ['surcharge' => '4.00'])], [1 => '10']);

    return $r['surcharge'] === '4.00' && $r['fields'][0]['surcharge'] === '4.00' && $r['fields'][0]['option_id'] === 0;
});

check('un numero senza decimali è un intero e rifiuta la virgola', function () use ($numero, $rifiuto) {
    $d = $numero(3, ['decimals' => 0]);
    $ok = Customizations::check([$d], [3 => '42']);
    $e = $rifiuto(fn () => Customizations::check([$d], [3 => '4,5']));

    return $ok['fields'][0]['value'] === '42'
        && $e?->key() === 'customization.whole_number'
        && $e->field() === 3;
});

check('troppi decimali si rifiutano', function () use ($numero, $rifiuto) {
    $d = $numero(1, ['decimals' => 2]);
    $e = $rifiuto(fn () => Customizations::check([$d], [1 => '1,234']));

    return $e?->key() === 'customization.too_many_decimals'
        && str_contains($e->getMessage(), '2');
});

check('quello che non è un numero positivo si rifiuta', function () use ($numero, $rifiuto) {
    foreach (['abc', '1e5', '-3', '--3', '1,2,3', '0x1A', '12 cm'] as $scritto) {
        if ($rifiuto(fn () => Customizations::check([$numero(1)], [1 => $scritto]))?->key() !== 'customization.not_number') {
            return false;
        }
    }

    return true;
});

check('un numero troppo lungo si rifiuta', function () use ($numero, $rifiuto) {
    return $rifiuto(fn () => Customizations::check([$numero(1)], [1 => str_repeat('9', 20)]))?->key() === 'customization.not_number';
});

check('un numero vuoto non entra, e se è obbligatorio si rifiuta', function () use ($numero, $rifiuto) {
    $facoltativo = Customizations::check([$numero(1)], [1 => '  ']);
    $e = $rifiuto(fn () => Customizations::check([$numero(2, ['required' => true])], []));

    return $facoltativo['fields'] === [] && $e?->key() === 'customization.required';
});

check('un numero già salvato si ricontrolla uguale e dà la stessa firma', function () use ($numero) {
    $d = $numero(1);
    $primo = Customizations::check([$d], [1 => '1250,5']);
    $di_nuovo = Customizations::check([$d], Customizations::valuesOf($primo['fields']));
    $altro = Customizations::check([$d], [1 => '1250.50']);

    return $di_nuovo['fields'] === $primo['fields']
        && Customizations::signature($primo['fields']) === Customizations::signature($altro['fields']);
});

check('sotto il nome un numero si legge come un testo', function () use ($numero) {
    $r = Customizations::check([$numero(1, ['label' => 'Larghezza'])], [1 => '12.5']);

    return Customizations::lines(['customization' => Customizations::encode($r['fields'])]) === ['Larghezza: 12,50'];
});

check('un numero ha bisogno di decimali da 0 a 6, e non di caratteri', function () use ($rifiuto) {
    $prova = static fn ($n) => $rifiuto(fn () => Customizations::assertDefinition(['kind' => 'number', 'decimals' => $n, 'surcharge' => '0'], []));

    return $prova(0) === null && $prova('2') === null && $prova(6) === null
        && $prova(7)?->key() === 'customization.decimals'
        && $prova(-1)?->key() === 'customization.decimals'
        && $prova('')?->key() === 'customization.decimals'
        && $prova('1,5')?->key() === 'customization.decimals'
        && $prova('abc')?->key() === 'customization.decimals';
});

check('un numero non guarda i caratteri massimi', function () use ($rifiuto) {
    return $rifiuto(fn () => Customizations::assertDefinition(['kind' => 'number', 'decimals' => 1, 'max_length' => '', 'surcharge' => '0'], [])) === null;
});

check('un sovrapprezzo negativo su un numero si rifiuta', function () use ($rifiuto) {
    return $rifiuto(fn () => Customizations::assertDefinition(['kind' => 'number', 'decimals' => 1, 'surcharge' => '-2'], []))?->key() === 'customization.surcharge';
});

check('il sovrapprezzo per articolo vale se c\'è, anche zero, e se manca vale quello della personalizzazione', function () {
    $una = static fn (?string $suPiuArticoli): string => Customizations::effectiveSurcharge('5.00', $suPiuArticoli);

    return $una(null) === '5.00'
        && $una('') === '5.00'
        && $una('0') === '0.00'
        && $una('0.00') === '0.00'
        && $una('2,5') === '2.50'
        && $una('7.50') === '7.50';
});

summary();
