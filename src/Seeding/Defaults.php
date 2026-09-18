<?php

namespace Wonder\Plugin\Gestionale\Seeding;

use Wonder\App\Module\Contracts\ModuleDefaults;
use Wonder\App\Support\DefaultRows;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\System\Feature;
use Wonder\Plugin\Gestionale\Support\Features\FeatureCatalog;

/**
 * Righe precaricate del gestionale, create da `forge update` in locale.
 * Regola unica: si inserisce solo ciò che manca, mai si modifica ciò che c'è.
 */
final class Defaults implements ModuleDefaults
{
    public static function seed(DefaultRows $rows): void
    {
        $unlock = array_map('strval', (array) Gestionale::config('features.unlock', []));
        $features = [];

        foreach (array_keys(FeatureCatalog::all()) as $key) {
            $features[] = [
                'feature_key' => $key,
                // Il sito può far nascere sbloccate alcune funzionalità (starter),
                // mai cambiarle dopo: le righe esistenti non si toccano.
                'enabled' => in_array($key, $unlock, true) ? 'true' : 'false',
            ];
        }

        $rows->ensure(Feature::class, 'feature_key', $features);
    }
}
