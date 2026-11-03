<?php

namespace Wonder\Plugin\Gestionale\Support\Labels;

use Throwable;
use Wonder\Plugin\Gestionale\Extensions\Extensions;
use Wonder\Support\Labels\LabelFormat;

/**
 * I formati di etichette fra cui si sceglie in stampa: quelli standard del
 * core (fogli A4 adesivi e rotoli termici). Il sito ne aggiunge o ne toglie con
 * `labelFormats()` di un'estensione.
 */
final class LabelFormats
{
    /** @return array<string, LabelFormat> */
    public static function defaults(): array
    {
        return LabelFormat::standard();
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
