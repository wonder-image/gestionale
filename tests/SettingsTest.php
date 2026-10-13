<?php
/** php tests/SettingsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\System\Setting;
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

check('non c\'è più una pagina di impostazioni del commerciante: tutto sta in Set Up', fn () =>
    !class_exists('Wonder\\Plugin\\Gestionale\\Resources\\System\\MerchantSettingResource')
    && !class_exists('Wonder\\Plugin\\Gestionale\\Models\\System\\MerchantSetting')
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
        && in_array('merchant_notification_emails', $campi, true);
});

check('le email per gli ordini e per gli avvisi restano nel loro ambiente e si cambiano anche in produzione', fn () =>
    array_diff(['merchant_notification_emails', 'low_stock_emails', 'batch_expiry_days'], Setting::syncSchema()->excludeColumns) === []
    && SettingResource::editableWhenReadonly() === ['merchant_notification_emails', 'low_stock_emails', 'batch_expiry_days']
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

check('le impostazioni sono solo di admin', function () {
    foreach (SettingResource::permissionSchema()->toArray()['backend'] ?? [] as $authorities) {
        if ($authorities !== [] && $authorities !== ['admin']) {
            return false;
        }
    }

    return true;
});

check('le impostazioni stanno in Set Up', fn () =>
    (SettingResource::navigationSchema()->toArray()['section_key'] ?? '') === 'set-up'
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

check('le impostazioni hanno la colonna dei destinatari degli avvisi', fn () =>
    in_array('low_stock_emails', $colonne(Setting::class), true)
    && in_array('low_stock_emails', array_map(static fn ($field): string => (string) $field->key, Setting::dataSchema()), true)
);

check('con gli avvisi bloccati i destinatari non si chiedono e non si scrivono', function () use ($forza) {
    $forza(['low_stock_alerts' => false]);
    $campi = array_map(static fn ($field): string => (string) $field->name, SettingResource::formSchema());
    $valori = SettingResource::mutateRequestValues(['low_stock_emails' => 'a@x.it'], 'update');

    return !in_array('low_stock_emails', $campi, true) && !array_key_exists('low_stock_emails', $valori);
});

check('con gli avvisi sbloccati i destinatari si salvano puliti', function () use ($forza) {
    $forza(['low_stock_alerts' => true]);
    $campi = array_map(static fn ($field): string => (string) $field->name, SettingResource::formSchema());
    $valori = SettingResource::mutateRequestValues(['low_stock_emails' => 'a@x.it; A@x.it  b@y.it'], 'update');

    return in_array('low_stock_emails', $campi, true)
        && $valori['low_stock_emails'] === 'a@x.it, b@y.it'
        && trim((string) (SettingResource::labelSchema()['low_stock_emails'] ?? '')) !== '';
});

check('un destinatario scritto male si rifiuta, nominandolo', function () use ($forza) {
    $forza(['low_stock_alerts' => true]);

    try {
        SettingResource::mutateRequestValues(['low_stock_emails' => 'a@x.it, anna.x.it'], 'update');
    } catch (InvalidArgumentException $errore) {
        return str_contains($errore->getMessage(), 'anna.x.it');
    }

    return false;
});

check('un campo vuoto si salva vuoto: vuol dire nessuna email', function () use ($forza) {
    $forza(['low_stock_alerts' => true]);

    return SettingResource::mutateRequestValues(['low_stock_emails' => ' '], 'update')['low_stock_emails'] === '';
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

$forza(null);

check('l\'interruttore delle spedizioni col deploy c\'è ed è acceso di base', function () {
    $colonne = [];

    foreach (Setting::tableSchema() as $column) {
        $colonne[(string) $column->name] = $column;
    }

    $dati = array_map(static fn ($field): string => (string) $field->key, Setting::dataSchema());

    return isset($colonne['shipping_sync'])
        && (string) $colonne['shipping_sync']->getSchema('default') === 'true'
        && in_array('shipping_sync', $dati, true);
});

check('l\'interruttore delle spedizioni si cambia dal form, con la sua etichetta', function () {
    $campi = array_map(static fn ($field): string => (string) $field->name, SettingResource::formSchema());

    return in_array('shipping_sync', $campi, true)
        && trim((string) (SettingResource::labelSchema()['shipping_sync'] ?? '')) !== '';
});

summary();
