<?php

namespace Wonder\Plugin\Gestionale\Models\System;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * Base dei log degli stati: ogni documento con stati ha la sua tabella, sempre
 * con le stesse colonne (4.1).
 *
 * Le tabelle vere nascono con i loro sotto-progetti — `gst_order_status_logs`,
 * `gst_payment_status_logs`, `gst_invoice_status_logs` e le altre — e nessuna
 * si sincronizza tra ambienti: sono la storia di quell'ambiente.
 *
 * Una sottoclasse dichiara solo tre cose: `$table` (es. `gst_order_status_logs`),
 * `entityColumn()` (es. `order_id`) ed `entityTable()` (la tabella del
 * documento). Il resto — colonne, indice e campi — arriva da qui.
 *
 * Niente esempi con la parola chiave `class` in questo commento: il registro
 * dei Model legge la prima che trova nel file, commenti compresi.
 */
abstract class StatusLog extends Model
{
    public static string $icon = 'bi bi-clock-history';

    /** Colonna che punta al documento, es. `order_id`. */
    abstract public static function entityColumn(): string;

    /** Tabella del documento, per la chiave esterna. */
    abstract public static function entityTable(): string;

    /** La storia di un ambiente non viaggia con il deploy. */
    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    /** Colonne comuni a tutti i log degli stati. @return list<Column> */
    public static function commonColumns(): array
    {
        return [
            Column::key('field')->length(100),
            Column::key('from_value')->length(100),
            Column::key('to_value')->length(100),
            Column::key('source')->length(20),
            Column::key('user_id')->int(),
            Column::key('message')->type('TEXT'),
            Column::key('response')->json(),
        ];
    }

    /** Campi comuni per il `dataSchema()`. @return list<Field> */
    public static function commonFields(): array
    {
        return [
            Field::key('field')->text()->sanitize(false),
            Field::key('from_value')->text()->sanitize(false),
            Field::key('to_value')->text()->sanitize(false),
            Field::key('source')->text()->sanitize(false),
            Field::key('user_id')->number()->decimals(0),
            Field::key('message')->text(),
            Field::key('response')->json(),
        ];
    }

    public static function tableSchema(): array
    {
        return [
            Column::key(static::entityColumn())->int()->null(false)->foreign(static::entityTable()),
            ...static::commonColumns(),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_entity' => ['index' => static::entityColumn()],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key(static::entityColumn())->number()->decimals(0),
            ...static::commonFields(),
        ];
    }
}
