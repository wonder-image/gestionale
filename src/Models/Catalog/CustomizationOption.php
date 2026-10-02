<?php

namespace Wonder\Plugin\Gestionale\Models\Catalog;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Columns;
use Wonder\Sql\TableSchema as Column;

/**
 * Un'opzione di una personalizzazione a scelta: «Rossa», «Blu».
 *
 * Il sovrapprezzo si somma a quello della personalizzazione. Una riga tolta
 * dal form si cancella davvero: la riga d'ordine tiene la sua copia.
 */
final class CustomizationOption extends Model
{
    public static string $table = 'gst_customization_options';
    public static string $folder = 'gestionale/customizations';
    public static string $icon = 'bi bi-list-ul';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('customization_id')->int()->null(false)->foreign(Customization::$table),
            Column::key('label'),
            Columns::decimal('surcharge', '12,2'),
            Column::key('position')->int(),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_customization' => ['index' => 'customization_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('customization_id')->number()->decimals(0),
            Field::key('label')->text()->sanitizeFirst(),
            Field::key('surcharge')->number()->decimals(2),
            Field::key('position')->number()->decimals(0),
        ];
    }
}
