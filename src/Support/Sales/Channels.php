<?php

namespace Wonder\Plugin\Gestionale\Support\Sales;

use Wonder\Plugin\Gestionale\Gestionale;

/**
 * I canali di vendita che il sito ha acceso: sito, ufficio, cassa.
 *
 * La maggior parte dei negozi usa una sola delle tre piattaforme, quindi
 * ognuna ha la sua funzionalità (`online_sales`, `office_sales`, `pos`) e i
 * form di coupon, campagne e metodi di pagamento mostrano solo i toggle dei
 * canali accesi. Se nessuno è acceso vale il sito: i siti esistenti, nati
 * prima del pannello, non cambiano.
 *
 * Il motore (`CouponRules`, `Campaigns`) non sa niente di tutto questo: legge
 * sempre le colonne `applies_*`. Questa classe decide solo cosa si vede nel
 * form e che valore parte per un record nuovo.
 */
final class Channels
{
    /** @var array<string, array{feature: string, label: string, name: string}> */
    private const ALL = [
        'online' => ['feature' => 'online_sales', 'label' => 'Sito', 'name' => 'Vendita online'],
        'office' => ['feature' => 'office_sales', 'label' => 'Ufficio', 'name' => 'Vendita in ufficio'],
        'pos' => ['feature' => 'pos', 'label' => 'Cassa', 'name' => 'Vendita in cassa'],
    ];

    /** @return array<string, array{feature: string, label: string, name: string}> */
    public static function all(): array
    {
        return self::ALL;
    }

    /** I canali accesi, nell'ordine sito, ufficio, cassa. @return list<string> */
    public static function active(): array
    {
        return self::activeFrom(Gestionale::features());
    }

    /**
     * @param array<string, bool> $features
     * @return list<string>
     */
    public static function activeFrom(array $features): array
    {
        $active = [];

        foreach (self::ALL as $channel => $info) {
            if (!empty($features[$info['feature']])) {
                $active[] = $channel;
            }
        }

        return $active === [] ? ['online'] : $active;
    }

    public static function label(string $channel): string
    {
        return self::ALL[$channel]['label'] ?? $channel;
    }

    /** La colonna `applies_*` del canale. */
    public static function column(string $channel): string
    {
        return 'applies_'.$channel;
    }

    /** I toggle si mostrano solo se c'è davvero da scegliere. @param list<string> $active */
    public static function chooseFrom(array $active): bool
    {
        return count($active) > 1;
    }

    public static function choose(): bool
    {
        return self::chooseFrom(self::active());
    }

    /**
     * I valori di partenza di un record nuovo: «sì» sui canali accesi, «no»
     * sugli altri.
     *
     * @param list<string> $active
     * @return array<string, string>
     */
    public static function defaultsFrom(array $active): array
    {
        $defaults = [];

        foreach (self::ALL as $channel => $info) {
            $defaults[self::column($channel)] = in_array($channel, $active, true) ? 'true' : 'false';
        }

        return $defaults;
    }

    /** @return array<string, string> */
    public static function defaults(): array
    {
        return self::defaultsFrom(self::active());
    }

    /**
     * I canali da scrivere nel record.
     *
     * Quelli che il form mostra passano come sono (un toggle spento che non
     * arriva vale «no»). Quelli che non mostra non si toccano: restano i valori
     * del record, o quelli di partenza se è nuovo. Se il form non ha toggle,
     * perché il canale acceso è uno solo, quello vale «sì»: un record che non
     * vale da nessuna parte non serve a niente e non ci sarebbe modo di
     * correggerlo.
     *
     * @param list<string> $active
     * @param array<string, mixed> $values i valori della richiesta
     * @param array<string, mixed>|null $old il record com'è salvato, `null` se è nuovo
     * @return array<string, mixed> `$values` con le tre colonne `applies_*`
     */
    public static function keepHiddenFrom(array $active, array $values, ?array $old): array
    {
        $defaults = self::defaultsFrom($active);
        $choose = self::chooseFrom($active);

        foreach (self::ALL as $channel => $info) {
            $column = self::column($channel);
            $shown = $choose && in_array($channel, $active, true);

            $values[$column] = match (true) {
                $shown => ($values[$column] ?? 'false') === 'true' ? 'true' : 'false',
                !$choose && in_array($channel, $active, true) => 'true',
                $old !== null && isset($old[$column]) => (string) $old[$column],
                default => $defaults[$column],
            };
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed>|null $old
     * @return array<string, mixed>
     */
    public static function keepHidden(array $values, ?array $old): array
    {
        return self::keepHiddenFrom(self::active(), $values, $old);
    }
}
