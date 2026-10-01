<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Throwable;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;

/**
 * Come si chiama un prodotto fuori dalla sua scheda: l'articolo e l'opzione.
 *
 * L'email della scorta, la home e *Da controllare* lo scrivono tutti allo
 * stesso modo, "Maglia — Rossa, M", e un articolo senza varianti non ripete
 * il suo nome due volte.
 */
final class ProductNames
{
    /**
     * I nomi degli articoli di questi prodotti, per id del modello.
     *
     * @param array<int, array<string, mixed>> $products
     * @return array<int, string>
     */
    public static function models(array $products): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row['product_model_id'] ?? 0),
            $products
        ))));

        if ($ids === []) {
            return [];
        }

        try {
            $rows = ProductModel::find('id IN ('.implode(',', $ids).')');
        } catch (Throwable) {
            return [];
        }

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $names = [];

        foreach (isset($rows['id']) ? [$rows] : array_filter($rows, 'is_array') as $row) {
            $names[(int) $row['id']] = trim((string) ($row['name'] ?? ''));
        }

        return $names;
    }

    /**
     * @param array<string, mixed> $product
     * @param array<int, string> $modelNames
     * @return array{article: string, option: string}
     */
    public static function of(array $product, array $modelNames): array
    {
        $article = $modelNames[(int) ($product['product_model_id'] ?? 0)] ?? '';
        $article = $article !== '' ? $article : '—';
        $option = trim((string) ($product['name'] ?? ''));

        return [
            'article' => $article,
            // L'unica versione di un articolo senza varianti ha il suo nome.
            'option' => mb_strtolower($option, 'UTF-8') === mb_strtolower($article, 'UTF-8') ? '' : $option,
        ];
    }

    /**
     * Il nome per intero, quello che resta scritto su un ordine: "Maglia — Blu / M",
     * o solo "Maglia" per un articolo senza opzioni.
     *
     * @param array<string, mixed> $product
     * @param array<int, string> $modelNames
     */
    public static function full(array $product, array $modelNames): string
    {
        $names = self::of($product, $modelNames);
        $article = $names['article'] === '—' ? '' : $names['article'];

        if ($article === '') {
            return $names['option'];
        }

        return $names['option'] !== '' ? $article.' — '.$names['option'] : $article;
    }
}
