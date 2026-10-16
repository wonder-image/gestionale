<?php

namespace Wonder\Plugin\Gestionale\Models\Catalog;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Plan;
use Wonder\Plugin\Gestionale\Support\Columns;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Sql\TableSchema as Column;

/**
 * Le personalizzazioni di un articolo **o** di un piano d'abbonamento,
 * nell'ordine in cui il cliente le vede: ogni riga ha uno solo fra
 * `product_model_id` e `plan_id` ({@see assertTarget()}).
 *
 * `is_required` obbliga a compilarla per comprare. `surcharge`, se c'è,
 * sostituisce qui il sovrapprezzo della personalizzazione (anche con zero);
 * vuoto, vale quello della personalizzazione. Una riga tolta dal form si
 * cancella davvero.
 */
final class CustomizationLink extends Model
{
    public static string $table = 'gst_customization_links';
    public static string $folder = 'gestionale/models';
    public static string $icon = 'bi bi-pencil-square';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('product_model_id')->int()->foreign(ProductModel::$table),
            Column::key('plan_id')->int()->foreign(Plan::$table),
            Column::key('customization_id')->int()->null(false)->foreign(Customization::$table),
            Column::key('is_required')->enum(['true', 'false'])->default('false'),
            Columns::decimal('surcharge', '12,2'),
            Column::key('position')->int(),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_model' => ['index' => 'product_model_id'],
            'ind_plan' => ['index' => 'plan_id'],
            'ind_customization' => ['index' => 'customization_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('product_model_id')->number()->decimals(0),
            Field::key('plan_id')->number()->decimals(0),
            Field::key('customization_id')->number()->decimals(0),
            Field::key('is_required')->text()->sanitize(false),
            Field::key('surcharge')->number()->decimals(2),
            Field::key('position')->number()->decimals(0),
        ];
    }

    /**
     * Un legame va a un articolo oppure a un piano, mai a tutti e due né a
     * nessuno.
     *
     * @param array<string, mixed> $values
     * @throws UserError `customization.link_target`
     */
    public static function assertTarget(array $values): void
    {
        $model = (int) ($values['product_model_id'] ?? 0) > 0;
        $plan = (int) ($values['plan_id'] ?? 0) > 0;

        if ($model === $plan) {
            throw UserError::make('customization.link_target');
        }
    }
}
