<?php

namespace Wonder\Plugin\Gestionale\Models\Subscriptions;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * Un limite del piano (G10.7): una chiave scelta dal sito (`max_progetti`) e
 * il suo valore (`5`, `illimitato`). Il gestionale li dichiara e li espone con
 * `Subscriptions::limit()`; il controllo lo fa il sito quando crea qualcosa.
 *
 * Le colonne si chiamano `feature_key` e `feature_value`, non `key` e `value`:
 * `KEY` è una parola riservata di MySQL (come in `Models\System\Feature`).
 */
final class PlanFeature extends Model
{
    public static string $table = 'gst_plan_features';
    public static string $folder = 'gestionale/subscriptions';
    public static string $icon = 'bi bi-sliders';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('plan_id')->int()->null(false)->foreign(Plan::$table),
            Column::key('feature_key')->length(100),
            Column::key('feature_value')->length(191),
            Column::key('position')->int(),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_plan' => ['index' => 'plan_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('plan_id')->number()->decimals(0),
            Field::key('feature_key')->text()->sanitize(false),
            Field::key('feature_value')->text()->sanitize(false),
            Field::key('position')->number()->decimals(0),
        ];
    }
}
