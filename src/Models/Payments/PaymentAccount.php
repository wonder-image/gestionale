<?php

namespace Wonder\Plugin\Gestionale\Models\Payments;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Sql\TableSchema as Column;

/**
 * Il conto su cui arrivano i soldi: banca e IBAN da stampare su preventivi,
 * fatture e fattura elettronica (4.8).
 *
 * Configurazione di `admin`, portata in produzione dal deploy come le aliquote.
 * Il codice (`pac_k3x9d2a`) lo genera il framework: chi compila il conto non lo
 * scrive. `holder` è l'intestatario, che il bonifico vuole accanto all'IBAN.
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
            ...static::sqlColumnsFromDataSchema(['code']),
            Column::key('name'),
            Column::key('holder'),
            Column::key('bank_name'),
            Column::key('iban')->length(34),
            Column::key('bic')->length(11),
            Column::key('active')->enum(['true', 'false'])->default('true'),
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->uniqueCode(Codes::PAYMENT_ACCOUNT),
            Field::key('name')->text()->sanitizeFirst(),
            Field::key('holder')->text()->sanitize(false),
            Field::key('bank_name')->text()->sanitizeFirst(),
            Field::key('iban')->text()->sanitize(false),
            Field::key('bic')->text()->sanitize(false),
            Field::key('active')->text()->sanitize(false),
        ];
    }
}
