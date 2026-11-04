<?php

namespace Wonder\Plugin\Gestionale\Models\Catalog;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Columns;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Sql\TableSchema as Column;

/**
 * Le personalizzazioni di un articolo, anche di tipo abbonamento, nell'ordine
 * in cui il cliente le vede ({@see assertTarget()}).
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
            'ind_customization' => ['index' => 'customization_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('product_model_id')->number()->decimals(0),
            Field::key('customization_id')->number()->decimals(0),
            Field::key('is_required')->text()->sanitize(false),
            Field::key('surcharge')->number()->decimals(2),
            Field::key('position')->number()->decimals(0),
        ];
    }

    /**
     * Un legame va sempre a un articolo.
     *
     * @param array<string, mixed> $values
     * @throws UserError `customization.link_target`
     */
    public static function assertTarget(array $values): void
    {
        if ((int) ($values['product_model_id'] ?? 0) <= 0) {
            throw UserError::make('customization.link_target');
        }
    }
}
