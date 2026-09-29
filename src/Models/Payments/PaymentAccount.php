<?php

namespace Wonder\Plugin\Gestionale\Models\Payments;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * Il conto su cui arrivano i soldi: banca e IBAN da stampare su preventivi,
 * fatture e fattura elettronica (4.8).
 *
 * Configurazione di `admin`, con il codice parlante (`banca-principale`) e il
 * deploy che la porta in produzione, come le aliquote.
 */
final class PaymentAccount extends Model
{
    public static string $table = 'gst_payment_accounts';
    public static string $folder = 'gestionale/payments';
    public static string $icon = 'bi bi-bank';

    public static function syncSchema(): ?SyncSchema
    {
        return SyncSchema::multiRow()->keepIds()->localOnly();
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('code')->length(100)->null(false)->unique(),
            Column::key('name'),
            Column::key('bank_name'),
            Column::key('iban')->length(34),
            Column::key('bic')->length(11),
            Column::key('active')->enum(['true', 'false'])->default('true'),
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->slug()->readonlyOnUpdate()->immutableOnUpdate(),
            Field::key('name')->text()->sanitizeFirst(),
            Field::key('bank_name')->text()->sanitizeFirst(),
            Field::key('iban')->text()->sanitize(false),
            Field::key('bic')->text()->sanitize(false),
            Field::key('active')->text()->sanitize(false),
        ];
    }
}
