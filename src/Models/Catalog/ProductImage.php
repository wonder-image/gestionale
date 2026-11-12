<?php

namespace Wonder\Plugin\Gestionale\Models\Catalog;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductImages;
use Wonder\Sql\TableSchema as Column;

/**
 * Le foto di un articolo.
 *
 * Senza variante l'immagine vale per tutto il modello; con la variante vale
 * solo per quella, e chi ne ha prende le sue invece di quelle del modello. La
 * regola sta in `Support\Catalog\ProductImages::for()`, pura, perché la devono
 * applicare allo stesso modo il pannello e la vetrina.
 *
 * **Le misure le genera la coda del core.** Il campo è un campo immagine come
 * gli altri: `uploadFiles()` scrive l'originale e accoda le misure responsive
 * (`Wonder\App\Media\ResizeQueue`, task `wonder.media-resize`), così venti
 * foto da telefono non tengono fermo il salvataggio per minuti (G2a.8). Dove
 * lo scheduler non gira il core ridimensiona durante il salvataggio. Finché
 * le misure mancano si mostra l'originale; un mp4 non ne ha.
 *
 * Lo stato si legge dalla coda, per percorso del file
 * (`ProductImages::state()` ed `error()`), non da questa tabella.
 *
 * @deprecated le colonne `status`, `attempts`, `processed_at`, `error`: erano
 *             della coda del modulo (`ImageQueue`), che non c'è più. Restano
 *             nella tabella e non si leggono più, con due eccezioni: `pending`
 *             segna le righe che `ProductImageQueueMigration` deve ancora
 *             consegnare alla coda del core, `failed` tiene nascoste al sito
 *             le foto che la vecchia coda non era riuscita a lavorare. Le
 *             righe nuove nascono `ready`.
 */
final class ProductImage extends Model
{
    public static string $table = 'gst_product_images';
    // La stessa cartella che usa la pagina dei modelli per rileggere i file.
    public static string $folder = ProductImages::DIR;
    public static string $icon = 'bi bi-image';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('product_model_id')->int()->null(false)->foreign(ProductModel::$table),
            Column::key('product_variant_id')->int()->foreign(ProductVariant::$table),
            // La foto di una sola opzione in vendita: «Blu / S» può avere la
            // sua, diversa da «Blu / M». Vuota vuol dire che la foto vale per
            // tutto il colore, e se il colore manca per tutto l'articolo.
            //
            // Nullable non per comodità: eliminando l'opzione il database
            // azzera questa colonna, e la foto torna a valere per il colore
            // invece di sparire con lei.
            Column::key('product_id')->int()->foreign(Product::$table),
            Column::key('file')->json(),
            Column::key('alt'),
            // Che foto è (Support\Catalog\ProductImages::TYPES): `packshot` il
            // prodotto da solo, `lifestyle` il prodotto in uso, con le persone o
            // l'ambiente. Oggi il pannello non lo chiede e ogni foto nasce
            // packshot: la colonna c'è per chi la vorrà scegliere.
            Column::key('type')->enum(['packshot', 'lifestyle'])->default('packshot'),
            Column::key('position')->int(),
            // Deprecate: erano della coda del modulo (vedi il commento della classe).
            Column::key('status')->enum(['pending', 'ready', 'failed'])->default('pending'),
            Column::key('attempts')->int(),
            Column::key('processed_at')->datetime(),
            Column::key('error')->type('TEXT'),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_model' => ['index' => 'product_model_id'],
            'ind_variant' => ['index' => 'product_variant_id'],
            'ind_product' => ['index' => 'product_id'],
            'ind_status' => ['index' => 'status'],
        ];
    }

    /**
     * Il campo della foto: un'immagine con le misure responsive del sito, che
     * `uploadFiles()` consegna alla coda del core, oppure un mp4, che resta
     * com'è.
     */
    private static function image(): \Wonder\Data\Fields\Image
    {
        return Field::key('file')
            ->image()
            ->extensions(['png', 'jpg', 'jpeg', 'webp', 'mp4'])
            ->maxSize(8)
            ->maxFile(1)
            ->name('{file_prefix}{rand}');
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('product_model_id')->number()->decimals(0),
            Field::key('product_variant_id')->number()->decimals(0),
            Field::key('product_id')->number()->decimals(0),
            self::image(),
            Field::key('alt')->text()->sanitize(),
            Field::key('type')->text()->sanitize(false),
            Field::key('position')->number()->decimals(0),
            Field::key('status')->text()->sanitize(false),
            Field::key('attempts')->number()->decimals(0),
            Field::key('processed_at')->text()->sanitize(false),
            Field::key('error')->text(),
        ];
    }
}
