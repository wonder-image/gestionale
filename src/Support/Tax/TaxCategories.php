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
    /** @return array<string, string> id => nome, nell'ordine dell'elenco */
    public static function options(): array
    {
        $options = [];

        foreach (self::rows() as $row) {
            $options[(string) $row['id']] = (string) ($row['name'] ?? '');
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
