<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Support\Catalog\Bundles;

/**
 * Il badge della disponibilità negli elenchi e nella scheda.
 *
 * Rosso a zero o sotto, giallo sotto la scorta minima, verde altrimenti.
 * «Sotto la scorta minima» è la stessa regola degli avvisi (`LowStock`),
 * sede per sede, e vale solo con la funzionalità degli avvisi accesa: senza,
 * le soglie non si leggono e il giallo non compare.
 *
 * Le regole (`tone`, `isLow`, `total`, `html`) sono pure; le letture stanno
 * in `forProducts()` e `forBundle()`.
 */
final class StockBadge
{
    /** Un multiprodotto che nessun componente limita. */
    public const UNLIMITED = Bundles::UNLIMITED;

    public static function tone(float $available, bool $low): string
    {
        if (round($available, 3) <= 0.0) {
            return 'danger';
        }

        return $low ? 'warning' : 'success';
    }

    /**
     * Almeno una sede è arrivata alla sua soglia? Una sede con la soglia e
     * senza righe di giacenza ha zero pezzi.
     *
     * @param array<int, array{available: float}> $byLocation per sede
     * @param array<int, float> $thresholds per sede
     */
    public static function isLow(array $byLocation, array $thresholds): bool
    {
        foreach ($thresholds as $locationId => $threshold) {
            $available = (float) ($byLocation[$locationId]['available'] ?? 0.0);

            if (LowStock::decide((float) $threshold, $available, false) === LowStock::OPEN) {
                return true;
            }
        }

        return false;
    }

    /**
     * Più opzioni in un numero solo: si sommano, ed è basso se una lo è.
     *
     * @param array<int, array{available: float, low: bool}> $perProduct
     * @return array{available: float, low: bool, unlimited: bool}
     */
    public static function total(array $perProduct): array
    {
        $available = 0.0;
        $low = false;

        foreach ($perProduct as $level) {
            $available += (float) $level['available'];
            $low = $low || $level['low'];
        }

        return ['available' => round($available, 3), 'low' => $low, 'unlimited' => false];
    }

    public static function html(float $available, bool $low, bool $unlimited = false): string
    {
        $tone = $unlimited ? self::tone(1.0, $low) : self::tone($available, $low);
        $text = $unlimited ? '∞' : self::number($available);
        $tooltip = $tone === 'warning'
            ? ' data-bs-toggle="tooltip" data-bs-title="Sotto la scorta minima"'
            : '';

        return '<span class="badge text-bg-'.$tone.'"'.$tooltip.'>'.$text.'</span>';
    }

    /**
     * Il disponibile di ogni opzione e se è sotto la scorta minima.
     *
     * @param list<int> $productIds
     * @return array<int, array{available: float, low: bool}>
     */
    public static function forProducts(array $productIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds))));

        if ($ids === []) {
            return [];
        }

        $levels = Levels::forProducts($ids);
        $alerts = Gestionale::feature('low_stock_alerts');
        $byLocation = $alerts ? Levels::byLocationForProducts($ids) : [];
        $thresholds = $alerts ? Thresholds::forProducts($ids) : [];
        $result = [];

        foreach ($ids as $id) {
            $result[$id] = [
                'available' => round((float) ($levels[$id]['available'] ?? 0.0), 3),
                'low' => self::isLow($byLocation[$id] ?? [], $thresholds[$id] ?? []),
            ];
        }

        return $result;
    }

    /**
     * Le confezioni componibili di un multiprodotto, e se un suo pezzo è
     * sotto la scorta minima. I pezzi sono quelli che `Bundles` conta: i
     * componenti fissi se il modo non è «choice», i gruppi se non è «fixed».
     *
     * @return array{available: float, low: bool, unlimited: bool}
     */
    public static function forBundle(int $modelId): array
    {
        $available = Bundles::available($modelId);
        $composition = Bundles::forModel($modelId);
        $mode = (string) ($composition['mode'] ?? '');
        $components = $mode === 'choice' ? [] : ($composition['components'] ?? []);
        $groups = $mode === 'fixed' ? [] : ($composition['groups'] ?? []);
        $ids = array_map(static fn (array $component): int => (int) $component['product_id'], $components);

        foreach ($groups as $group) {
            foreach ($group['options'] ?? [] as $option) {
                $ids[] = (int) $option['product_id'];
            }
        }

        $unlimited = $available >= self::UNLIMITED;

        return [
            'available' => $unlimited ? self::UNLIMITED : round($available, 3),
            'low' => self::total(self::forProducts($ids))['low'],
            'unlimited' => $unlimited,
        ];
    }

    /** 12, 2,5, -1: niente zeri inutili, la virgola. */
    private static function number(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');

        return str_replace('.', ',', $text === '-0' ? '0' : $text);
    }
}
