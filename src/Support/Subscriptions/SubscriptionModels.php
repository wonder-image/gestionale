<?php

namespace Wonder\Plugin\Gestionale\Support\Subscriptions;

use Throwable;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;

/**
 * I prodotti di tipo abbonamento (G10): prima erano i «piani». I prezzi
 * (`PlanPrice`), i limiti (`PlanFeature`) e i legami (`PlanLink`) puntano al
 * modello con `product_model_id`.
 *
 * Un abbonamento è vendibile se è visibile e non cancellato; sul sito anche
 * con `visible_online`.
 */
final class SubscriptionModels
{
    public const TYPE = 'subscription';

    /** Il modello abbonamento di un id, o null. @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        try {
            $model = ProductModel::findById($id);
        } catch (Throwable) {
            return null;
        }

        return is_array($model) && isset($model['id']) && (string) ($model['type'] ?? '') === self::TYPE ? $model : null;
    }

    /** Il modello abbonamento di un prezzo, o null. @param array<string, mixed>|null $price @return array<string, mixed>|null */
    public static function ofPrice(?array $price): ?array
    {
        return is_array($price) ? self::find((int) ($price['product_model_id'] ?? 0)) : null;
    }

    /** Si vende ancora (in ufficio)? @param array<string, mixed>|null $model */
    public static function isLive(?array $model): bool
    {
        return is_array($model)
            && (string) ($model['type'] ?? '') === self::TYPE
            && (string) ($model['visible'] ?? 'false') === 'true'
            && (string) ($model['deleted'] ?? 'false') === 'false';
    }

    /** Si vende sul sito? @param array<string, mixed>|null $model */
    public static function isOnline(?array $model): bool
    {
        return self::isLive($model) && (string) ($model['visible_online'] ?? 'false') === 'true';
    }

    /**
     * Gli abbonamenti vendibili, nell'ordine del catalogo.
     *
     * @return list<array<string, mixed>>
     */
    public static function live(): array
    {
        try {
            $found = ProductModel::find(['type' => self::TYPE, 'visible' => 'true', 'deleted' => 'false'], null, 'position', 'ASC');
        } catch (Throwable) {
            return [];
        }

        if (!is_array($found) || $found === []) {
            return [];
        }

        return isset($found['id']) ? [$found] : array_values(array_filter($found, 'is_array'));
    }
}
