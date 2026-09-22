<?php
/** php tests/StockPagesTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Resources\Stock\StockAdjustmentResource;
use Wonder\Plugin\Gestionale\Resources\Stock\StockLevelResource;
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

check('il link porta l\'opzione e la strada del ritorno', function () {
    $url = StockAdjustmentResource::urlFor(7, '/backend/app/gestionale/giacenze/?p=2');

    return str_contains($url, 'versione=7')
        && str_contains($url, 'torna=');
});

check('la strada del ritorno accetta solo indirizzi di questo sito', function () {
    // Un `torna=https://altrove.example` sarebbe un redirect aperto. Le rotte
    // del core però tornano indirizzi assoluti di **questo** sito, e quelli
    // devono passare.
    $_SERVER['HTTP_HOST'] = 'ecommerce.test';

    return StockAdjustmentResource::backUrlFrom('https://altrove.example/x') === ''
        && StockAdjustmentResource::backUrlFrom('//altrove.example') === ''
        && StockAdjustmentResource::backUrlFrom('/backend/app/gestionale/giacenze/?p=2')
            === '/backend/app/gestionale/giacenze/?p=2'
        && StockAdjustmentResource::backUrlFrom('https://ecommerce.test/backend/app/gestionale/giacenze/?cerca=TSH')
            === '/backend/app/gestionale/giacenze/?cerca=TSH';
});

check('le giacenze hanno il loro indirizzo e sono una pagina-form', fn () =>
    StockLevelResource::path() === 'app/gestionale/giacenze'
    && StockLevelResource::isFormPage() === true
);

check('le giacenze stanno nel menu Magazzino, prima dei movimenti', function () {
    $nav = StockLevelResource::navigationSchema()->toArray();

    return ($nav['enabled'] ?? true) !== false
        && ($nav['section_key'] ?? '') === 'magazzino'
        && (int) ($nav['order'] ?? 0) < 20;
});

check('si lavora cinquanta righe per volta', fn () =>
    StockLevelResource::PER_PAGE === 50
);

check('la causale della schermata parte dall\'inventario', function () {
    foreach (StockLevelResource::formSchema() as $field) {
        if ((string) $field->name === 'reason') {
            return $field->get('value') === Reasons::DEFAULT;
        }
    }

    return false;
});

check('dalla ricerca non passano virgolette né punti e virgola', function () {
    // Il testo arriva dall'indirizzo e finisce dentro una LIKE: quello che
    // chiuderebbe la stringa non deve sopravvivere. I trattini sì: stanno
    // negli SKU.
    $pulito = StockLevelResource::searchTerm("Rosso'; DROP TABLE gst_stock; --");

    return !str_contains($pulito, "'")
        && !str_contains($pulito, ';')
        && !str_contains($pulito, '\\')
        && StockLevelResource::searchTerm('TSH-1_B') === 'TSH-1_B';
});

check('una pagina fuori scala torna alla prima', fn () =>
    StockLevelResource::pageNumber('0') === 1
    && StockLevelResource::pageNumber('-4') === 1
    && StockLevelResource::pageNumber('abc') === 1
    && StockLevelResource::pageNumber('3') === 3
);

check('la rettifica porta con sé l\'opzione e il ritorno', function () {
    $_GET['versione'] = '9';
    $_GET['torna'] = '/backend/app/gestionale/giacenze/?p=2';
    $_SERVER['HTTP_HOST'] = 'ecommerce.test';

    $campi = [];

    foreach (StockAdjustmentResource::formSchema() as $field) {
        $campi[(string) $field->name] = $field;
    }

    unset($_GET['versione'], $_GET['torna']);

    // La rotta del salvataggio non ha query string: senza questi due campi
    // nascosti, al salvataggio non si saprebbe più di quale opzione si
    // stava parlando. `versione=` resta come chiave di query.
    return ($campi['product_id'] ?? null)?->get('value') === '9'
        && ($campi['back'] ?? null)?->get('value') === '/backend/app/gestionale/giacenze/?p=2';
});

check('un rifiuto della rettifica non diventa una pagina 500', function () {
    // Il controller delle pagine-form non intercetta niente: il messaggio
    // deve tornare come stringa, non come eccezione.
    $messaggio = StockAdjustmentResource::submitFormPage([
        'product_id' => '0',
        'quantity' => '3',
    ]);

    return str_contains($messaggio, 'non esiste più');
});

summary();
