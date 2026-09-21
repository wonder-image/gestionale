<?php

namespace Wonder\Plugin\Gestionale\Models\Catalog;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Sql\TableSchema as Column;

/**
 * La variante: quello che cambia l'aspetto di un modello. "Blu".
 *
 * **La variante c'è sempre**, anche per un articolo che non ne ha (G2a.2): un
 * modello nuovo nasce con una variante che porta il suo stesso nome, e finché
 * resta una sola la scheda non la nomina nemmeno. Così chi vende un pezzo unico
 * non incontra mai la parola "variante", e chi cresce non deve rifare i dati.
 *
 * Il nome resta scritto a mano — è quello che legge il cliente — ma il pannello
 * lo propone dai valori degli attributi di variante.
 */
final class ProductVariant extends Model
{
    public static string $table = 'gst_product_variants';
    public static string $folder = 'gestionale/models';
    public static string $icon = 'bi bi-palette';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            ...static::sqlColumnsFromDataSchema(['code']),
            Column::key('product_model_id')->int()->null(false)->foreign(ProductModel::$table),
            Column::key('name'),
            Column::key('slug')->length(150),
            Column::key('position')->int(),
            Column::key('visible')->enum(['true', 'false'])->default('true'),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_model' => ['index' => 'product_model_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->uniqueCode(Codes::VARIANT),
            Field::key('product_model_id')->number()->decimals(0),
            Field::key('name')->text()->sanitizeFirst(),
            Field::key('slug')->text()->slug()->readonlyOnUpdate()->immutableOnUpdate(),
            Field::key('position')->number()->decimals(0),
            Field::key('visible')->text()->sanitize(false),
        ];
    }
}
