<?php

namespace Wonder\Plugin\Gestionale;

use Wonder\App\Module\ConfigRepository;
use Wonder\App\Module\Contracts\ModuleInterface;
use Wonder\App\Module\Contracts\ModuleTasks;
use Wonder\Plugin\Gestionale\Scheduler\ImagesTask;
use Wonder\Plugin\Gestionale\Scheduler\StockAlertsTask;

/**
 * Entrypoint del modulo: percorsi, configurazione, guida e attività
 * pianificate.
 */
final class Gestionale implements ModuleInterface, ModuleTasks
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

    /**
     * Le attività che il modulo mette a disposizione dello scheduler del core.
     *
     * Nascono spente: le accende chi le vuole, dalla pagina delle attività.
     *
     * @return iterable<\Wonder\App\Scheduler\Contracts\TaskInterface>
     */
    public static function tasks(): iterable
    {
        return [new ImagesTask(), new StockAlertsTask()];
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
        $modules = [];

        try {
            foreach (Models\System\Feature::find(['deleted' => 'false']) ?: [] as $row) {
                if (is_array($row)) {
                    $unlocked[(string) ($row['feature_key'] ?? '')] = ($row['enabled'] ?? 'false') === 'true';
                }
            }

            $modules = array_map(
                static fn ($manifest): string => $manifest->slug(),
                \Wonder\App\Module\Registry::enabled()
            );
        } catch (\Throwable) {
            // Senza database (test degli schemi, comandi fuori dal sito) non
            // si sa cosa sia sbloccato: **tutto bloccato** è la risposta
            // giusta, perché è quella che non mostra niente per sbaglio.
            $unlocked = [];
            $modules = [];
        }

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
    /**
     * Indirizzo di una pagina della guida.
     *
     * `$space` sceglie la guida: `user` è quella del commerciante, `dev` quella
     * dello sviluppatore, per le pagine che un commerciante non deve toccare
     * (aliquote, tipi fiscali, regole).
     *
     * Il percorso della pagina è `gruppo/nome-del-file`, come lo pubblica
     * GitBook: il gruppo è il titolo `##` del SUMMARY in minuscolo con i
     * trattini, il nome è quello del file senza `.md`. `DocsPagesTest` controlla
     * che ogni pagina dichiarata esista davvero.
     */
    public static function docsUrl(string $page, string $space = 'user'): string
    {
        $key = $space === 'dev' ? 'docs.developer_url' : 'docs.merchant_url';
        $base = trim((string) self::config($key, ''));
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
