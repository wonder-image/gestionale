<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

/**
 * Quali varianti e quali prodotti mancano, dati i valori spuntati.
 *
 * Due colori e tre taglie sono due varianti e sei prodotti. Rifarlo con le
 * stesse spunte non deve creare niente: si guarda cosa c'è già e si dice solo
 * cosa manca. Classe pura: riceve i valori e l'esistente, non tocca il
 * database.
 *
 * Senza valori di variante i prodotti nascono sulla variante che il modello ha
 * già — è l'articolo che ha solo le taglie. Senza valori di prodotto nasce un
 * prodotto per variante.
 */
final class Combinations
{
    /**
     * @param list<array{id: int, label: string}> $variantValues
     * @param list<array{id: int, label: string}> $productValues
     * @param array{variants: array<int, int>, products: array<string, mixed>} $existing
     *        `variants`: id del valore => id della variante che lo usa già;
     *        `products`: chiave "valoreVariante-valoreProdotto" già presente.
     * @return array{
     *     variants: list<array{value_id: int, label: string}>,
     *     products: list<array{variant_value_id: int, product_value_id: int, labels: list<string>}>
     * }
     */
    public static function plan(array $variantValues, array $productValues, array $existing): array
    {
        $knownVariants = (array) ($existing['variants'] ?? []);
        $knownProducts = (array) ($existing['products'] ?? []);

        $variants = [];

        foreach ($variantValues as $value) {
            $id = (int) ($value['id'] ?? 0);

            if ($id === 0 || isset($knownVariants[$id])) {
                continue;
            }

            $variants[] = ['value_id' => $id, 'label' => (string) ($value['label'] ?? '')];
        }

        // Nessun colore spuntato: si lavora sulla variante che c'è già, che
        // qui vale zero perché il suo id lo conosce solo chi scrive le righe.
        $variantSide = $variantValues === []
            ? [['id' => 0, 'label' => '']]
            : $variantValues;
        $productSide = $productValues === []
            ? [['id' => 0, 'label' => '']]
            : $productValues;

        $products = [];

        foreach ($variantSide as $variant) {
            $variantId = (int) ($variant['id'] ?? 0);

            foreach ($productSide as $product) {
                $productId = (int) ($product['id'] ?? 0);
                $key = $variantId.'-'.$productId;

                if (isset($knownProducts[$key])) {
                    continue;
                }

                $labels = [];

                foreach ([(string) ($variant['label'] ?? ''), (string) ($product['label'] ?? '')] as $label) {
                    if ($label !== '') {
                        $labels[] = $label;
                    }
                }

                $products[] = [
                    'variant_value_id' => $variantId,
                    'product_value_id' => $productId,
                    'labels' => $labels,
                ];
            }
        }

        // Niente spuntato di qua e di là: non c'è niente da generare.
        if ($variantValues === [] && $productValues === []) {
            return ['variants' => [], 'products' => []];
        }

        return ['variants' => $variants, 'products' => $products];
    }
}
