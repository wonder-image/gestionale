<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

use Wonder\Plugin\Gestionale\Gestionale;

/**
 * La funzionalità «Misure»: unità, peso e dimensioni dell'articolo.
 *
 * Spenta, la scheda non li chiede e chi li legge li vede vuoti, anche con le
 * spedizioni accese: non tutte le tariffe guardano il peso. I valori scritti
 * prima restano nel database e tornano alla riaccensione.
 */
final class Measures
{
    public const FEATURE = 'measures';

    /** Le colonne che contano come peso e misure. */
    public const KEYS = ['weight', 'length', 'width', 'height', 'circumference'];

    /** L'unità di un articolo nato con la funzionalità spenta. */
    public const DEFAULT_UNIT = 'pz';

    public static function active(): bool
    {
        return Gestionale::feature(self::FEATURE);
    }

    /**
     * Peso e misure di una riga, in numeri; tutti zero a funzionalità spenta.
     *
     * @param array<string, mixed>|null $row
     * @return array{weight: float, length: float, width: float, height: float}
     */
    public static function of(?array $row): array
    {
        $on = $row !== null && self::active();
        $value = static fn (string $key): float => $on ? (float) ($row[$key] ?? 0) : 0.0;

        return ['weight' => $value('weight'), 'length' => $value('length'), 'width' => $value('width'), 'height' => $value('height')];
    }
}
