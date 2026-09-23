<?php

namespace Wonder\Plugin\Gestionale\Support\Tax;

use Throwable;
use Wonder\Plugin\Gestionale\Models\Tax\TaxCategory;

/**
 * I tipi fiscali che un articolo può scegliere, e quello da cui parte.
 *
 * Un articolo nuovo parte dal tipo segnato «Predefinito»; se nessuno lo è,
 * dal primo visibile. I nascosti non si propongono: un tipo si nasconde
 * proprio perché non lo si scelga più.
 */
final class TaxCategories
{
    /**
     * @param int $keep il tipo che l'articolo usa già: se è nascosto resta in
     *                  coda, altrimenti il select ne mostrerebbe un altro e
     *                  salvando lo sostituirebbe
     *
     * @return array<string, string> id => nome, nell'ordine dell'elenco
     */
    public static function options(int $keep = 0): array
    {
        $rows = self::rows();
        $kept = null;

        if ($keep > 0 && !in_array($keep, array_map(static fn ($row) => (int) $row['id'], $rows), true)) {
            try {
                $found = TaxCategory::find(['id' => $keep, 'deleted' => 'false'], 1);
                $kept = is_array($found) && isset($found['id']) ? $found : null;
            } catch (Throwable) {
                $kept = null;
            }
        }

        return self::optionsIn($rows, $kept);
    }

    /**
     * La regola, senza database: i visibili in ordine, poi il nascosto in uso.
     *
     * @param list<array<string, mixed>> $rows tipi visibili, già in ordine
     * @param array<string, mixed>|null  $kept il tipo nascosto che l'articolo usa
     *
     * @return array<string, string>
     */
    public static function optionsIn(array $rows, ?array $kept = null): array
    {
        $options = [];

        foreach ($rows as $row) {
            $options[(string) $row['id']] = (string) ($row['name'] ?? '');
        }

        $keptId = (string) ($kept['id'] ?? '');

        if ($keptId !== '' && !isset($options[$keptId])) {
            $options[$keptId] = (string) ($kept['name'] ?? '').' (nascosto)';
        }

        return $options;
    }

    /** Il tipo da cui parte un articolo nuovo; `0` se non ce n'è nessuno. */
    public static function defaultId(): int
    {
        return self::defaultIn(self::rows());
    }

    /**
     * La regola, senza database: il predefinito, altrimenti il primo.
     *
     * @param list<array<string, mixed>> $rows tipi visibili, già in ordine
     */
    public static function defaultIn(array $rows): int
    {
        foreach ($rows as $row) {
            if (($row['is_default'] ?? 'false') === 'true') {
                return (int) $row['id'];
            }
        }

        return (int) ($rows[0]['id'] ?? 0);
    }

    /**
     * I tipi visibili, o niente.
     *
     * Il `formSchema()` di una Resource viene valutato anche dove un database
     * non c'è — i test degli schemi, i comandi di `forge` — e un elenco vuoto
     * è una risposta migliore di un errore di connessione.
     *
     * @return list<array<string, mixed>>
     */
    private static function rows(): array
    {
        try {
            $rows = TaxCategory::find(['deleted' => 'false', 'visible' => 'true'], null, 'position', 'ASC');
        } catch (Throwable) {
            return [];
        }

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }
}
