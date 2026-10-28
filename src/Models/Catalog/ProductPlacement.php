<?php

namespace Wonder\Plugin\Gestionale\Models\Catalog;

use Wonder\App\Model;
use Wonder\App\Support\MediaFileManager;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\ForgetsRequestMemo;
use Wonder\Sql\TableSchema as Column;

/**
 * Un articolo, o una sola sua variante, messo a mano in un elenco del sito,
 * nella sua posizione.
 *
 * L'elenco è la coppia `context_type` + `context_id`: una selezione, una
 * categoria o un tag. Per la selezione le righe sono il contenuto; per
 * categoria e tag sono gli articoli «in evidenza», che il sito mette in cima
 * prima degli altri. `product_variant_id` vuoto vale per tutto l'articolo; se
 * ci sono entrambe, nell'elenco per variante conta la riga della variante.
 *
 * `image` è la foto di contesto di quell'elenco (la ragazza che usa la crema,
 * non la confezione): il sito la usa al posto della foto del prodotto dove il
 * blocco lo prevede.
 *
 * Nessun indice unico: il repeater scrive prima e cancella dopo, come per
 * {@see ProductRelation}. I doppioni si tolgono prima di salvare
 * ({@see \Wonder\Plugin\Gestionale\Support\Catalog\Placements::cleanRows()}).
 */
final class ProductPlacement extends Model
{
    use ForgetsRequestMemo;

    public const MEMO_GROUP = 'placements';

    public static string $table = 'gst_product_placements';
    public static string $folder = 'gestionale/placements';
    public static string $icon = 'bi bi-pin-angle';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('context_type')->enum(['selection', 'category', 'tag'])->default('selection'),
            Column::key('context_id')->int()->null(false),
            Column::key('product_model_id')->int()->null(false)->foreign(ProductModel::$table),
            Column::key('product_variant_id')->int()->foreign(ProductVariant::$table),
            Column::key('image')->json(),
            Column::key('position')->int(),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_context' => ['index' => ['context_type', 'context_id']],
            'ind_model' => ['index' => 'product_model_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('context_type')->text()->sanitize(false),
            Field::key('context_id')->number()->decimals(0),
            Field::key('product_model_id')->number()->decimals(0),
            Field::key('product_variant_id')->number()->decimals(0),
            Field::key('image')
                ->image()
                ->extensions(['png', 'jpg', 'jpeg', 'webp'])
                ->maxSize(4)
                ->maxFile(1)
                ->dir('/catalogo/contesto/')
                ->name('{rand}'),
            Field::key('position')->number()->decimals(0),
        ];
    }

    /** L'indirizzo dell'immagine di una riga; vuoto se non ce l'ha. */
    public static function imageUrl(array $row): string
    {
        $field = static::dataFields()['image'] ?? null;
        $schema = is_object($field) && method_exists($field, 'getSchema') ? (array) $field->getSchema() : [];

        return static::storedFileUrls(MediaFileManager::decodeStoredFiles($row['image'] ?? ''), $schema)[0] ?? '';
    }
}
