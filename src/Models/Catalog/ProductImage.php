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
 * **Il campo non dichiara `responsive()`** (G2a.8): `uploadFiles()` del core
 * ridimensiona durante il salvataggio, e venti foto da telefono vogliono dire
 * minuti di attesa con il pannello che sembra bloccato. Qui si salva solo
 * l'originale, la riga nasce `pending` e le misure le genera la coda
 * (`Support\Catalog\ImageQueue`). Fino ad allora si mostra l'originale.
 *
 * `attempts` serve alla regola dei tre tentativi: una foto che non si lascia
 * ridimensionare tre volte resta `failed` e non riprova da sola.
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
            Column::key('position')->int(),
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
     * Il campo della foto, che **non** si fa ridimensionare al salvataggio.
     *
     * Un campo immagine, se non dice niente, prende da sé le misure responsive
     * del sito: `deferResize()` gli dice di scrivere solo l'originale e di
     * lasciare le misure alla coda. C'è dalla 2.2.15, che il modulo pretende.
     */
    private static function deferredImage(): \Wonder\Data\Fields\Image
    {
        return Field::key('file')
            ->image()
            ->extensions(['png', 'jpg', 'jpeg', 'webp', 'mp4'])
            ->maxSize(8)
            ->maxFile(1)
            ->name('{rand}')
            ->deferResize();
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('product_model_id')->number()->decimals(0),
            Field::key('product_variant_id')->number()->decimals(0),
            Field::key('product_id')->number()->decimals(0),
            self::deferredImage(),
            Field::key('alt')->text()->sanitizeFirst(),
            Field::key('position')->number()->decimals(0),
            Field::key('status')->text()->sanitize(false),
            Field::key('attempts')->number()->decimals(0),
            Field::key('processed_at')->text()->sanitize(false),
            Field::key('error')->text(),
        ];
    }
}
