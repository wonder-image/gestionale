<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

use Throwable;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductImage;

/**
 * La foto da mostrare accanto a un articolo, in elenco o in una scheda.
 *
 * Applica `ProductImages::for()` — la regola dell'ereditarietà, che sta in un
 * posto solo — e restituisce l'indirizzo della prima foto pronta. `pick()` è
 * pura e si prova senza database; `forModel()` e `forProduct()` leggono le
 * immagini una volta sola per articolo, perché una tabella chiede la foto riga
 * per riga.
 */
final class ProductPhotos
{
    /** @var array<int, list<array<string, mixed>>> le foto pronte, per articolo */
    private static array $ready = [];

    /**
     * @param list<array<string, mixed>> $images tutte le immagini dell'articolo
     */
    public static function pick(array $images, int $variantId, int $productId): string
    {
        $ready = array_values(array_filter($images, [ProductImages::class, 'isReady']));

        foreach (ProductImages::for($ready, $variantId, $productId) as $image) {
            if (($url = ProductImages::url($image)) !== '') {
                return $url;
            }
        }

        return '';
    }

    /** La foto di una versione, dall'articolo, dal colore e dall'opzione. */
    public static function forModel(int $modelId, int $variantId, int $productId): string
    {
        if ($modelId <= 0) {
            return '';
        }

        self::$ready[$modelId] ??= self::load($modelId);

        return self::pick(self::$ready[$modelId], $variantId, $productId);
    }

    /** La foto di una versione, dal suo id; vuota se la versione non esiste più. */
    public static function forProduct(int $productId): string
    {
        if ($productId <= 0) {
            return '';
        }

        try {
            $product = Product::findById($productId);
        } catch (Throwable) {
            return '';
        }

        if (!is_array($product) || $product === []) {
            return '';
        }

        return self::forModel(
            (int) ($product['product_model_id'] ?? 0),
            (int) ($product['product_variant_id'] ?? 0),
            $productId
        );
    }

    /** @return list<array<string, mixed>> */
    private static function load(int $modelId): array
    {
        try {
            $rows = ProductImage::find(['deleted' => 'false', 'product_model_id' => $modelId], null, 'position', 'ASC');
        } catch (Throwable) {
            return [];
        }

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }
}
