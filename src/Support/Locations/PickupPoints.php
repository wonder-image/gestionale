<?php

namespace Wonder\Plugin\Gestionale\Support\Locations;

use Wonder\App\Support\SocietyLocations;
use Wonder\Plugin\Gestionale\Models\Locations\Location;

/**
 * Le sedi dove il cliente può ritirare.
 *
 * Una regola sola per il checkout e per le spedizioni: la sede è attiva, è un
 * punto di ritiro, non è eliminata e la sede della società dietro c'è ancora.
 * L'orario qui non conta: chi ordina la sera ritira domani. Conta quando la
 * merce è pronta, in `Shipments::createPickup`.
 */
final class PickupPoints
{
    /**
     * La sede, se ci si può ritirare.
     *
     * @return array{location: array<string, mixed>, place: object}|null
     */
    public static function find(int $locationId): ?array
    {
        $location = $locationId > 0 ? Location::findById($locationId) : null;

        if (!is_array($location) || !isset($location['id']) || !self::usable($location)) {
            return null;
        }

        $place = SocietyLocations::find((int) $location['society_location_id']);

        return is_object($place) ? ['location' => $location, 'place' => $place] : null;
    }

    /**
     * Le sedi dove si può ritirare, in ordine di id.
     *
     * @return list<array{id: int, name: string, address: string}>
     */
    public static function all(): array
    {
        $points = [];

        foreach (self::rows(Location::find(['active' => 'true', 'is_pickup_point' => 'true'])) as $location) {
            if (!self::usable($location)) {
                continue;
            }

            $place = SocietyLocations::find((int) $location['society_location_id']);

            if (!is_object($place)) {
                continue;
            }

            $points[] = [
                'id' => (int) $location['id'],
                'name' => trim((string) ($place->label ?? '')),
                'address' => self::address($place),
            ];
        }

        usort($points, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return $points;
    }

    /** @param array<string, mixed> $location */
    private static function usable(array $location): bool
    {
        return (string) ($location['deleted'] ?? 'false') !== 'true'
            && (string) ($location['active'] ?? 'false') === 'true'
            && (string) ($location['is_pickup_point'] ?? 'false') === 'true';
    }

    /** «Via numero, cap città», quel che c'è. */
    private static function address(object $place): string
    {
        $parts = [
            trim(trim((string) ($place->street ?? '')).' '.trim((string) ($place->number ?? ''))),
            trim(trim((string) ($place->cap ?? '')).' '.trim((string) ($place->city ?? ''))),
        ];

        return implode(', ', array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $found): array
    {
        if (!is_array($found) || $found === []) {
            return [];
        }

        return array_key_exists('id', $found) ? [$found] : array_values(array_filter($found, 'is_array'));
    }
}
