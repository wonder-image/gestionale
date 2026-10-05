<?php

namespace Wonder\Plugin\Gestionale\Support\Promotions;

/**
 * «A chi si applica»: decide se un prodotto rientra in una selezione di
 * catalogo (§2 della spec di G6). La stessa selezione serve alle campagne e
 * ai coupon.
 *
 * I criteri scelti sono in **unione** — basta che il prodotto ne soddisfi uno —
 * e gli articoli esclusi vincono su tutto, anche su «tutto il catalogo». Una
 * selezione vuota e senza «tutto» non prende nessuno: è la scelta più sicura
 * per uno sconto, che non deve scattare per una dimenticanza.
 *
 * Classe pura: niente database. Le categorie dei fatti arrivano già con gli
 * antenati, così scegliere «Abbigliamento» prende anche le sue sottocategorie.
 */
final class ScopeMatcher
{
    /**
     * @param array{all?: bool, categories?: list<int>, tags?: list<int>, brands?: list<int>, models?: list<int>, excluded_models?: list<int>} $scope
     * @param array{categories?: list<int>, tags?: list<int>, brand_id?: int, model_id?: int} $facts
     */
    public static function matches(array $scope, array $facts): bool
    {
        $modelId = (int) ($facts['model_id'] ?? 0);

        if ($modelId > 0 && in_array($modelId, static::ids($scope['excluded_models'] ?? []), true)) {
            return false;
        }

        if (!empty($scope['all'])) {
            return true;
        }

        if (static::intersects($scope['categories'] ?? [], $facts['categories'] ?? [])) {
            return true;
        }

        if (static::intersects($scope['tags'] ?? [], $facts['tags'] ?? [])) {
            return true;
        }

        $brandId = (int) ($facts['brand_id'] ?? 0);

        if ($brandId > 0 && in_array($brandId, static::ids($scope['brands'] ?? []), true)) {
            return true;
        }

        return $modelId > 0 && in_array($modelId, static::ids($scope['models'] ?? []), true);
    }

    /**
     * @param array<int, mixed> $chosen
     * @param array<int, mixed> $present
     */
    private static function intersects(array $chosen, array $present): bool
    {
        return array_intersect(static::ids($chosen), static::ids($present)) !== [];
    }

    /**
     * @param array<int, mixed> $values
     * @return list<int>
     */
    private static function ids(array $values): array
    {
        return array_values(array_map('intval', $values));
    }
}
