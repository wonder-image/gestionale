<?php
/** php tests/SettingsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\System\MerchantSetting;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Resources\System\MerchantSettingResource;
use Wonder\Plugin\Gestionale\Resources\System\SettingResource;
use Wonder\Sql\TableSchema as Column;

$colonne = static function (string $model): array {
    $nomi = [];

    foreach ($model::tableSchema() as $column) {
        $nomi[] = (string) $column->name;
    }

    return $nomi;
};

check('le impostazioni tecniche sono una riga sola che viaggia col deploy', function () {
    $schema = Setting::syncSchema();

    return $schema !== null && $schema->singleton && $schema->localOnly;
});

check('le impostazioni del commerciante restano nel loro ambiente', fn () =>
    MerchantSetting::syncSchema() === null
);

check('le colonne fiscali e dei documenti di G1 ci sono tutte', function () use ($colonne) {
    $attese = [
        'invoice_provider', 'tax_regime', 'vat_collectability', 'transmitter_country',
        'transmitter_fiscal_code', 'catalog_prices_include_tax', 'fallback_tax_id',
        'shipping_tax_id', 'invoice_numeration', 'stamp_duty_auto', 'fiscal_confirmed_at',
        'developer_error_emails',
    ];

    return array_diff($attese, $colonne(Setting::class)) === [];
});

check('il commerciante ha i suoi destinatari delle notifiche', fn () =>
    in_array('merchant_notification_emails', $colonne(MerchantSetting::class), true)
);

check('le due pagine hanno padrone diverso', function () {
    $tecniche = SettingResource::permissionSchema()->toArray();
    $commerciante = MerchantSettingResource::permissionSchema()->toArray();

    foreach ($tecniche['backend'] ?? [] as $authorities) {
        if ($authorities !== [] && $authorities !== ['admin']) {
            return false;
        }
    }

    foreach ($commerciante['backend'] ?? [] as $authorities) {
        if ($authorities !== [] && $authorities !== ['administrator']) {
            return false;
        }
    }

    return true;
});

check('le tecniche stanno in Set Up, quelle del commerciante nel gestionale', fn () =>
    (SettingResource::navigationSchema()->toArray()['section_key'] ?? '') === 'set-up'
    && (MerchantSettingResource::navigationSchema()->toArray()['section_key'] ?? '') === 'gestionale'
);

check('salvando le impostazioni fiscali si segna la conferma', function () {
    $valori = SettingResource::mutateRequestValues(['tax_regime' => 'RF01'], 'update');

    return trim((string) ($valori['fiscal_confirmed_at'] ?? '')) !== '';
});

check('una conferma già data non si riscrive', function () {
    $valori = SettingResource::mutateRequestValues(
        ['tax_regime' => 'RF01'],
        'update',
        'backend',
        ['fiscal_confirmed_at' => '2026-01-01 10:00:00']
    );

    return ($valori['fiscal_confirmed_at'] ?? '') === '2026-01-01 10:00:00';
});

summary();
