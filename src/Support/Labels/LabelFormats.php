<?php

namespace Wonder\Plugin\Gestionale\Support\Labels;

use Throwable;
use Wonder\Plugin\Gestionale\Extensions\Extensions;

/**
 * I formati di etichette fra cui si sceglie in stampa: i fogli A4 adesivi più
 * comuni e due rotoli termici. Il sito ne aggiunge o ne toglie con
 * `labelFormats()` di un'estensione.
 */
final class LabelFormats
{
    /** @return array<string, LabelFormat> */
    public static function defaults(): array
    {
        $formats = [
            LabelFormat::a4('a4-24', 'A4, 24 etichette (70 × 37 mm)', 3, 8, 70.0, 37.0),
            LabelFormat::a4('a4-40', 'A4, 40 etichette (52,5 × 29,7 mm)', 4, 10, 52.5, 29.7),
            LabelFormat::a4('a4-65', 'A4, 65 etichette (38,1 × 21,2 mm)', 5, 13, 38.1, 21.2),
            LabelFormat::roll('roll-50x30', 'Rotolo termico 50 × 30 mm', 50.0, 30.0),
            LabelFormat::roll('roll-40x25', 'Rotolo termico 40 × 25 mm', 40.0, 25.0),
        ];

        $out = [];

        foreach ($formats as $format) {
            $out[$format->key] = $format;
        }

        return $out;
    }

    /** @return array<string, LabelFormat> */
    public static function all(): array
    {
        $formats = self::defaults();

        try {
            $filtered = Extensions::filter('labelFormats', $formats);
        } catch (Throwable) {
            return $formats;
        }

        $out = [];

        foreach (is_array($filtered) ? $filtered : [] as $format) {
            if ($format instanceof LabelFormat && $format->perPage() > 0 && $format->labelWidth > 0 && $format->labelHeight > 0) {
                $out[$format->key] = $format;
            }
        }

        return $out !== [] ? $out : $formats;
    }

    public static function find(string $key): ?LabelFormat
    {
        return self::all()[$key] ?? null;
    }

    /** @return array<string, string> chiave => nome, per la tendina. */
    public static function options(): array
    {
        return array_map(static fn (LabelFormat $format): string => $format->name, self::all());
    }
}
