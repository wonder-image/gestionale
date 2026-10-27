<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog\Exports;

use InvalidArgumentException;
use RuntimeException;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Support\Features\FeaturePanel;

/**
 * I feed a indirizzo fisso: lo stesso file dell'«Esporta», riscritto da solo
 * in `shared/feeds/` (accanto alla sitemap) perché Google Merchant Center e
 * Meta lo vadano a prendere quando vogliono.
 *
 * Il file c'è solo finché la funzionalità è sbloccata: spenta, il file sparisce,
 * così all'indirizzo già dato a Google non resta un catalogo vecchio. Ogni
 * quanto si riscrive lo si sceglie nel pannello Funzionalità, accanto
 * all'interruttore; l'attività `gestionale.product_feeds` passa ogni ora e
 * riscrive solo ciò che è scaduto.
 */
final class ProductFeeds
{
    /** chiave del feed → formato di `ProductExports` e nome fisso del file */
    public const FEEDS = [
        'meta' => ['file' => 'catalogo-meta.csv'],
        'google' => ['file' => 'google-merchant.xml'],
    ];

    /** Ogni quante ore si riscrive il file → etichetta del pannello. */
    public const EVERY = [
        1 => 'Ogni ora',
        6 => 'Ogni 6 ore',
        24 => 'Una volta al giorno',
    ];

    /**
     * Di serie ogni 6 ore: Google e Meta leggono il file al massimo una volta
     * al giorno, all'ora scelta da loro, e così prezzi e disponibilità non
     * sono mai indietro di più di sei ore. Scriverlo costa una lettura del
     * catalogo.
     */
    public const DEFAULT_EVERY = 6;

    /** L'attività passa ogni ora: un file scritto 59 minuti fa è già da rifare. */
    private const SLACK = 300;

    /** La funzionalità del feed, quella di `ProductExports`. */
    public static function feature(string $key): string
    {
        self::feed($key);

        return ProductExports::FORMATS[$key]['feature'];
    }

    /** Il feed di una funzionalità, null se la funzionalità non ne ha. */
    public static function forFeature(string $feature): ?string
    {
        foreach (array_keys(self::FEEDS) as $key) {
            if (ProductExports::FORMATS[$key]['feature'] === $feature) {
                return $key;
            }
        }

        return null;
    }

    /** Ogni quante ore, dalle impostazioni della funzionalità; una scelta che non c'è vale il default. */
    public static function every(string $key, ?array $settings = null): int
    {
        $settings ??= FeaturePanel::settings()[self::feature($key)] ?? [];
        $every = (int) ($settings['every'] ?? 0);

        return isset(self::EVERY[$every]) ? $every : self::DEFAULT_EVERY;
    }

    /** La cartella dei file, dentro la radice del sito. */
    public static function dir(?string $root = null): string
    {
        return rtrim($root ?? (string) ($GLOBALS['ROOT'] ?? getcwd()), '/').'/shared/feeds';
    }

    /**
     * L'indirizzo da dare a Google o Meta. Dal dominio di produzione quando
     * c'è: il pannello si compila in locale, dove `APP_URL` è quello di Herd.
     */
    public static function url(string $key, ?string $domain = null, ?string $base = null): string
    {
        $domain ??= (string) ($_ENV['APP_DOMAIN'] ?? getenv('APP_DOMAIN') ?: '');
        $base ??= ProductFeed::shopUrl();
        $domain = trim(preg_replace('#^https?://#', '', $domain) ?? '', '/');
        $root = $domain !== '' ? 'https://'.$domain.'/' : $base;

        return $root === '' ? '' : $root.'shared/feeds/'.self::feed($key)['file'];
    }

    /** Se il file va riscritto: manca, o è più vecchio della frequenza scelta. */
    public static function due(?int $writtenAt, int $everyHours, int $now): bool
    {
        return $writtenAt === null || $now - $writtenAt >= $everyHours * 3600 - self::SLACK;
    }

    /** Quando è stato scritto il file, null se non c'è. */
    public static function writtenAt(string $key, ?string $dir = null): ?int
    {
        $path = ($dir ?? self::dir()).'/'.self::feed($key)['file'];
        clearstatcache(true, $path);

        return is_file($path) ? (int) filemtime($path) : null;
    }

    /** I file già scritti diventano scaduti: il prossimo giro li riscrive. */
    public static function expire(?string $dir = null): void
    {
        foreach (self::FEEDS as $key => $feed) {
            $path = ($dir ?? self::dir()).'/'.$feed['file'];

            if (is_file($path)) {
                touch($path, 1);
            }
        }
    }

    /**
     * Un giro dell'attività: per ogni feed, sbloccato e scaduto si riscrive,
     * bloccato si cancella. Le dipendenze si passano per i test.
     *
     * @param (callable(string): bool)|null $enabled la funzionalità è sbloccata
     * @param (callable(string): int)|null $every ogni quante ore
     * @param (callable(string): string)|null $body il contenuto del file
     * @return array{written: list<string>, removed: list<string>, skipped: list<string>}
     */
    public static function refresh(
        ?string $dir = null,
        ?callable $enabled = null,
        ?callable $every = null,
        ?callable $body = null,
        ?int $now = null,
    ): array {
        $dir ??= self::dir();
        $enabled ??= static fn (string $key): bool => Gestionale::feature(self::feature($key));
        $every ??= static fn (string $key): int => self::every($key);
        $body ??= static fn (string $key): string => ProductExports::body($key);
        $now ??= time();
        $result = ['written' => [], 'removed' => [], 'skipped' => []];

        foreach (self::FEEDS as $key => $feed) {
            $path = $dir.'/'.$feed['file'];

            if (!$enabled($key)) {
                if (is_file($path) && unlink($path)) {
                    $result['removed'][] = $key;
                }

                continue;
            }

            if (!self::due(self::writtenAt($key, $dir), $every($key), $now)) {
                $result['skipped'][] = $key;

                continue;
            }

            self::write($path, $body($key));
            $result['written'][] = $key;
        }

        return $result;
    }

    /** Scrive su un file a parte e poi lo sposta: chi legge non vede mai un file a metà. */
    private static function write(string $path, string $content): void
    {
        $dir = dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Impossibile creare la cartella dei feed: '.$dir);
        }

        $temporary = $path.'.'.bin2hex(random_bytes(4)).'.tmp';

        if (file_put_contents($temporary, $content) === false || !rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException('Impossibile scrivere il feed: '.$path);
        }

        @chmod($path, 0644);
    }

    /** @return array{file: string} */
    private static function feed(string $key): array
    {
        return self::FEEDS[$key] ?? throw new InvalidArgumentException('Feed sconosciuto: '.$key);
    }
}
