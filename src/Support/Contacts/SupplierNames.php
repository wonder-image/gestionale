<?php

namespace Wonder\Plugin\Gestionale\Support\Contacts;

use Throwable;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;

/**
 * I nomi dei fornitori per gli elenchi del magazzino (G3, Acquisti): una
 * lettura per richiesta, eliminati e non attivi compresi, perché un carico o
 * un movimento vecchio tiene il suo fornitore anche dopo.
 */
final class SupplierNames
{
    /** @var array<int, string>|null */
    private static ?array $names = null;

    /** @return array<int, string> id => nome */
    public static function all(): array
    {
        if (self::$names !== null) {
            return self::$names;
        }

        try {
            $rows = Contact::find("`is_supplier` = 'true' AND `deleted` IN ('true', 'false')");
        } catch (Throwable) {
            return self::$names = [];
        }

        $rows = !is_array($rows) || $rows === [] ? [] : (isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array')));
        $names = [];

        foreach ($rows as $row) {
            $names[(int) $row['id']] = Contacts::displayName($row);
        }

        asort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return self::$names = $names;
    }

    /** Il nome, o stringa vuota per «nessun fornitore». */
    public static function of(int $id): string
    {
        return $id > 0 ? (self::all()[$id] ?? 'Fornitore #'.$id) : '';
    }

    /**
     * La condizione del filtro *Fornitore*: solo id interi; «0» = senza
     * fornitore.
     *
     * @param list<string> $values
     */
    public static function condition(array $values, string $column = '`supplier_id`'): string
    {
        $ids = [];

        foreach ($values as $value) {
            if (preg_match('/^\d{1,10}$/D', (string) $value) === 1) {
                $ids[] = (int) $value;
            }
        }

        return $ids === [] ? '' : $column.' IN ('.implode(', ', array_unique($ids)).')';
    }

    public static function reset(): void
    {
        self::$names = null;
    }
}
