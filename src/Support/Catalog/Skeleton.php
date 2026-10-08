<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Sql\Transaction;

/**
 * Un modello nuovo nasce già con una variante e un prodotto.
 *
 * È la regola G2a.2 vista da dentro: la variante esiste sempre, così chi vende
 * un pezzo unico non incontra mai la parola "variante" e chi domani avrà tre
 * colori non deve rifare i dati. Il pannello nasconde ciò che non serve; qui si
 * creano le righe.
 *
 * Sta in una classe sua perché la usano la scheda del modello, il generatore
 * delle combinazioni e i dati di prova.
 */
final class Skeleton
{
    /**
     * Crea variante e prodotto di un modello appena nato.
     *
     * @return array{variant_id: int, product_id: int} zero se qualcosa non è andato
     */
    public static function forModel(int $modelId, string $modelName, string $modelSku = ''): array
    {
        $created = ['variant_id' => 0, 'product_id' => 0];

        if ($modelId <= 0) {
            return $created;
        }

        Transaction::run(static function () use ($modelId, $modelName, $modelSku, &$created): void {
            $variant = ProductVariant::create([
                'code' => Code::make(ProductVariant::class, Codes::VARIANT),
                'product_model_id' => $modelId,
                'name' => $modelName,
                'slug' => '',
                'position' => 1,
                'visible' => 'true',
            ]);

            $variantId = (int) ($variant->insert_id ?? 0);

            if ($variantId === 0) {
                return;
            }

            $product = Product::create([
                'code' => Code::make(Product::class, Codes::PRODUCT),
                'product_model_id' => $modelId,
                'product_variant_id' => $variantId,
                'sku' => $modelSku,
                'position' => 1,
                'active' => 'true',
            ]);

            $created = [
                'variant_id' => $variantId,
                'product_id' => (int) ($product->insert_id ?? 0),
            ];
        });

        return $created;
    }
}
