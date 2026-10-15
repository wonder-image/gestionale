<?php

namespace Wonder\Plugin\Gestionale\Models\Subscriptions;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * «Si lega a» (G10b): il piano `plan_id` si compra solo insieme a un
 * abbonamento del piano `parent_plan_id` (l'hosting al dominio). Un livello
 * solo: una madre non ha madri ({@see \Wonder\Plugin\Gestionale\Support\Subscriptions\PlanLinks::assert()}).
 * Un legame tolto dal form si cancella davvero.
 */
final class PlanLink extends Model
{
    public static string $table = 'gst_plan_links';
    public static string $folder = 'gestionale/subscriptions';
    public static string $icon = 'bi bi-link-45deg';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('plan_id')->int()->null(false)->foreign(Plan::$table),
            Column::key('parent_plan_id')->int()->null(false)->foreign(Plan::$table),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_plan' => ['index' => 'plan_id'],
            'ind_parent' => ['index' => 'parent_plan_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('plan_id')->number()->decimals(0),
            Field::key('parent_plan_id')->number()->decimals(0),
        ];
    }
}
