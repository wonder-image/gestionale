<?php

namespace Wonder\Plugin\Gestionale\Support;

use Wonder\Sql\TableSchema as Column;

/**
 * Colonne che il generatore del core non sa ancora fare.
 *
 * **Decimali.** `Field::key('quantity')->number()->decimals(3)` sembra dire al
 * database di tenere tre decimali. Non è così: `Data\Fields\Number::sqlSchema()`
 * del core torna sempre `DECIMAL(10,2)`, e `decimals()` vale solo per come il
 * form scrive il numero. Una colonna nata da `sqlColumnsFromDataSchema()`
 * arrotonda quindi al centesimo, e `0,125 kg` diventa `0,13`.
 *
 * Finché il core non legge `decimals()`, le colonne che hanno bisogno di più
 * decimali si dichiarano qui, a mano, e il campo del `dataSchema()` resta come
 * sta: serve al form, non alla tabella.
 */
final class Columns
{
    /** Una colonna DECIMAL con la precisione che serve davvero. */
    public static function decimal(string $name, string $length = '10,3'): Column
    {
        return Column::key($name)->type('DECIMAL')->schema('length', $length);
    }
}
