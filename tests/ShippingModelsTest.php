<?php
/** php tests/ShippingModelsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Shipping\Carrier;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingMethod;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingRate;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingRateBracket;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingZone;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingZoneArea;
use Wonder\Plugin\Gestionale\Support\Codes;

$colonne = static function (string $model): array {
    $colonne = [];

    foreach ($model::tableSchema() as $column) {
        $colonne[(string) $column->name] = $column;
    }

    return $colonne;
};

$campo = static function (string $model, string $key): ?object {
    foreach ($model::dataSchema() as $field) {
        if ((string) $field->key === $key) {
            return $field;
        }
    }

    return null;
};

$tutti = [
    Carrier::class,
    ShippingMethod::class,
    ShippingZone::class,
    ShippingZoneArea::class,
    ShippingRate::class,
    ShippingRateBracket::class,
];

check('le tabelle hanno il prefisso del gestionale e la loro cartella', fn () =>
    Carrier::$table === 'gst_carriers'
    && ShippingMethod::$table === 'gst_shipping_methods'
    && ShippingZone::$table === 'gst_shipping_zones'
    && ShippingZoneArea::$table === 'gst_shipping_zone_areas'
    && ShippingRate::$table === 'gst_shipping_rates'
    && ShippingRateBracket::$table === 'gst_shipping_rate_brackets'
    && Carrier::$folder === 'gestionale/models'
);

check('i listini sono lavoro del commerciante: non viaggiano con il deploy', function () use ($tutti) {
    foreach ($tutti as $model) {
        if ($model::syncSchema() !== null) {
            return false;
        }
    }

    return true;
});

check('ogni tabella ha le sue colonne', function () use ($colonne) {
    $attese = [
        Carrier::class => ['code', 'name', 'tracking_url_template', 'provider', 'active', 'position'],
        ShippingMethod::class => [
            'code', 'name', 'description', 'carrier_id', 'provider_service_code',
            'applies_online', 'applies_office', 'active', 'position',
        ],
        ShippingZone::class => ['code', 'name', 'position'],
        ShippingZoneArea::class => ['shipping_zone_id', 'country', 'province'],
        ShippingRate::class => [
            'shipping_method_id', 'shipping_zone_id', 'volumetric_divisor', 'excess_mode',
            'fuel_surcharge_percent', 'markup_percent', 'rounding_step', 'min_price',
            'free_over_amount', 'free_under_weight', 'cod_fee', 'active',
        ],
        ShippingRateBracket::class => ['shipping_rate_id', 'type', 'max_weight', 'amount'],
    ];

    foreach ($attese as $model => $nomi) {
        $presenti = array_keys($colonne($model));

        foreach ($nomi as $nome) {
            if (!in_array($nome, $presenti, true)) {
                return false;
            }
        }
    }

    return true;
});

check('gli importi hanno due decimali veri e i pesi tre', function () use ($colonne) {
    $r = $colonne(ShippingRate::class);
    $b = $colonne(ShippingRateBracket::class);

    return $r['min_price']->getSchema('type') === 'DECIMAL'
        && $r['min_price']->getSchema('length') === '12,2'
        && $r['cod_fee']->getSchema('length') === '12,2'
        && $r['free_over_amount']->getSchema('length') === '12,2'
        && $r['rounding_step']->getSchema('length') === '12,2'
        && $r['free_under_weight']->getSchema('length') === '10,3'
        && $b['max_weight']->getSchema('length') === '10,3'
        && $b['amount']->getSchema('length') === '12,2';
});

check('i campi facoltativi del listino possono mancare (NULL non è zero)', function () use ($colonne) {
    $r = $colonne(ShippingRate::class);

    foreach (['free_over_amount', 'free_under_weight', 'rounding_step', 'volumetric_divisor'] as $nome) {
        if ($r[$nome]->getSchema('null') === false || $r[$nome]->getSchema('default') !== null) {
            return false;
        }
    }

    return true;
});

check('il margine può essere negativo: la colonna ha il segno', function () use ($colonne) {
    $r = $colonne(ShippingRate::class);

    return $r['markup_percent']->getSchema('type') === 'DECIMAL'
        && $r['markup_percent']->getSchema('unsigned') !== true;
});

check('i default: attivo sì, scaglioni sull\'intero peso, riga di prezzo', function () use ($colonne) {
    $r = $colonne(ShippingRate::class);

    return $r['active']->getSchema('default') === 'true'
        && $r['excess_mode']->getSchema('default') === 'total_weight'
        && $r['excess_mode']->getSchema('enum') === ['total_weight', 'excess_only']
        && $colonne(ShippingRateBracket::class)['type']->getSchema('default') === 'price'
        && $colonne(ShippingRateBracket::class)['type']->getSchema('enum') === ['price', 'excess']
        && $colonne(Carrier::class)['active']->getSchema('default') === 'true'
        && $colonne(Carrier::class)['provider']->getSchema('default') === 'manual'
        && $colonne(ShippingMethod::class)['active']->getSchema('default') === 'true'
        && $colonne(ShippingMethod::class)['applies_online']->getSchema('default') === 'true'
        && $colonne(ShippingMethod::class)['applies_office']->getSchema('default') === 'true';
});

check('il metodo senza corriere ha carrier_id 0 e nessuna chiave esterna', function () use ($colonne) {
    $c = $colonne(ShippingMethod::class)['carrier_id'];

    return (string) $c->getSchema('default') === '0' && $c->getSchema('foreign_table') === null;
});

check('le chiavi esterne puntano a ciò che collegano', function () use ($colonne) {
    return $colonne(ShippingZoneArea::class)['shipping_zone_id']->getSchema('foreign_table') === ShippingZone::$table
        && $colonne(ShippingRate::class)['shipping_method_id']->getSchema('foreign_table') === ShippingMethod::$table
        && $colonne(ShippingRate::class)['shipping_zone_id']->getSchema('foreign_table') === ShippingZone::$table
        && $colonne(ShippingRateBracket::class)['shipping_rate_id']->getSchema('foreign_table') === ShippingRate::$table;
});

check('un solo listino per coppia metodo-zona e gli indici dei collegamenti', function () {
    $rate = ShippingRate::tablePseudos();
    $uni = $rate['uni_method_zone']['unique'] ?? null;

    return $uni === ['shipping_method_id', 'shipping_zone_id']
        && array_key_exists('ind_rate', ShippingRateBracket::tablePseudos())
        && array_key_exists('ind_zone', ShippingZoneArea::tablePseudos());
});

check('i codici hanno i loro prefissi', function () use ($campo) {
    return ($campo(Carrier::class, 'code')?->getSchema('unique_code')['prefix'] ?? null) === Codes::CARRIER
        && ($campo(ShippingMethod::class, 'code')?->getSchema('unique_code')['prefix'] ?? null) === Codes::SHIPPING_METHOD
        && ($campo(ShippingZone::class, 'code')?->getSchema('unique_code')['prefix'] ?? null) === Codes::SHIPPING_ZONE
        && Codes::CARRIER === 'car_'
        && Codes::SHIPPING_METHOD === 'shm_'
        && Codes::SHIPPING_ZONE === 'shz_';
});

check('nessuna colonna usa una parola riservata di MySQL', function () use ($colonne, $tutti) {
    $riservate = ['key', 'group', 'order', 'index', 'default', 'type_'];

    foreach ($tutti as $model) {
        foreach (array_keys($colonne($model)) as $nome) {
            if (in_array(strtolower((string) $nome), $riservate, true)) {
                return false;
            }
        }
    }

    return true;
});

summary();
