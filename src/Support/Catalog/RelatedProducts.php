<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductRelation;
use RuntimeException;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

/**
 * Gli articoli collegati a mano a un altro (#74, #75): i suggeriti da
 * abbinare e gli alternativi da proporre al suo posto. Nessuna regola li
 * calcola: sono solo le scelte di chi vende, nel suo ordine.
 *
 * {@see forModel()} è la lettura per il sito: solo gli articoli che il sito
 * mostra (visibili, online, con almeno una versione attiva), gli stessi
 * criteri di `ProductListing::byModelIds()` dell'ecommerce. {@see ids()} è la
 * lettura grezza per backend e MCP, dove chi vende vuole vedere quello che ha
 * scelto anche se oggi un articolo è nascosto. {@see cleanRows()} è pura.
 */
final class RelatedProducts
{
    public const SUGGESTED = 'suggested';
    public const ALTERNATIVE = 'alternative';
    public const TYPES = [self::SUGGESTED, self::ALTERNATIVE];

    /**
     * Le righe di un elenco senza quelle vuote, l'articolo stesso e i
     * doppioni (vale la prima): l'ordine resta quello dato.
     *
     * @param array<int|string, mixed> $rows
     * @return list<array<string, mixed>>
     */
    public static function cleanRows(array $rows, int $modelId, string $key = 'related_model_id'): array
    {
        $visti = [];
        $out = [];

        foreach ($rows as $row) {
            $id = is_array($row) ? (int) trim((string) ($row[$key] ?? '')) : 0;

            if ($id <= 0 || $id === $modelId || isset($visti[$id])) {
                continue;
            }

            $visti[$id] = true;
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Gli articoli collegati di un tipo, nell'ordine scelto, così come sono.
     *
     * @return list<int>
     */
    public static function ids(int $modelId, string $type): array
    {
        if ($modelId <= 0 || !in_array($type, self::TYPES, true)) {
            return [];
        }

        $rows = ProductRelation::find(
            ['product_model_id' => $modelId, 'type' => $type, 'deleted' => 'false'],
            null,
            'position',
            'ASC'
        );

        $ids = [];

        foreach (self::rows($rows) as $row) {
            $ids[] = (int) $row['related_model_id'];
        }

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0 && $id !== $modelId)));
    }

    /**
     * Suggeriti e alternativi che il sito può mostrare, nell'ordine scelto.
     *
     * @return array{suggested: list<int>, alternative: list<int>}
     */
    public static function forModel(int $modelId): array
    {
        $out = [self::SUGGESTED => [], self::ALTERNATIVE => []];

        foreach (self::TYPES as $type) {
            $out[$type] = array_values(array_filter(self::ids($modelId, $type), self::onSite(...)));
        }

        return $out;
    }

    /**
     * I suggeriti di più articoli insieme (il carrello), senza doppioni e
     * senza gli articoli di partenza: prima tutti quelli del primo, poi del
     * secondo, e così via.
     *
     * @param list<int> $modelIds
     * @return list<int>
     */
    public static function suggestedFor(array $modelIds): array
    {
        $partenza = array_fill_keys(array_map('intval', $modelIds), true);
        $out = [];

        foreach (array_keys($partenza) as $modelId) {
            foreach (self::forModel($modelId)[self::SUGGESTED] as $id) {
                if (!isset($partenza[$id]) && !in_array($id, $out, true)) {
                    $out[] = $id;
                }
            }
        }

        return $out;
    }

    /**
     * Sostituisce un elenco con gli articoli dati, in quell'ordine. Chiamare
     * dentro una transazione.
     *
     * @param list<int> $relatedIds
     * @throws UserError `catalog.related_self`, `catalog.related_not_found`
     */
    public static function replace(int $modelId, string $type, array $relatedIds): void
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new RuntimeException('Tipo di collegamento sconosciuto: '.$type);
        }

        $ids = [];

        foreach ($relatedIds as $id) {
            $id = (int) $id;

            if ($id === $modelId) {
                throw UserError::make('catalog.related_self');
            }

            $model = ProductModel::find(['id' => $id, 'deleted' => 'false'], 1);

            if (!is_array($model) || !isset($model['id'])) {
                throw UserError::make('catalog.related_not_found', ['id' => (string) $id]);
            }

            if (!in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        foreach (self::rows(ProductRelation::find(['product_model_id' => $modelId, 'type' => $type])) as $row) {
            ProductRelation::delete((int) $row['id']);
        }

        foreach ($ids as $position => $id) {
            $result = ProductRelation::create([
                'product_model_id' => $modelId,
                'related_model_id' => $id,
                'type' => $type,
                'position' => $position,
            ]);

            if (empty($result->success)) {
                throw new RuntimeException('Collegamento '.$modelId.' → '.$id.' non salvato.');
            }
        }
    }

    /** Un articolo che il sito mostra: visibile, online e con una versione attiva. */
    private static function onSite(int $modelId): bool
    {
        $model = ProductModel::find([
            'id' => $modelId,
            'visible' => 'true',
            'visible_online' => 'true',
            'deleted' => 'false',
        ], 1);

        if (!is_array($model) || !isset($model['id'])) {
            return false;
        }

        $product = Product::find(['product_model_id' => $modelId, 'active' => 'true', 'deleted' => 'false'], 1);

        return is_array($product) && isset($product['id']);
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $rows): array
    {
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }
}
