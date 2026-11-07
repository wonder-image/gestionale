<?php

namespace Wonder\Plugin\Gestionale\Support\Pricing;

use Throwable;
use Wonder\App\Logger;
use Wonder\App\Module\ConfigRepository;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Support\Sales\Channels;

/**
 * I canali che possono avere un listino (#162), per chiave → etichetta.
 *
 * Il gestionale porta i suoi canali di vendita e i feed che conosce (`CORE`).
 * Un modulo (eBay, un marketplace, un nuovo feed) aggiunge, rinomina o toglie
 * (`false`) i suoi con `price_lists.channels` nella propria configurazione; il
 * sito ha l'ultima parola con `PriceListChannels::set()`. Una voce che non è
 * un'etichetta va nel log e si salta.
 *
 * `active()` è quello che vede il commerciante: i canali del gestionale solo
 * se la loro funzionalità è accesa (`ACTIVATION`), quelli di un modulo o del
 * sito sempre, perché registrarli è già accenderli.
 *
 * Il prezzo di un canale lo dà `ChannelPrice`: chi aggiunge un canale non
 * tocca né `PriceLists` né `LinePrice`.
 */
final class PriceListChannels
{
    /** La chiave: minuscole, cifre e `_`, come nella colonna `channel` (30). */
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,29}$/';

    /** @var array<string, string> */
    public const CORE = [
        'online' => 'Sito',
        'office' => 'Ufficio',
        'pos' => 'Cassa',
        'google' => 'Google Shopping',
        'meta' => 'Meta',
    ];

    /**
     * La funzionalità che accende un canale del gestionale. Sito, ufficio e
     * cassa seguono `Channels::activeFrom()` (con nessuno acceso vale il sito).
     *
     * @var array<string, string>
     */
    public const ACTIVATION = [
        'google' => 'export_google',
        'meta' => 'export_meta',
    ];

    /** @var array<string, string|false> */
    private static array $overrides = [];

    public static function set(string $key, string|false $label): void
    {
        self::$overrides[$key] = $label;
    }

    public static function reset(): void
    {
        self::$overrides = [];
    }

    /**
     * @param list<array<string, mixed>>|null $configs le configurazioni dei moduli, `null` per quelle vere
     * @return array<string, string>
     */
    public static function all(?array $configs = null): array
    {
        $entries = self::CORE;

        foreach ($configs ?? self::moduleConfigs() as $config) {
            foreach ((array) ($config['price_lists']['channels'] ?? []) as $key => $label) {
                $entries[(string) $key] = $label;
            }
        }

        $channels = [];

        foreach (array_merge($entries, self::$overrides) as $key => $label) {
            $key = (string) $key;

            if ($label === false) {
                unset($channels[$key]);
                continue;
            }

            if (!is_string($label) || trim($label) === '' || preg_match(self::KEY_PATTERN, $key) !== 1) {
                self::skip($key, $label);
                continue;
            }

            $channels[$key] = trim($label);
        }

        return $channels;
    }

    /**
     * I canali accesi, nell'ordine del registro.
     *
     * @return array<string, string>
     */
    public static function active(): array
    {
        return self::activeFrom(Gestionale::features(), self::all());
    }

    /**
     * @param array<string, bool> $features
     * @param array<string, string> $all i canali del registro, da `all()`
     * @return array<string, string>
     */
    public static function activeFrom(array $features, array $all): array
    {
        $sales = Channels::activeFrom($features);
        $active = [];

        foreach ($all as $key => $label) {
            $on = match (true) {
                array_key_exists($key, Channels::all()) => in_array($key, $sales, true),
                isset(self::ACTIVATION[$key]) => !empty($features[self::ACTIVATION[$key]]),
                default => true,
            };

            if ($on) {
                $active[$key] = $label;
            }
        }

        return $active;
    }

    public static function has(string $key, ?array $configs = null): bool
    {
        return array_key_exists($key, self::all($configs));
    }

    public static function label(string $key, ?array $configs = null): string
    {
        return self::all($configs)[$key] ?? $key;
    }

    /** @return list<array<string, mixed>> */
    private static function moduleConfigs(): array
    {
        try {
            return class_exists(ConfigRepository::class) ? array_values(array_filter(ConfigRepository::all(), 'is_array')) : [];
        } catch (Throwable) {
            return [];
        }
    }

    private static function skip(string $key, mixed $label): void
    {
        if (!class_exists(Logger::class)) {
            return;
        }

        try {
            Logger::log(new \InvalidArgumentException('Canale listino non valido: '.$key), 'backend', 'price_lists', 'WARNING', 'error', [
                'key' => $key,
                'label' => get_debug_type($label),
            ], false);
        } catch (Throwable) {
        }
    }
}
