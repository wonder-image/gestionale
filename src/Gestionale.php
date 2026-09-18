<?php

namespace Wonder\Plugin\Gestionale;

use Wonder\App\Module\ConfigRepository;
use Wonder\App\Module\Contracts\ModuleInterface;

/**
 * Entrypoint del modulo: percorsi, configurazione e indirizzi della guida.
 */
final class Gestionale implements ModuleInterface
{
    public const SLUG = 'gestionale';

    private static ?array $config = null;
    private static ?array $features = null;

    public static function root(): string
    {
        return dirname(__DIR__);
    }

    public static function manifestPath(): string
    {
        return self::root().'/module.json';
    }

    public static function handlerPath(string $path): string
    {
        return self::root().'/http/'.ltrim($path, '/');
    }

    /** La view del sito, se pubblicata, vince su quella del modulo. */
    public static function viewPath(string $path): string
    {
        $root = (string) ($GLOBALS['ROOT'] ?? '');
        $custom = $root.'/custom/modules/'.self::SLUG.'/view/'.ltrim($path, '/');

        return $root !== '' && is_file($custom) ? $custom : self::root().'/view/'.ltrim($path, '/');
    }

    public static function langPath(): string
    {
        return self::root().'/lang';
    }

    public static function assetPath(string $path = ''): string
    {
        return self::root().'/resources/assets/'.ltrim($path, '/');
    }

    /** Configurazione del modulo, con l'override del sito. */
    public static function config(?string $key = null, mixed $default = null): mixed
    {
        if (self::$config === null) {
            $defaults = require self::root().'/config/module.php';
            $site = class_exists(ConfigRepository::class) ? ConfigRepository::for(self::SLUG) : [];
            self::$config = array_replace_recursive((array) $defaults, (array) $site);
        }

        if ($key === null) {
            return self::$config;
        }

        $current = self::$config;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    /** Stato effettivo di tutte le funzionalità, calcolato una volta per richiesta. */
    public static function features(): array
    {
        if (self::$features !== null) {
            return self::$features;
        }

        $unlocked = [];

        foreach (Models\System\Feature::find(['deleted' => 'false']) ?: [] as $row) {
            if (is_array($row)) {
                $unlocked[(string) ($row['feature_key'] ?? '')] = ($row['enabled'] ?? 'false') === 'true';
            }
        }

        $modules = array_map(
            static fn ($manifest): string => $manifest->slug(),
            \Wonder\App\Module\Registry::enabled()
        );

        return self::$features = Support\Features\FeatureState::resolve(
            Support\Features\FeatureCatalog::all(),
            $unlocked,
            array_values($modules)
        );
    }

    /** Stato di una funzionalità; una chiave sconosciuta è sempre bloccata. */
    public static function feature(string $key): bool
    {
        return self::features()[$key] ?? false;
    }

    /** Indirizzo di una pagina della guida commercianti; vuoto se non configurato. */
    public static function docsUrl(string $page): string
    {
        $base = trim((string) self::config('docs.merchant_url', ''));
        $page = trim($page, '/ ');

        if ($base === '' || $page === '') {
            return '';
        }

        return rtrim($base, '/').'/'.$page;
    }

    public static function reset(): void
    {
        self::$config = null;
        self::$features = null;
    }
}
