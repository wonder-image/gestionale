<?php
/** php tests/ChannelPriceTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Pricing\ChannelPrice;
use Wonder\Plugin\Gestionale\Support\Pricing\PriceListChannels;
use Wonder\Plugin\Gestionale\Support\Sales\Channels;

PriceListChannels::reset();

check('il registro parte dai canali del gestionale', function () {
    return array_keys(PriceListChannels::all([])) === ['online', 'office', 'pos', 'google', 'meta']
        && PriceListChannels::label('google', []) === 'Google Shopping';
});

check('un modulo aggiunge, rinomina e toglie i suoi canali', function () {
    $configs = [
        ['price_lists' => ['channels' => ['amazon' => 'Amazon', 'meta' => 'Meta Shops']]],
        ['price_lists' => ['channels' => ['pos' => false]]],
        ['import' => ['layouts' => []]],
    ];
    $all = PriceListChannels::all($configs);

    return ($all['amazon'] ?? null) === 'Amazon'
        && $all['meta'] === 'Meta Shops'
        && !array_key_exists('pos', $all)
        && PriceListChannels::has('amazon', $configs)
        && !PriceListChannels::has('amazon', []);
});

check('il sito ha l\'ultima parola, e reset() la toglie', function () {
    PriceListChannels::set('zalando', 'Zalando');
    PriceListChannels::set('ebay', false);
    $configs = [['price_lists' => ['channels' => ['ebay' => 'eBay Italia']]]];
    $all = PriceListChannels::all($configs);
    PriceListChannels::reset();

    return ($all['zalando'] ?? null) === 'Zalando'
        && !array_key_exists('ebay', $all)
        && array_key_exists('ebay', PriceListChannels::all($configs))
        && !array_key_exists('zalando', PriceListChannels::all([]));
});

check('una voce non valida si salta, le altre restano', function () {
    $all = PriceListChannels::all([['price_lists' => ['channels' => [
        'Con Spazi' => 'No',
        'vuoto' => '  ',
        'numero' => 12,
        'otto' => 'Otto',
        'chiave_troppo_lunga_per_la_colonna_channel' => 'No',
    ]]]]);

    return ($all['otto'] ?? null) === 'Otto'
        && !array_key_exists('Con Spazi', $all)
        && !array_key_exists('vuoto', $all)
        && !array_key_exists('numero', $all)
        && count($all) === 6;
});

check('attivi: le vendite accese, i feed col loro interruttore, i canali dei moduli sempre', function () {
    $all = PriceListChannels::all([['price_lists' => ['channels' => ['amazon' => 'Amazon']]]]);
    $off = array_keys(PriceListChannels::activeFrom([], $all));
    $on = array_keys(PriceListChannels::activeFrom(['office_sales' => true, 'pos' => true, 'export_meta' => true], $all));

    return $off === ['online', 'amazon']
        && $on === ['office', 'pos', 'meta', 'amazon']
        && !in_array('google', $on, true);
});

check('nessun canale scrive bindCommerce', function () {
    return !str_contains(strtolower(implode(' ', PriceListChannels::all([]))), 'bindcommerce')
        && !str_contains(strtolower(implode(' ', Channels::PRICE_LIST_CHANNELS)), 'bindcommerce');
});

check('Channels::priceListChannels() legge il registro', function () {
    PriceListChannels::set('amazon', 'Amazon');
    $ok = (Channels::priceListChannels()['amazon'] ?? null) === 'Amazon';
    PriceListChannels::reset();

    return $ok;
});

check('un canale sconosciuto è un errore, non il prezzo base', function () {
    try {
        ChannelPrice::lists('gogle');
    } catch (InvalidArgumentException $e) {
        return str_contains($e->getMessage(), 'gogle');
    }

    return false;
});

$listino = ['id' => 4, 'prices_include_tax' => true, 'rule' => 'discount', 'percent' => 10.0, 'items' => [7 => [['min_quantity' => 1.0, 'price' => 8.0]]]];
$solo = ['id' => 5, 'prices_include_tax' => true, 'rule' => 'none', 'percent' => 0.0, 'items' => [7 => [['min_quantity' => 1.0, 'price' => 8.0]]]];
$righe = [
    ['id' => 7, 'price' => 10.0, 'tax_rate' => 22.0, 'sale_price' => 9.5, 'campaign' => ['price' => 9.0, 'ends_at' => '']],
    ['id' => 9, 'price' => 20.0, 'tax_rate' => 22.0, 'sale_price' => 19.0],
];

check('applyLists: la riga del listino vince e cadono scontato e campagna', function () use ($listino, $righe) {
    $out = ChannelPrice::applyLists($righe, [$listino], true);

    return $out[0]['price'] === 8.0
        && $out[0]['price_list_id'] === 4
        && $out[0]['sale_price'] === null
        && !array_key_exists('campaign', $out[0])
        && $out[1]['price'] === 18.0
        && $out[1]['price_list_id'] === 4;
});

check('applyLists: il prodotto che il listino non trova resta com\'è', function () use ($solo, $righe) {
    $out = ChannelPrice::applyLists($righe, [$solo], true);

    return $out[1] === $righe[1] && $out[0]['price'] === 8.0;
});

check('applyLists senza listini non tocca le righe', fn () => ChannelPrice::applyLists($righe, [], true) === $righe);

summary();
