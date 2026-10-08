<?php
/** php tests/SettingsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Gestionale;
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

check('le email per gli ordini stanno in Set Up, vicino a quelle degli errori', function () use ($colonne) {
    $campi = array_map(static fn ($field): string => (string) $field->name, SettingResource::formSchema());

    return in_array('merchant_notification_emails', $colonne(Setting::class), true)
        && !in_array('merchant_notification_emails', $colonne(MerchantSetting::class), true)
        && in_array('merchant_notification_emails', $campi, true)
        && !in_array('merchant_notification_emails', array_map(static fn ($field): string => (string) $field->name, MerchantSettingResource::formSchema()), true);
});

check('le email per gli ordini restano nel loro ambiente e si cambiano anche in produzione', fn () =>
    in_array('merchant_notification_emails', Setting::syncSchema()->excludeColumns, true)
    && SettingResource::editableWhenReadonly() === ['merchant_notification_emails']
);

check('ogni campo email dice quali email riceve', function () {
    $etichette = SettingResource::labelSchema();

    return stripos((string) ($etichette['merchant_notification_emails'] ?? ''), 'ordini') !== false
        && stripos((string) ($etichette['developer_error_emails'] ?? ''), 'errori') !== false;
});

check('le email per gli ordini e per gli errori si salvano pulite', function () {
    $valori = SettingResource::mutateRequestValues([
        'merchant_notification_emails' => 'a@x.it; A@x.it  b@y.it',
        'developer_error_emails' => ' dev@x.it ',
    ], 'update');

    return $valori['merchant_notification_emails'] === 'a@x.it, b@y.it'
        && $valori['developer_error_emails'] === 'dev@x.it';
});

check('un indirizzo per gli ordini scritto male si rifiuta, nominandolo', function () {
    try {
        SettingResource::mutateRequestValues(['merchant_notification_emails' => 'a@x.it, anna.x.it'], 'update');
    } catch (InvalidArgumentException $errore) {
        return str_contains($errore->getMessage(), 'anna.x.it');
    }

    return false;
});

check('le tecniche sono solo di admin; quelle del commerciante anche di admin, che vede tutto', function () {
    $tecniche = SettingResource::permissionSchema()->toArray();
    $commerciante = MerchantSettingResource::permissionSchema()->toArray();

    foreach ($tecniche['backend'] ?? [] as $authorities) {
        if ($authorities !== [] && $authorities !== ['admin']) {
            return false;
        }
    }

    foreach ($commerciante['backend'] ?? [] as $authorities) {
        if ($authorities !== [] && $authorities !== ['admin', 'administrator']) {
            return false;
        }
    }

    return (MerchantSettingResource::navigationSchema()->toArray()['authority'] ?? []) === ['admin', 'administrator'];
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

$forza = static function (?array $stato): void {
    (new ReflectionProperty(Gestionale::class, 'features'))->setValue(null, $stato);
};

check('il commerciante ha la colonna dei destinatari degli avvisi', fn () =>
    in_array('low_stock_emails', $colonne(MerchantSetting::class), true)
);

check('con gli avvisi bloccati i destinatari non si chiedono e non si scrivono', function () use ($forza) {
    $forza(['low_stock_alerts' => false]);
    $campi = array_map(static fn ($field): string => (string) $field->name, MerchantSettingResource::formSchema());
    $valori = MerchantSettingResource::mutateRequestValues(['low_stock_emails' => 'a@x.it'], 'update');

    return !in_array('low_stock_emails', $campi, true) && !array_key_exists('low_stock_emails', $valori);
});

check('con gli avvisi sbloccati i destinatari si salvano puliti', function () use ($forza) {
    $forza(['low_stock_alerts' => true]);
    $campi = array_map(static fn ($field): string => (string) $field->name, MerchantSettingResource::formSchema());
    $valori = MerchantSettingResource::mutateRequestValues(['low_stock_emails' => 'a@x.it; A@x.it  b@y.it'], 'update');

    return in_array('low_stock_emails', $campi, true)
        && $valori['low_stock_emails'] === 'a@x.it, b@y.it';
});

check('un destinatario scritto male si rifiuta, nominandolo', function () use ($forza) {
    $forza(['low_stock_alerts' => true]);

    try {
        MerchantSettingResource::mutateRequestValues(['low_stock_emails' => 'a@x.it, anna.x.it'], 'update');
    } catch (InvalidArgumentException $errore) {
        return str_contains($errore->getMessage(), 'anna.x.it');
    }

    return false;
});

check('un campo vuoto si salva vuoto: vuol dire nessuna email', function () use ($forza) {
    $forza(['low_stock_alerts' => true]);

    return MerchantSettingResource::mutateRequestValues(['low_stock_emails' => ' '], 'update')['low_stock_emails'] === '';
});

check('le due impostazioni della vendita ci sono, con i predefiniti di 5.2', function () {
    $colonne = [];

    foreach (Setting::tableSchema() as $column) {
        $colonne[(string) $column->name] = $column;
    }

    return isset($colonne['order_reservation_minutes'], $colonne['order_payment_wait_days'])
        && (string) $colonne['order_reservation_minutes']->getSchema('default') === '30'
        && (string) $colonne['order_payment_wait_days']->getSchema('default') === '7';
});

check('le due impostazioni si possono cambiare dal form', function () {
    $campi = array_map(static fn ($field): string => (string) $field->name, SettingResource::formSchema());

    return in_array('order_reservation_minutes', $campi, true)
        && in_array('order_payment_wait_days', $campi, true);
});

check('le due impostazioni hanno un\'etichetta in italiano', function () {
    $etichette = SettingResource::labelSchema();

    return trim((string) ($etichette['order_reservation_minutes'] ?? '')) !== ''
        && trim((string) ($etichette['order_payment_wait_days'] ?? '')) !== '';
});

check('font e ordini senza account non stanno più nelle impostazioni del commerciante', function () use ($colonne, $forza) {
    $forza(['online_sales' => true, 'low_stock_alerts' => true]);
    $campi = array_map(static fn ($field): string => (string) $field->name, MerchantSettingResource::formSchema());
    $via = ['font_auth', 'font_account', 'font_cart', 'font_checkout', 'checkout_guest'];

    return array_intersect($via, $colonne(MerchantSetting::class)) === []
        && array_intersect($via, $campi) === []
        && array_diff_key(array_flip($via), MerchantSettingResource::labelSchema()) === array_flip($via);
});

check('la pagina del commerciante si vede nel menu solo con gli avvisi di scorta', function () use ($forza) {
    $forza(['low_stock_alerts' => false]);
    $spenta = MerchantSettingResource::navigationSchema()->toArray()['enabled'] ?? null;
    $forza(['low_stock_alerts' => true]);
    $accesa = MerchantSettingResource::navigationSchema()->toArray()['enabled'] ?? null;

    return $spenta === false && $accesa === true;
});

$forza(null);

summary();
