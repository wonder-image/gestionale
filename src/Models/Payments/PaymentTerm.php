<?php

namespace Wonder\Plugin\Gestionale\Models\Payments;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Sql\TableSchema as Column;

/**
 * Una condizione di pagamento: «Rimessa diretta», «Bonifico 30 gg fine mese»,
 * «RiBa 30/60 gg fine mese» (G9, architettura 4.8).
 *
 * Dice in quante rate si paga e quando: le rate stanno in
 * `PaymentTermInstallment`. Non sa niente di ordini: la usano gli ordini da
 * ufficio e, con G10, gli abbonamenti da ufficio. `sdi_condition` è il codice
 * della fattura elettronica (`TP01` a rate, `TP02` completo, `TP03` anticipo).
 *
 * Configurazione di `admin`, portata in produzione dal deploy come metodi e
 * conti.
 */
final class PaymentTerm extends Model
{
    public static string $table = 'gst_payment_terms';
    public static string $folder = 'gestionale/payments';
    public static string $icon = 'bi bi-calendar3';

    public const SDI_CONDITIONS = ['TP01', 'TP02', 'TP03'];

    public static function syncSchema(): ?SyncSchema
    {
        return SyncSchema::multiRow()->keepIds()->localOnly();
    }

    public static function tableSchema(): array
    {
        return [
            ...static::sqlColumnsFromDataSchema(['code']),
            Column::key('name'),
            Column::key('sdi_condition')->enum(self::SDI_CONDITIONS)->default('TP02'),
            Column::key('active')->enum(['true', 'false'])->default('true'),
            Column::key('position')->int(),
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->uniqueCode(Codes::PAYMENT_TERM),
            Field::key('name')->text()->sanitizeFirst(),
            Field::key('sdi_condition')->text()->sanitize(false),
            Field::key('active')->text()->sanitize(false),
            Field::key('position')->number()->decimals(0),
        ];
    }
}
