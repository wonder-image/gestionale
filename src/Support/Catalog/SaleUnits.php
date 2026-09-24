<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

/**
 * Le unità con cui si vende un articolo, e quanti decimali ha la sua
 * giacenza.
 *
 * Non sono le unità degli attributi ({@see Units}): quelle misurano un valore,
 * queste contano la merce. I pezzi si contano interi, i chili no: «20.000» su
 * un articolo a pezzi si leggeva ventimila. È l'unico posto che lo sa: la
 * select dell'unità, le caselle della giacenza e lo script che le aggiorna
 * quando l'unità cambia leggono tutti da qui.
 */
final class SaleUnits
{
    /** I decimali per unità: quelle intere si contano, le altre si pesano. */
    private const DECIMALS = [
        'pz' => 0,
        'conf' => 0,
        'kg' => 3,
        'g' => 0,
        'l' => 3,
        'ml' => 0,
        'm' => 3,
    ];

    /** Quelle che un negozio usa davvero. @return array<string, string> */
    public static function all(): array
    {
        return [
            'pz' => 'Pezzi',
            'conf' => 'Confezioni',
            'kg' => 'Chilogrammi',
            'g' => 'Grammi',
            'l' => 'Litri',
            'ml' => 'Millilitri',
            'm' => 'Metri',
        ];
    }

    /**
     * Le cifre decimali della giacenza in quell'unità. Un'unità che non si
     * conosce ne tiene tre: mostrarne troppe non perde niente, troppo poche
     * sì.
     */
    public static function decimals(string $unit): int
    {
        return self::DECIMALS[self::normalize($unit)] ?? 3;
    }

    /** I decimali di tutte le unità, per lo script del browser. @return array<string, int> */
    public static function decimalsMap(): array
    {
        $map = [];

        foreach (array_keys(self::all()) as $unit) {
            $map[$unit] = self::decimals($unit);
        }

        return $map;
    }

    /** L'unità in coda al numero, staccata da uno spazio: «12 pz». */
    public static function suffix(string $unit): string
    {
        $unit = self::normalize($unit);

        return $unit === '' ? '' : ' '.$unit;
    }

    /**
     * I decimali da mostrare per queste giacenze.
     *
     * Quelli dell'unità, ma tre se una giacenza ha già dei decimali: 2,5 pz
     * scritti prima si mostrano 2,500. Arrotondarli vorrebbe dire salvare un
     * movimento di +0,5 che nessuno ha chiesto.
     */
    public static function decimalsFor(string $unit, float ...$quantities): int
    {
        foreach ($quantities as $quantity) {
            if (self::isFractional($quantity)) {
                return 3;
            }
        }

        return self::decimals($unit);
    }

    /** Se la quantità ha decimali, fino ai millesimi che il database tiene. */
    public static function isFractional(float $quantity): bool
    {
        return round($quantity, 3) !== round($quantity, 0);
    }

    private static function normalize(string $unit): string
    {
        return strtolower(trim($unit));
    }
}
