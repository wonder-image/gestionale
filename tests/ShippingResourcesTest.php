<?php
/** php tests/ShippingResourcesTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\App\ResourceSchema\Inputs\InputCountry;
use Wonder\App\ResourceSchema\Inputs\InputStates;
use Wonder\Plugin\Gestionale\Models\Shipping\Carrier;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingMethod;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingZone;
use Wonder\Plugin\Gestionale\Resources\Catalog\PackageResource;
use Wonder\Plugin\Gestionale\Resources\Shipping\CarrierResource;
use Wonder\Plugin\Gestionale\Resources\Shipping\ShippingMethodResource;
use Wonder\Plugin\Gestionale\Resources\Shipping\ShippingZoneResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Shipping\RateForm;

/** I nomi dei campi del form, nell'ordine dichiarato. @return list<string> */
$campi = static fn (string $resource): array => array_map(
    static fn ($input): string => (string) $input->name,
    $resource::formSchema()
);

check('le tre pagine stanno sotto Spedizioni, dietro la funzionalità, solo per l\'admin', function () {
    $attese = [
        [CarrierResource::class, Carrier::class, 'app/gestionale/corrieri'],
        [ShippingZoneResource::class, ShippingZone::class, 'app/gestionale/zone-di-spedizione'],
        [ShippingMethodResource::class, ShippingMethod::class, 'app/gestionale/metodi-di-spedizione'],
    ];

    foreach ($attese as [$resource, $model, $path]) {
        $menu = $resource::navigationSchema()->toArray();

        if ($resource::$feature !== 'shipping'
            || $resource::$model !== $model
            || $resource::path() !== $path
            || ($menu['section_key'] ?? '') !== 'set-up'
            || ($menu['authority'] ?? []) !== ['admin', 'administrator']
            || str_starts_with($resource::$docsPage, 'spedizioni/') === false) {
            return false;
        }
    }

    return true;
});

check('in Set-up le quattro pagine stanno nel menu a tendina «Spedizioni»: metodi, zone, corrieri, imballaggi', function () {
    $attese = [
        ShippingMethodResource::class => 'Metodi di spedizione',
        ShippingZoneResource::class => 'Zone di spedizione',
        CarrierResource::class => 'Corrieri',
        PackageResource::class => 'Imballaggi',
    ];
    $ordine = [];

    foreach ($attese as $resource => $titolo) {
        $menu = $resource::navigationSchema()->toArray();

        if (($menu['section_key'] ?? '') !== 'set-up'
            || ($menu['group_key'] ?? '') !== 'spedizioni'
            || ($menu['group']['title'] ?? '') !== 'Spedizioni'
            || ($menu['title'] ?? '') !== $titolo) {
            return false;
        }

        $ordine[$titolo] = (int) ($menu['order'] ?? 0);
    }

    $voci = array_keys($ordine);
    asort($ordine);

    return array_keys($ordine) === $voci;
});

check('la zona si crea anche dalla finestra «Nuova zona…»: nome, paese e provincia, solo lo store', function () {
    $schema = ShippingZoneResource::apiSchema()->toArray();
    $nomi = array_map(static fn ($input): string => (string) $input->name, ShippingZoneResource::quickCreateFields());

    return ($schema['routes']['store'] ?? false) === true
        && ($schema['fields']['store'] ?? null) === ['name', 'country', 'province']
        && array_keys(array_filter((array) $schema['routes'])) === ['store']
        && $nomi === ['name', 'country', 'province'];
});

check('con la funzionalità spenta le pagine non ci sono e il menu le nasconde', function () {
    // Senza database la funzionalità risulta spenta.
    foreach ([CarrierResource::class, ShippingZoneResource::class, ShippingMethodResource::class] as $resource) {
        $pagine = $resource::pageSchema()->toArray()['pages'] ?? [];

        if ($resource::featureActive() !== false
            || ($resource::navigationSchema()->toArray()['enabled'] ?? true) !== false
            || in_array(true, $pagine, true)) {
            return false;
        }
    }

    return true;
});

check('il corriere ha nome, dati aziendali, link di tracking, attivo e ordine', function () use ($campi) {
    return array_diff(['name', 'tracking_url_template', 'active'], $campi(CarrierResource::class)) === []
        && CarrierResource::getInput('tracking_url_template') !== null;
});

check('la zona ha il nome e la tabella delle aree', function () use ($campi) {
    return array_diff(['name', 'areas'], $campi(ShippingZoneResource::class)) === [];
});

check('nelle aree della zona paese e provincia sono due select, la provincia sceglie dal paese', function () {
    $input = ShippingZoneResource::getInput('areas');
    // Le colonne, nell'ordine dichiarato: id, paese, provincia.
    $colonne = (new ReflectionProperty($input, 'schema'))->getValue($input)['context']['columns'];

    return ($colonne[1] ?? null) instanceof InputCountry
        && ($colonne[2] ?? null) instanceof InputStates;
});

check('nella tabella degli scaglioni il peso massimo si vede sempre: il core non risolve le regole di visibilità nei repeater annidati', function () {
    $campi = (new ReflectionMethod(ShippingMethodResource::class, 'rateFields'))->invoke(null, 1);
    $scaglioni = array_values(array_filter($campi, static fn ($input): bool => (string) $input->name === 'rate_1_brackets'))[0];
    $colonne = (new ReflectionProperty($scaglioni, 'schema'))->getValue($scaglioni)['context']['columns'];
    $peso = array_values(array_filter($colonne, static fn ($colonna): bool => (string) $colonna->name === 'max_weight'))[0];

    return $peso->conditionalAttributes() === [];
});

check('il metodo ha i suoi campi, e nessun riquadro di listino se non ci sono zone', function () use ($campi) {
    $attesi = ['name', 'description', 'carrier_id', 'provider_service_code', 'applies_online', 'applies_office', 'active'];

    // Senza database non ci sono zone: niente campi `rate_*`.
    return array_diff($attesi, $campi(ShippingMethodResource::class)) === []
        && array_filter($campi(ShippingMethodResource::class), static fn (string $n): bool => str_starts_with($n, 'rate_')) === [];
});

check('le tabelle elencano nome e stato', function () {
    foreach ([CarrierResource::class, ShippingZoneResource::class, ShippingMethodResource::class] as $resource) {
        $colonne = array_map(static fn ($c): string => (string) $c->name, $resource::tableSchema());

        if (!in_array('name', $colonne, true) || !in_array('actions', $colonne, true)) {
            return false;
        }
    }

    return true;
});

check('readRates legge solo le zone accese e normalizza i numeri', function () {
    $post = [
        'rate_3_on' => 'true',
        'rate_3_excess_mode' => 'excess_only',
        'rate_3_volumetric_divisor' => '5000',
        'rate_3_fuel_surcharge_percent' => '7,5',
        'rate_3_markup_percent' => '-5',
        'rate_3_rounding_step' => '0,50',
        'rate_3_min_price' => '',
        'rate_3_free_over_amount' => '100,00',
        'rate_3_free_under_weight' => '',
        'rate_3_cod_fee' => '4',
        'rate_3_brackets' => [
            ['type' => 'price', 'max_weight' => '5', 'amount' => '9,90'],
            ['type' => 'excess', 'max_weight' => '', 'amount' => '1,5'],
        ],
        'rate_4_on' => 'false',
        'rate_4_markup_percent' => '3',
        'name' => 'Standard',
    ];

    $rates = RateForm::readRates($post);

    return array_keys($rates) === [3]
        && $rates[3]['excess_mode'] === 'excess_only'
        && $rates[3]['volumetric_divisor'] === '5000'
        && $rates[3]['fuel_surcharge_percent'] === '7.5'
        && $rates[3]['markup_percent'] === '-5'
        && $rates[3]['rounding_step'] === '0.50'
        && $rates[3]['min_price'] === '0'
        && $rates[3]['free_over_amount'] === '100.00'
        && $rates[3]['free_under_weight'] === null
        && $rates[3]['cod_fee'] === '4'
        && $rates[3]['brackets'] === [
            ['type' => 'price', 'max_weight' => '5', 'amount' => '9.90'],
            ['type' => 'excess', 'max_weight' => null, 'amount' => '1.5'],
        ];
});

check('readRates toglie gli scaglioni vuoti e ordina quelli di prezzo per peso', function () {
    $rates = RateForm::readRates([
        'rate_1_on' => 'true',
        'rate_1_brackets' => [
            ['type' => 'price', 'max_weight' => '10', 'amount' => '12'],
            ['type' => 'price', 'max_weight' => '', 'amount' => ''],
            ['type' => 'price', 'max_weight' => '2', 'amount' => '6'],
            ['type' => 'excess', 'max_weight' => '', 'amount' => ''],
        ],
    ]);

    return array_column($rates[1]['brackets'], 'max_weight') === ['2', '10'];
});

check('readRates non si rompe con una richiesta storta', function () {
    $rates = RateForm::readRates([
        'rate_x_on' => 'true',
        'rate_0_on' => 'true',
        'rate_2_on' => 'true',
        'rate_2_markup_percent' => ['no'],
        'rate_2_brackets' => 'non una lista',
        'rate_5_on' => 'true',
        'rate_5_brackets' => [['type' => ['x'], 'max_weight' => ['1'], 'amount' => '3'], 'testo', null],
    ]);

    return array_keys($rates) === [2, 5]
        && $rates[2]['brackets'] === []
        && $rates[2]['markup_percent'] === '0'
        && $rates[5]['brackets'] === [];
});

/** Il listino più semplice che sta in piedi. */
$listino = static fn (array $in = []): array => $in + [
    'excess_mode' => 'total_weight',
    'volumetric_divisor' => null,
    'fuel_surcharge_percent' => '0',
    'markup_percent' => '0',
    'rounding_step' => null,
    'min_price' => '0',
    'free_over_amount' => null,
    'free_under_weight' => null,
    'cod_fee' => '0',
    'brackets' => [['type' => 'price', 'max_weight' => '5', 'amount' => '9.90']],
];

/** La chiave dell'errore con cui la validazione si ferma, '' se passa. */
$errore = static function (array $rates): string {
    try {
        RateForm::validate($rates);
    } catch (UserError $e) {
        return $e->key();
    }

    return '';
};

check('readRates legge il tipo di prezzo: a scaglioni se non detto, fisso con il suo importo', function () {
    $rates = RateForm::readRates([
        'rate_1_on' => 'true',
        'rate_2_on' => 'true', 'rate_2_price_type' => 'fixed', 'rate_2_fixed_price' => '7,50',
        'rate_3_on' => 'true', 'rate_3_price_type' => 'boh', 'rate_3_fixed_price' => ['x'],
    ]);

    return $rates[1]['price_type'] === 'brackets' && $rates[1]['fixed_price'] === null
        && $rates[2]['price_type'] === 'fixed' && $rates[2]['fixed_price'] === '7.50'
        && $rates[3]['price_type'] === 'brackets';
});

check('un listino a prezzo fisso non vuole scaglioni ma vuole l\'importo', function () use ($listino, $errore) {
    $fisso = static fn (array $in = []): array => $listino($in + ['price_type' => 'fixed', 'fixed_price' => '7.50', 'brackets' => []]);

    return $errore([1 => $fisso()]) === ''
        && $errore([1 => $fisso(['fixed_price' => '0'])]) === ''
        && $errore([1 => $fisso(['fixed_price' => null])]) === 'shipping.fixed_price_missing'
        && $errore([1 => $fisso(['fixed_price' => '-1'])]) === 'shipping.rate_negative';
});

check('un listino a prezzo fisso non si ferma sui campi a scaglioni nascosti', function () use ($listino, $errore) {
    return $errore([1 => $listino([
        'price_type' => 'fixed', 'fixed_price' => '5', 'brackets' => [], 'markup_percent' => '-500', 'min_price' => '-3',
    ])]) === '';
});

check('un listino a posto passa, anche con il margine che porta sotto zero', function () use ($listino, $errore) {
    return $errore([1 => $listino()]) === ''
        && $errore([1 => $listino(['markup_percent' => '-100'])]) === ''
        && $errore([]) === '';
});

check('un listino senza scaglioni di prezzo non sta in piedi', function () use ($listino, $errore) {
    return $errore([1 => $listino(['brackets' => []])]) === 'shipping.rate_no_brackets'
        && $errore([1 => $listino(['brackets' => [['type' => 'excess', 'max_weight' => null, 'amount' => '2']]])]) === 'shipping.rate_no_brackets';
});

check('due scaglioni con lo stesso peso massimo sono rifiutati', function () use ($listino, $errore) {
    return $errore([1 => $listino(['brackets' => [
        ['type' => 'price', 'max_weight' => '5', 'amount' => '9'],
        ['type' => 'price', 'max_weight' => '5.000', 'amount' => '12'],
    ]])]) === 'shipping.bracket_duplicate';
});

check('peso massimo zero o mancante e importo negativo sono rifiutati', function () use ($listino, $errore) {
    $uno = static fn (array $b): array => [1 => $listino(['brackets' => [$b]])];

    return $errore($uno(['type' => 'price', 'max_weight' => '0', 'amount' => '9'])) === 'shipping.bracket_weight'
        && $errore($uno(['type' => 'price', 'max_weight' => null, 'amount' => '9'])) === 'shipping.bracket_weight'
        && $errore($uno(['type' => 'price', 'max_weight' => '5', 'amount' => '-1'])) === 'shipping.bracket_amount'
        && $errore($uno(['type' => 'price', 'max_weight' => '5', 'amount' => null])) === 'shipping.bracket_amount';
});

check('la tariffa al kg è una sola e non è negativa', function () use ($listino, $errore) {
    $prezzo = ['type' => 'price', 'max_weight' => '5', 'amount' => '9'];

    return $errore([1 => $listino(['brackets' => [$prezzo,
        ['type' => 'excess', 'max_weight' => null, 'amount' => '2'],
        ['type' => 'excess', 'max_weight' => null, 'amount' => '3'],
    ]])]) === 'shipping.excess_duplicate'
        && $errore([1 => $listino(['brackets' => [$prezzo, ['type' => 'excess', 'max_weight' => null, 'amount' => '-2']]])]) === 'shipping.bracket_amount';
});

check('i valori del listino non negativi, il margine non sotto −100 %', function () use ($listino, $errore) {
    foreach (['fuel_surcharge_percent', 'min_price', 'rounding_step', 'free_over_amount', 'free_under_weight', 'cod_fee', 'volumetric_divisor'] as $campo) {
        if ($errore([1 => $listino([$campo => '-1'])]) !== 'shipping.rate_negative') {
            return false;
        }
    }

    return $errore([1 => $listino(['markup_percent' => '-100.01'])]) === 'shipping.markup_out_of_range';
});

check('le frasi della validazione esistono in italiano', function () {
    $json = json_decode((string) file_get_contents(__DIR__ . '/../lang/it/gestionale.json'), true);
    $frasi = $json['gestionale']['errors']['shipping'] ?? [];

    foreach ([
        'rate_no_brackets', 'fixed_price_missing', 'bracket_duplicate', 'bracket_weight', 'bracket_amount', 'excess_duplicate',
        'rate_negative', 'markup_out_of_range', 'zone_no_areas', 'area_duplicate', 'area_country',
    ] as $chiave) {
        if (trim((string) ($frasi[$chiave] ?? '')) === '') {
            return false;
        }
    }

    return true;
});

summary();
