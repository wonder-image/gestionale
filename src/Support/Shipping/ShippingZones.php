<?php

namespace Wonder\Plugin\Gestionale\Support\Shipping;

use Wonder\Plugin\Gestionale\Models\Shipping\ShippingZone;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingZoneArea;

/**
 * Da dove si parte per spedire: a quale zona appartiene una destinazione.
 */
final class ShippingZones
{
    /**
     * La zona più specifica per la destinazione: una zona con la provincia
     * batte una zona con il solo paese; a pari specificità vince la `position`
     * minore. `null` se nessuna zona la copre.
     */
    public static function resolve(string $country, string $province): ?int
    {
        $country = self::code($country, 2);
        $province = self::code($province, 10);

        if ($country === '') {
            return null;
        }

        $zones = self::zones();
        $best = null;

        foreach (self::areas(array_keys($zones)) as $area) {
            if (($area['country'] ?? '') !== $country) {
                continue;
            }

            $areaProvince = (string) ($area['province'] ?? '');
            if ($areaProvince !== '' && $areaProvince !== $province) {
                continue;
            }

            $zone = $zones[(int) $area['shipping_zone_id']];
            $rank = [$areaProvince !== '' ? 0 : 1, (int) ($zone['position'] ?? 0), (int) $zone['id']];

            if ($best === null || $rank < $best[0]) {
                $best = [$rank, (int) $zone['id']];
            }
        }

        return $best[1] ?? null;
    }

    /**
     * I nomi delle altre zone che hanno almeno un'area uguale a una di questa.
     *
     * @return list<string>
     */
    public static function overlaps(int $zoneId): array
    {
        $zones = self::zones();
        $mine = [];
        $others = [];

        foreach (self::areas(array_keys($zones)) as $area) {
            $key = ($area['country'] ?? '').'|'.($area['province'] ?? '');
            $id = (int) $area['shipping_zone_id'];

            if ($id === $zoneId) {
                $mine[$key] = true;
            } else {
                $others[$key][$id] = true;
            }
        }

        $names = [];
        foreach (array_keys($mine) as $key) {
            foreach (array_keys($others[$key] ?? []) as $id) {
                $names[$id] = (string) ($zones[$id]['name'] ?? '');
            }
        }

        return array_values($names);
    }

    private static function code(string $value, int $length): string
    {
        return substr(strtoupper(trim($value)), 0, $length);
    }

    /**
     * Le zone non eliminate, per id.
     *
     * @return array<int, array>
     */
    private static function zones(): array
    {
        $zones = [];
        foreach (self::rows(ShippingZone::find([])) as $row) {
            $zones[(int) $row['id']] = $row;
        }

        return $zones;
    }

    /**
     * Le aree delle zone indicate.
     *
     * @param list<int> $zoneIds
     * @return list<array>
     */
    private static function areas(array $zoneIds): array
    {
        if ($zoneIds === []) {
            return [];
        }

        return self::rows(ShippingZoneArea::find(['shipping_zone_id' => $zoneIds]));
    }

    /** @return list<array> */
    private static function rows(mixed $found): array
    {
        if (!is_array($found)) {
            return [];
        }

        return array_key_exists('id', $found) ? [$found] : array_values(array_filter($found, 'is_array'));
    }
}
