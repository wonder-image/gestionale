<?php

namespace Wonder\Plugin\Gestionale\Extensions;

/**
 * Un modulo che aggiunge riquadri alle Impostazioni di Set Up: lo implementa
 * il suo entrypoint, quello del `module.json`.
 */
interface ProvidesSettings
{
    /** @return iterable<SettingsSection> */
    public static function settingsSections(): iterable;
}
