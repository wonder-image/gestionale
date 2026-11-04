<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog\Import;

use Wonder\Plugin\Gestionale\Support\Catalog\ProductExport;

/**
 * Le celle del file del catalogo, lette come le scrive una persona: pure,
 * senza database.
 */
final class CatalogCells
{
    /** Le colonne che non si leggono: l'id e il link li decide il gestionale. */
    public const IGNORED = ['id', 'link'];

    public const NUMBERS = ['price', 'sale_price', 'available', 'weight'];

    public const BOOLEANS = ['backorder', 'visible', 'visible_online', 'active'];

    private const YES = ['sì', 'si', 'vero', '1', 'x', 'true', 'yes'];

    private const NO = ['no', 'falso', '0', 'false'];

    /** «1.234,50 €» → 1234.5; null se non è un numero. */
    public static function number(string $value): ?float
    {
        $text = str_replace([' ', "\u{00A0}", '€'], '', trim($value));

        if (str_contains($text, ',') && str_contains($text, '.')) {
            $text = str_replace(',', '.', str_replace('.', '', $text));
        } elseif (str_contains($text, ',')) {
            $text = str_replace(',', '.', $text);
        }

        return $text !== '' && is_numeric($text) ? (float) $text : null;
    }

    /** Sì/No e i loro sinonimi; null se non si capisce. */
    public static function boolean(string $value): ?bool
    {
        $text = mb_strtolower(trim($value), 'UTF-8');

        return match (true) {
            in_array($text, self::YES, true) => true,
            in_array($text, self::NO, true) => false,
            default => null,
        };
    }

    /** «Illimitata» nella colonna dei disponibili: si lascia com'è. */
    public static function unlimited(string $value): bool
    {
        return mb_strtolower(trim($value), 'UTF-8') === 'illimitata';
    }

    /**
     * Le voci di una cella a più valori («a | b»), senza doppioni né vuoti.
     *
     * @return list<string>
     */
    public static function list(string $value): array
    {
        $parts = array_map('trim', explode(trim(ProductExport::VALUE_SEPARATOR), $value));

        return array_values(array_unique(array_filter($parts, static fn (string $part): bool => $part !== '')));
    }

    /**
     * Le categorie: percorsi «Padre › Figlia», il primo è il principale.
     *
     * @return list<list<string>>
     */
    public static function categories(string $value): array
    {
        $paths = [];

        foreach (self::list($value) as $path) {
            $levels = array_values(array_filter(array_map('trim', explode('›', $path)), static fn (string $level): bool => $level !== ''));

            if ($levels !== []) {
                $paths[implode(' › ', $levels)] = $levels;
            }
        }

        return array_values($paths);
    }

    /** Il prezzo dichiarato da un'intestazione: true IVA inclusa, false esclusa, null se non lo dice. */
    public static function headerTax(string $header): ?bool
    {
        $text = mb_strtolower($header, 'UTF-8');

        return match (true) {
            str_contains($text, 'iva esclusa') => false,
            str_contains($text, 'iva inclusa') => true,
            default => null,
        };
    }

    /**
     * Vero se la cella dice quello che c'è già: la riga si salta.
     *
     * @param array<string, mixed> $export la riga dell'esportazione
     */
    public static function same(string $key, string $value, array $export): bool
    {
        $current = $export[$key] ?? null;

        if (in_array($key, self::NUMBERS, true)) {
            if ($key === 'available' && (self::unlimited($value) || trim($value) === '')) {
                // Illimitata o vuota: la giacenza non si tocca.
                return trim($value) === '' || $current === null;
            }

            $number = self::number($value);

            if (trim($value) === '') {
                return $current === null;
            }

            return $current !== null && $number !== null && abs((float) $current - $number) < 0.0005;
        }

        if (in_array($key, self::BOOLEANS, true)) {
            return self::boolean($value) === self::boolean((string) $current);
        }

        if ($key === 'categories') {
            return self::categories($value) === self::categories((string) $current);
        }

        if ($key === 'description' || $key === 'short_description') {
            return ProductExport::plain($value) === (string) $current;
        }

        if (str_starts_with($key, 'attr:')) {
            return self::list($value) === self::list((string) $current);
        }

        return trim($value) === trim((string) $current);
    }
}
