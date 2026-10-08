<?php

namespace Wonder\Plugin\Gestionale\Extensions;

use Throwable;
use Wonder\App\Module\Registry;

/**
 * I riquadri che i moduli accesi aggiungono alle Impostazioni di Set Up,
 * letti una volta per richiesta.
 *
 * Senza registro (test degli schemi, comandi fuori dal sito) nessuno. Un
 * modulo che si rompe dando i suoi riquadri invece si fa sentire: tacerlo
 * vorrebbe dire un `forge update` che toglie le sue colonne.
 */
final class SettingsSections
{
    /** @var list<SettingsSection>|null */
    private static ?array $sections = null;

    /** @return list<SettingsSection> */
    public static function all(): array
    {
        if (self::$sections !== null) {
            return self::$sections;
        }

        try {
            $entrypoints = array_map(static fn ($manifest): string => $manifest->entrypoint(), Registry::enabled());
        } catch (Throwable) {
            $entrypoints = [];
        }

        return self::$sections = self::fromEntrypoints($entrypoints);
    }

    /**
     * I riquadri degli entrypoint che implementano `ProvidesSettings`; gli
     * altri si saltano.
     *
     * @param iterable<string> $entrypoints
     * @return list<SettingsSection>
     */
    public static function fromEntrypoints(iterable $entrypoints): array
    {
        $sections = [];

        foreach ($entrypoints as $entrypoint) {
            if (!is_string($entrypoint) || !is_subclass_of($entrypoint, ProvidesSettings::class)) {
                continue;
            }

            foreach ($entrypoint::settingsSections() as $section) {
                if ($section instanceof SettingsSection) {
                    $sections[] = $section;
                }
            }
        }

        return $sections;
    }

    /** Forza i riquadri; `null` torna a leggerli dai moduli. */
    public static function use(?array $sections): void
    {
        self::$sections = $sections === null ? null : array_values($sections);
    }
}
