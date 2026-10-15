<?php
/** php tests/ProductFeedsTest.php — D64, i file per Meta e Google e le classi di scrittura */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Catalog\Exports\GoogleMerchant;
use Wonder\Plugin\Gestionale\Support\Catalog\Exports\MetaCatalog;
use Wonder\Plugin\Gestionale\Support\Catalog\Exports\ProductExports;
use Wonder\Plugin\Gestionale\Support\Catalog\Exports\ProductFeed;
use Wonder\Plugin\Gestionale\Support\Catalog\Exports\ProductFeeds;
use Wonder\Plugin\Gestionale\Support\Export\Delimited;
use Wonder\Plugin\Gestionale\Support\Export\XmlFeed;

/** Una riga come la scrive `ProductExport::rows()`. */
$riga = static fn (array $extra = []): array => $extra + [
    'id' => '11', 'group' => 'MAG-1', 'sku' => 'MAG-1-BLU-S', 'ean' => '0012345678905', 'mpn' => 'X-1',
    'article' => 'Maglia', 'option' => 'Blu / S', 'type' => 'Prodotto', 'brand' => 'Aurora',
    'categories' => 'Abbigliamento › Maglie | Regali', 'price' => 29.9, 'sale_price' => null,
    'available' => 4.0, 'backorder' => 'No', 'visible' => 'Sì', 'visible_online' => 'Sì', 'active' => 'Sì',
    'weight' => 0.4, 'unit' => 'pz', 'link' => 'https://negozio.test/prodotto/maglia/blu/',
    'image' => 'https://negozio.test/a.jpg', 'short_description' => 'Corta', 'description' => 'Cotone "pesante", lungo',
    'images' => ['https://negozio.test/a.jpg', 'https://negozio.test/b.jpg', 'https://negozio.test/c.jpg'], 'tax_rate' => 22.0,
];
$ora = '2026-10-15 10:00:00';

// --- Delimited / XmlFeed ---

check('Delimited: csv standard, virgolette raddoppiate, formule lasciate stare', function () {
    $csv = Delimited::write(['a', 'b'], [['x "y"', '=1+1'], ['1,5', '']]);

    return $csv === "a,b\n\"x \"\"y\"\"\",=1+1\n\"1,5\",\n";
});

check('Delimited: per chi apre a mano le formule si disinnescano', fn () =>
    Delimited::write(['a'], [['=1+1'], ['-3'], ['ok']], Delimited::CSV, true) === "a\n'=1+1\n'-3\nok\n"
);

check('Delimited: tsv senza virgolette, tabulazioni e a capo diventano spazi', fn () =>
    Delimited::write(['a', 'b'], [["uno\tdue", "tre\nquattro"]], Delimited::TSV) === "a\tb\nuno due\ttre quattro\n"
);

check('XmlFeed: rss 2.0 col namespace g, campi ripetuti, vuoti saltati, caratteri escapati', function () {
    $xml = XmlFeed::rss(['title' => 'Negozio & co', 'link' => 'https://negozio.test/', 'description' => 'D'], [
        ['g:id' => '1', 'g:title' => 'A < B', 'g:gtin' => '', 'g:additional_image_link' => ['x.jpg', 'y.jpg'], 'g:mpn' => "M\x01N"],
    ]);
    $doc = simplexml_load_string($xml);

    if ($doc === false) {
        return false;
    }

    $g = $doc->channel->item->children(XmlFeed::GOOGLE);

    return (string) $doc['version'] === '2.0' && (string) $doc->channel->title === 'Negozio & co'
        && (string) $g->id === '1' && (string) $g->title === 'A < B' && count($g->additional_image_link) === 2
        && !isset($g->gtin) && (string) $g->mpn === 'MN';
});

// --- ProductFeed ---

check('feed: entra solo ciò che si compra sul sito', fn () =>
    ProductFeed::eligible($riga())
    && !ProductFeed::eligible($riga(['visible' => 'No']))
    && !ProductFeed::eligible($riga(['visible_online' => 'No']))
    && !ProductFeed::eligible($riga(['active' => 'No']))
    && !ProductFeed::eligible($riga(['price' => 0.0]))
    && !ProductFeed::eligible($riga(['link' => '']))
    && !ProductFeed::eligible($riga(['image' => '']))
);

check('feed: prezzi in "12.90 EUR", lordi quando il catalogo è senza IVA', fn () =>
    ProductFeed::money(ProductFeed::price($riga(), true), 'EUR') === '29.90 EUR'
    && ProductFeed::money(ProductFeed::price($riga(['price' => 10.0]), false), 'EUR') === '12.20 EUR'
    && ProductFeed::price($riga(['price' => 10.0, 'tax_rate' => null]), false) === 10.0
);

check('feed: lo sconto più basso tra scontato e campagna, mai sopra il prezzo', fn () =>
    ProductFeed::salePrice($riga(), true) === null
    && ProductFeed::salePrice($riga(['sale_price' => 19.9]), true) === 19.9
    && ProductFeed::salePrice($riga(['sale_price' => 19.9, 'campaign' => ['price' => 15.0, 'ends_at' => '']]), true) === 15.0
    && ProductFeed::salePrice($riga(['sale_price' => 35.0]), true) === null
);

check('feed: le date dello sconto solo per una campagna con la fine, che vince', function () use ($riga, $ora) {
    $date = ProductFeed::saleDates($riga(['campaign' => ['price' => 15.0, 'ends_at' => '2026-10-31 23:59:00']]), $ora);

    return preg_match('/^2026-10-15T10:00[+-]\d\d:\d\d\/2026-10-31T23:59[+-]\d\d:\d\d$/', $date) === 1
        && ProductFeed::saleDates($riga(['campaign' => ['price' => 15.0, 'ends_at' => '']]), $ora) === ''
        && ProductFeed::saleDates($riga(['sale_price' => 10.0, 'campaign' => ['price' => 15.0, 'ends_at' => '2026-10-31 23:59:00']]), $ora) === ''
        && ProductFeed::saleDates($riga(['sale_price' => 19.9]), $ora) === '';
});

check('feed: disponibile con giacenza, illimitato o vendibile scoperto', fn () =>
    ProductFeed::inStock($riga()) && ProductFeed::inStock($riga(['available' => null]))
    && !ProductFeed::inStock($riga(['available' => 0.0]))
    && ProductFeed::inStock($riga(['available' => 0.0, 'backorder' => 'Sì']))
);

check('feed: titolo, descrizione di ripiego, GTIN valido, peso e taglio', fn () =>
    ProductFeed::title($riga(), 150) === 'Maglia - Blu / S' && ProductFeed::title($riga(['option' => '']), 150) === 'Maglia'
    && ProductFeed::description($riga(['description' => '']), 100) === 'Corta'
    && ProductFeed::description($riga(['description' => '', 'short_description' => '']), 100) === 'Maglia - Blu / S'
    && ProductFeed::gtin($riga()) === '0012345678905' && ProductFeed::gtin($riga(['ean' => '123'])) === ''
    && ProductFeed::weight($riga()) === '0.4 kg' && ProductFeed::weight($riga(['weight' => 0.0])) === ''
    && ProductFeed::cut('abcdef', 4) === 'abc…' && ProductFeed::brand($riga(['brand' => '']), 'Negozio', 70) === 'Negozio'
);

// --- Meta ---

$meta = MetaCatalog::items([
    $riga(['campaign' => ['price' => 19.0, 'ends_at' => '2026-10-31 23:59:00']]),
    $riga(['id' => '12', 'option' => 'Rosso / S', 'ean' => '', 'available' => 0.0, 'images' => ['https://negozio.test/a.jpg']]),
], ['pricesIncludeTax' => true, 'currency' => 'EUR', 'now' => $ora, 'brand' => 'Negozio']);

check('meta: i campi obbligatori con i valori di Meta', fn () =>
    $meta[0]['id'] === '11' && $meta[0]['item_group_id'] === 'MAG-1' && $meta[0]['title'] === 'Maglia - Blu / S'
    && $meta[0]['availability'] === 'in stock' && $meta[1]['availability'] === 'out of stock'
    && $meta[0]['condition'] === 'new' && $meta[0]['price'] === '29.90 EUR' && $meta[0]['brand'] === 'Aurora'
    && $meta[0]['status'] === 'active' && $meta[0]['link'] === 'https://negozio.test/prodotto/maglia/blu/'
);

check('meta: sconto con date, foto in più separate da virgola, quantità e categoria', fn () =>
    $meta[0]['sale_price'] === '19.00 EUR' && $meta[0]['sale_price_effective_date'] !== ''
    && $meta[0]['additional_image_link'] === 'https://negozio.test/b.jpg,https://negozio.test/c.jpg'
    && $meta[1]['additional_image_link'] === ''
    && $meta[0]['quantity_to_sell_on_facebook'] === '4' && $meta[1]['quantity_to_sell_on_facebook'] === '0'
    && $meta[0]['product_type'] === 'Abbigliamento > Maglie' && $meta[0]['shipping_weight'] === '0.4 kg'
);

check('meta: un gruppo uguale a un id si lascia vuoto', fn () =>
    MetaCatalog::items([$riga(['group' => '11'])])[0]['item_group_id'] === ''
);

check('meta: il .csv ha le intestazioni di Meta e si rilegge uguale', function () use ($riga, $ora) {
    $csv = MetaCatalog::csv([$riga(['mpn' => '=X'])], ['now' => $ora]);
    $linee = array_map(static fn (string $l): array => str_getcsv($l, ',', '"', ''), explode("\n", trim($csv)));
    $voce = array_combine($linee[0], $linee[1]);

    return count($linee) === 2 && $linee[0][0] === 'id' && in_array('image_link', $linee[0], true)
        && $voce['description'] === 'Cotone "pesante", lungo' && $voce['mpn'] === '=X';
});

// --- Google ---

$google = GoogleMerchant::items([
    $riga(),
    $riga(['id' => '12', 'option' => 'Rosso / S', 'ean' => '', 'mpn' => '', 'available' => 0.0, 'sale_price' => 19.9]),
    $riga(['id' => '20', 'group' => 'SOLO', 'option' => '']),
], ['pricesIncludeTax' => true, 'currency' => 'EUR', 'now' => $ora, 'brand' => 'Negozio']);

check('google: i campi g: con i valori di Google', fn () =>
    $google[0]['g:id'] === '11' && $google[0]['g:availability'] === 'in_stock' && $google[1]['g:availability'] === 'out_of_stock'
    && $google[0]['g:price'] === '29.90 EUR' && $google[1]['g:sale_price'] === '19.90 EUR'
    && $google[0]['g:condition'] === 'new' && $google[0]['g:gtin'] === '0012345678905'
    && $google[0]['g:additional_image_link'] === ['https://negozio.test/b.jpg', 'https://negozio.test/c.jpg']
);

check('google: item_group_id solo quando l\'articolo ha più opzioni', fn () =>
    $google[0]['g:item_group_id'] === 'MAG-1' && $google[2]['g:item_group_id'] === ''
);

check('google: identifier_exists no solo senza EAN e senza MPN', fn () =>
    $google[0]['g:identifier_exists'] === '' && $google[1]['g:identifier_exists'] === 'no'
);

check('google: un xml RSS che si rilegge, un item per riga', function () use ($riga, $ora) {
    $doc = simplexml_load_string(GoogleMerchant::xml([$riga(), $riga(['id' => '12'])], ['now' => $ora, 'brand' => 'Negozio', 'url' => 'https://negozio.test/']));

    return $doc !== false && count($doc->channel->item) === 2 && (string) $doc->channel->title === 'Negozio'
        && (string) $doc->channel->item[1]->children(XmlFeed::GOOGLE)->id === '12';
});

// --- Registro ---

check('registro: solo i formati concessi, nell\'ordine del registro', fn () =>
    ProductExports::granted(static fn (string $f): bool => in_array($f, ['export_google', 'export_csv'], true))
        === ['csv' => 'CSV (.csv)', 'google' => 'Google Merchant Center (.xml)']
    && ProductExports::granted(static fn (): bool => false) === []
);

check('registro: ogni formato ha la sua funzionalità nel catalogo, area Esportazioni', function () {
    $catalogo = require __DIR__.'/../config/features.php';

    foreach (ProductExports::FORMATS as $formato) {
        if (($catalogo[$formato['feature']]['area'] ?? '') !== 'Esportazioni' || ($catalogo[$formato['feature']]['created'] ?? true) === false) {
            return false;
        }
    }

    return true;
});

check('registro: nomi dei file per formato', fn () =>
    ProductExports::fileName('meta', mktime(12, 0, 0, 10, 15, 2026)) === 'catalogo-meta-2026-10-15.csv'
    && ProductExports::fileName('google', mktime(12, 0, 0, 10, 15, 2026)) === 'google-merchant-2026-10-15.xml'
    && ProductExports::fileName('xlsx', mktime(12, 0, 0, 10, 15, 2026)) === 'prodotti-2026-10-15.xlsx'
);

check('registro: un formato sconosciuto non passa', function () {
    try {
        ProductExports::fileName('tiktok');
    } catch (InvalidArgumentException) {
        return true;
    }

    return false;
});

// --- Feed a indirizzo fisso ---

check('feed: si riscrive se manca o se è scaduto, con cinque minuti di margine', fn () =>
    ProductFeeds::due(null, 6, 100000)
    && !ProductFeeds::due(100000, 6, 100000 + 3 * 3600)
    && ProductFeeds::due(100000, 6, 100000 + 6 * 3600 - 120)
    && ProductFeeds::due(100000, 1, 100000 + 3600)
);

check('feed: frequenza dalle impostazioni, una scelta fuori elenco vale il default', fn () =>
    ProductFeeds::every('google', ['every' => 24]) === 24
    && ProductFeeds::every('meta', ['every' => 5]) === ProductFeeds::DEFAULT_EVERY
    && ProductFeeds::every('meta', []) === 6
);

check('feed: indirizzo dal dominio di produzione, altrimenti dal sito', fn () =>
    ProductFeeds::url('google', 'negozio.it', 'https://negozio.test/') === 'https://negozio.it/shared/feeds/google-merchant.xml'
    && ProductFeeds::url('meta', 'https://negozio.it/', '') === 'https://negozio.it/shared/feeds/catalogo-meta.csv'
    && ProductFeeds::url('meta', '', 'https://negozio.test/') === 'https://negozio.test/shared/feeds/catalogo-meta.csv'
);

check('feed: ogni feed ha la funzionalità del suo formato', fn () =>
    ProductFeeds::forFeature('export_google') === 'google'
    && ProductFeeds::forFeature('export_meta') === 'meta'
    && ProductFeeds::forFeature('export_csv') === null
);

check('feed: sbloccato si scrive, bloccato si cancella, non scaduto resta', function () {
    $dir = sys_get_temp_dir().'/gst-feeds-'.bin2hex(random_bytes(4));
    $acceso = ['google' => true, 'meta' => true];
    $giro = static function (int $ora) use ($dir, &$acceso): array {
        return ProductFeeds::refresh(
            $dir,
            static fn (string $k): bool => $acceso[$k],
            static fn (string $k): int => 6,
            static fn (string $k): string => 'feed '.$k,
            $ora,
        );
    };

    $primo = $giro(time());
    $ok = $primo['written'] === ['meta', 'google'] && file_get_contents($dir.'/google-merchant.xml') === 'feed google';
    $secondo = $giro(time() + 3600);
    $ok = $ok && $secondo['skipped'] === ['meta', 'google'];
    $terzo = $giro(time() + 6 * 3600);
    $ok = $ok && $terzo['written'] === ['meta', 'google'];

    $acceso['meta'] = false;
    $quarto = $giro(time() + 6 * 3600);
    $ok = $ok && $quarto['removed'] === ['meta'] && !is_file($dir.'/catalogo-meta.csv')
        && glob($dir.'/*.tmp') === [];

    array_map('unlink', glob($dir.'/*'));
    rmdir($dir);

    return $ok;
});

summary();
