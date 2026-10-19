<?php

namespace Wonder\Plugin\Gestionale\Models\Catalog;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Elements\Media\Image;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Sql\TableSchema as Column;

/**
 * Il prodotto: quello che si vende davvero e che sta a magazzino. "Blu / M".
 *
 * Punta sempre sia al modello sia alla variante, anche quando la variante è
 * una sola (G2a.2): senza variante un prodotto non esiste.
 *
 * `name` è l'etichetta della combinazione ("Blu / M"): la scrive il generatore
 * e la può correggere chi vende. Serve alla griglia della scheda, al selettore
 * della vetrina, alla riga dell'ordine e all'elenco delle giacenze, che
 * altrimenti dovrebbero ricomporla ogni volta dagli attributi.
 *
 * Peso e misure vuoti valgono quelli del modello: la regola sta nella scheda,
 * non qui, perché è una scelta di lettura e non un dato.
 *
 * `allow_backorder` e `backorder_lead_days` nascono ora ma restano invisibili
 * finché non arriva la vendita senza giacenza (G4): stanno sul prodotto, e
 * aggiungerle dopo vorrebbe dire rifare la scheda. La scorta minima invece
 * sta in `gst_stock_thresholds`, per sede.
 *
 * SKU ed EAN non hanno un indice unico: il framework scrive stringhe vuote e
 * non NULL, e due prodotti senza EAN si scontrerebbero. L'unicità la controlla
 * la Resource, che può spiegarla con una frase.
 */
final class Product extends Model
{
    public static string $table = 'gst_products';
    public static string $folder = 'gestionale/models';
    public static string $icon = 'bi bi-upc-scan';

    /** Le misure già pronte per la foto di un prodotto: miniatura, scheda, galleria, zoom. */
    public const IMAGE_THUMB = 240;
    public const IMAGE_CARD = 480;
    public const IMAGE_GALLERY = 960;
    public const IMAGE_ZOOM = 1920;

    /**
     * La foto di un prodotto, pronta da stampare ovunque: vetrina, carrello, checkout, account.
     *
     * È `__ri()` — srcset, webp e skeleton — con la misura da mostrare (una
     * delle costanti `IMAGE_*` o un numero) e sempre `fitContain()`: una foto
     * di prodotto non si taglia. Se la misura non è stata ancora generata il
     * motore ripiega sulla più vicina, o sul file originale; lo skeleton se ne
     * va quando l'immagine ha finito di caricare.
     *
     * `Product::image($url, Product::IMAGE_THUMB, $name)->displaySizes('72px')`
     */
    public static function image(string $src, int $size = self::IMAGE_CARD, string $alt = ''): Image
    {
        // Fuori dal sito (test, comandi) `__ri()` non c'è: stessa immagine, senza varianti.
        $image = function_exists('__ri') && defined('RESPONSIVE_IMAGE_SIZES')
            ? __ri($src)
            : Image::src($src)->skeleton()->notDraggable()->loading();

        $image->size($size)->fitContain();

        return $alt !== '' ? $image->alt($alt) : $image;
    }

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            ...static::sqlColumnsFromDataSchema([
                'code', 'price', 'sale_price',
                'weight', 'length', 'width', 'height', 'circumference',
            ]),
            Column::key('product_model_id')->int()->null(false)->foreign(ProductModel::$table),
            Column::key('product_variant_id')->int()->null(false)->foreign(ProductVariant::$table),
            Column::key('name'),
            Column::key('sku')->length(100),
            Column::key('ean')->length(13),
            Column::key('mpn')->length(100),
            Column::key('allow_backorder')->enum(['true', 'false'])->default('false'),
            Column::key('backorder_lead_days')->int(),
            Column::key('position')->int(),
            Column::key('active')->enum(['true', 'false'])->default('true'),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_model' => ['index' => 'product_model_id'],
            'ind_variant' => ['index' => 'product_variant_id'],
            'ind_sku' => ['index' => 'sku'],
            'ind_ean' => ['index' => 'ean'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->uniqueCode(Codes::PRODUCT),
            Field::key('product_model_id')->number()->decimals(0),
            Field::key('product_variant_id')->number()->decimals(0),
            // Niente `sanitizeFirst()`: "XL" deve restare "XL".
            Field::key('name')->text(),
            Field::key('sku')->text(),
            Field::key('ean')->text(),
            Field::key('mpn')->text(),
            Field::key('price')->number()->decimals(2),
            Field::key('sale_price')->number()->decimals(2),
            Field::key('allow_backorder')->text()->sanitize(false),
            Field::key('backorder_lead_days')->number()->decimals(0),
            Field::key('weight')->number()->decimals(3),
            Field::key('length')->number()->decimals(2),
            Field::key('width')->number()->decimals(2),
            Field::key('height')->number()->decimals(2),
            Field::key('circumference')->number()->decimals(2),
            Field::key('position')->number()->decimals(0),
            Field::key('active')->text()->sanitize(false),
        ];
    }
}
