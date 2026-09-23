<?php
/** php tests/LowStockEmailTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Stock\LowStockEmail;
use Wonder\Plugin\Gestionale\Support\Stock\LowStockReport;
use Wonder\Plugin\Gestionale\Support\Stock\ProductNames;

// Il sito avviato la definisce da `.env`: qui la mettiamo noi.
define('APP_URL', 'https://negozio.test/');

$avvisi = [
    ['id' => 11, 'product_id' => 1, 'threshold' => '5.000'],
    ['id' => 12, 'product_id' => 2, 'threshold' => '3.000'],
    ['id' => 13, 'product_id' => 3, 'threshold' => '2.000'],
    ['id' => 14, 'product_id' => 4, 'threshold' => '2.000'],
    ['id' => 15, 'product_id' => 1, 'threshold' => '5.000'],
    ['id' => 16, 'product_id' => 5, 'threshold' => '4.000'],
];
$prodotti = [
    1 => ['id' => 1, 'product_model_id' => 10, 'name' => 'Rossa, M', 'sku' => 'MAG-R-M', 'min_stock_quantity' => '6.000', 'deleted' => 'false'],
    2 => ['id' => 2, 'product_model_id' => 20, 'name' => 'Borraccia', 'sku' => 'BOR', 'min_stock_quantity' => '3.000', 'deleted' => 'false'],
    3 => ['id' => 3, 'product_model_id' => 10, 'name' => 'Blu, S', 'sku' => 'MAG-B-S', 'min_stock_quantity' => '2.000', 'deleted' => 'true'],
    5 => ['id' => 5, 'product_model_id' => 20, 'name' => 'Tappo', 'sku' => 'TAP', 'min_stock_quantity' => '4.000', 'deleted' => 'false'],
];
$nomi = [10 => 'Maglia', 20 => 'Borraccia'];
$livelli = [1 => ['available' => 2.0], 2 => ['available' => 2.5], 5 => ['available' => 9.0]];

check('nell\'email solo i prodotti che ci sono e sono ancora sotto la soglia, una volta', fn () =>
    array_column(LowStockReport::build($avvisi, $prodotti, $nomi, $livelli), 'product_id') === [2, 1]
);

check('ogni riga dice articolo, opzione, SKU, soglia e disponibile di adesso', function () use ($avvisi, $prodotti, $nomi, $livelli) {
    $righe = LowStockReport::build($avvisi, $prodotti, $nomi, $livelli);

    return $righe[1] === [
        'product_id' => 1,
        'article' => 'Maglia',
        'option' => 'Rossa, M',
        'sku' => 'MAG-R-M',
        'threshold' => 6.0,
        'available' => 2.0,
    ];
});

check('un articolo senza varianti non ripete il nome come opzione', function () use ($avvisi, $prodotti, $nomi, $livelli) {
    $righe = LowStockReport::build($avvisi, $prodotti, $nomi, $livelli);

    return $righe[0]['article'] === 'Borraccia' && $righe[0]['option'] === '';
});

check('articolo e opzione si leggono come nella griglia', fn () =>
    ProductNames::of(['product_model_id' => 10, 'name' => 'Rossa, M'], [10 => 'Maglia']) === ['article' => 'Maglia', 'option' => 'Rossa, M']
    && ProductNames::of(['product_model_id' => 20, 'name' => 'borraccia'], [20 => 'Borraccia']) === ['article' => 'Borraccia', 'option' => '']
    && ProductNames::of(['product_model_id' => 99, 'name' => 'Sola'], []) === ['article' => '—', 'option' => 'Sola']
);

check('gli avvisi di prodotti spariti o tolti dalla griglia sono da chiudere', fn () =>
    LowStockReport::orphans($avvisi, $prodotti) === [13, 14]
);

check('senza database i prodotti non si fingono spariti: l\'errore sale', function () use ($avvisi) {
    // I test girano senza database. Gli avvisi tornano vuoti (tabelle non
    // ancora create); i prodotti no: con `[]` ogni avviso sembrerebbe orfano.
    if (LowStockReport::open() !== [] || LowStockReport::pending() !== []) {
        return false;
    }

    try {
        LowStockReport::products($avvisi);
    } catch (Throwable) {
        return true;
    }

    return false;
});

check('l\'oggetto conta i prodotti', fn () =>
    LowStockEmail::subject(1) === '1 prodotto sotto scorta'
    && LowStockEmail::subject(3) === '3 prodotti sotto scorta'
);

check('i pezzi si scrivono come li legge una persona', fn () =>
    LowStockEmail::quantity(3.0) === '3'
    && LowStockEmail::quantity(2.5) === '2,5'
    && LowStockEmail::quantity(-1.0) === '-1'
    && LowStockEmail::quantity(20.0) === '20'
    && LowStockEmail::quantity(1.25) === '1,25'
);

check('il link dell\'email porta al sito, non a un percorso', fn () =>
    LowStockEmail::absoluteUrl('/backend/giacenze?sotto=1') === 'https://negozio.test/backend/giacenze?sotto=1'
    && LowStockEmail::absoluteUrl('https://altro.test/x') === 'https://altro.test/x'
);

$riga = [
    'product_id' => 7,
    'article' => 'Tè <b>verde</b> & co.',
    'option' => '',
    'sku' => 'TE-1',
    'threshold' => 5.0,
    'available' => 2.5,
];

check('l\'email ha oggetto, righe e link', function () use ($riga) {
    $email = LowStockEmail::compose([$riga], 'https://negozio.test/backend/giacenze?sotto=1');

    return $email['subject'] === '1 prodotto sotto scorta'
        && str_contains($email['body'], 'TE-1')
        && str_contains($email['body'], '2,5')
        && str_contains($email['body'], 'href="https://negozio.test/backend/giacenze?sotto=1"');
});

check('i nomi degli articoli arrivano come testo, non come HTML', function () use ($riga) {
    $body = LowStockEmail::compose([$riga], 'https://negozio.test/x')['body'];

    return str_contains($body, 'Tè &lt;b&gt;verde&lt;/b&gt; &amp; co.') && !str_contains($body, '<b>verde</b>');
});

check('il sito può riscrivere l\'email copiando la vista', function () use ($riga) {
    $root = sys_get_temp_dir().'/gst-email-'.uniqid();
    $vista = $root.'/custom/modules/gestionale/view/'.LowStockEmail::VIEW;
    mkdir(dirname($vista), 0777, true);
    file_put_contents($vista, '<p>Personalizzata: <?= $count ?> <?= $e($items[0][\'sku\']) ?></p>');

    $prima = $GLOBALS['ROOT'] ?? null;
    $GLOBALS['ROOT'] = $root;

    try {
        $body = LowStockEmail::compose([$riga], 'https://negozio.test/x')['body'];
    } finally {
        $GLOBALS['ROOT'] = $prima;
        unlink($vista);
    }

    return $body === '<p>Personalizzata: 1 TE-1</p>';
});

summary();
