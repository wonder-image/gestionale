<?php
/** php tests/FeatureModelsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\System\Feature;
use Wonder\Plugin\Gestionale\Models\System\FeatureLog;

check('tabelle con il prefisso gst_', fn () =>
    Feature::$table === 'gst_features' && FeatureLog::$table === 'gst_feature_logs'
);

check('colonne dello stato, con la chiave unica', function () {
    $columns = Feature::getColumns();

    return array_diff(['feature_key', 'enabled', 'changed_at', 'changed_by'], array_keys($columns)) === []
        && ($columns['feature_key']['unique'] ?? false) == true
        && ($columns['changed_by']['type'] ?? '') === 'INT';
});

check('colonne dello storico, legate alla funzionalità', function () {
    $columns = FeatureLog::getColumns();

    return array_diff(['feature_id', 'from_value', 'to_value', 'source', 'user_id', 'note'], array_keys($columns)) === []
        && ($columns['feature_id']['foreign_table'] ?? null) === 'gst_features';
});

check('sincronizzate con id stabili e modificabili solo in locale', function () {
    $feature = Feature::syncSchema();
    $log = FeatureLog::syncSchema();

    return $feature !== null && $feature->keepIds && $feature->localOnly
        && $log !== null && $log->keepIds && $log->localOnly;
});

check('la chiave della funzionalità non si modifica dopo la creazione', function () {
    $field = Feature::dataFields()['feature_key'] ?? null;

    return $field !== null
        && ($field->getSchema('immutable_on_update') ?? false) === true
        && ($field->getSchema('readonly_on_update') ?? false) === true;
});

summary();
