<?php

namespace Wonder\Plugin\Gestionale\Models\Payments;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * Come si paga: bonifico, carta, contrassegno, pagamento al ritiro (4.8).
 *
 * `fee_type` e `fee_value` generano la riga `fee` dell'ordine, con l'aliquota
 * di spedizione e commissioni; per il contrassegno il `cod_fee` del listino di
 * spedizione prevarrà su questo, ma arriva con G7.
 *
 * `available_for` limita il metodo alla consegna scelta: il contrassegno solo
 * con spedizione, il pagamento al ritiro solo con ritiro. `sdi_code` è il
 * codice della fattura elettronica (MP01–MP23).
 *
 * Configurazione di `admin` con il codice parlante (`bank-transfer`), portata
 * in produzione dal deploy.
 */
final class PaymentMethod extends Model
{
    public static string $table = 'gst_payment_methods';
    public static string $folder = 'gestionale/payments';
    public static string $icon = 'bi bi-credit-card';

    public const FEE_TYPES = ['none', 'amount', 'percent'];
    public const AVAILABLE_FOR = ['all', 'shipping', 'pickup'];
    public const PROVIDERS = ['manual', 'stripe', 'paypal', 'nexi'];

    public static function syncSchema(): ?SyncSchema
    {
        return SyncSchema::multiRow()->keepIds()->localOnly();
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('code')->length(100)->null(false)->unique(),
            ...static::sqlColumnsFromDataSchema(['fee_value']),
            Column::key('name'),
            Column::key('sdi_code')->length(10),
            Column::key('provider')->enum(static::PROVIDERS)->default('manual'),
            // Zero vuol dire "nessun conto": niente chiave esterna.
            Column::key('payment_account_id')->int()->default(0),
            Column::key('fee_type')->enum(static::FEE_TYPES)->default('none'),
            Column::key('available_for')->enum(static::AVAILABLE_FOR)->default('all'),
            Column::key('applies_online')->enum(['true', 'false'])->default('true'),
            Column::key('applies_office')->enum(['true', 'false'])->default('true'),
            Column::key('applies_pos')->enum(['true', 'false'])->default('false'),
            Column::key('instructions')->type('TEXT'),
            Column::key('stripe_payment_method_types')->length(255),
            Column::key('active')->enum(['true', 'false'])->default('true'),
            Column::key('position')->int(),
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->slug()->readonlyOnUpdate()->immutableOnUpdate(),
            Field::key('name')->text()->sanitizeFirst(),
            Field::key('sdi_code')->text()->sanitize(false),
            Field::key('provider')->text()->sanitize(false),
            Field::key('payment_account_id')->number()->decimals(0),
            Field::key('fee_type')->text()->sanitize(false),
            Field::key('fee_value')->number()->decimals(2),
            Field::key('available_for')->text()->sanitize(false),
            Field::key('applies_online')->text()->sanitize(false),
            Field::key('applies_office')->text()->sanitize(false),
            Field::key('applies_pos')->text()->sanitize(false),
            Field::key('instructions')->text(),
            Field::key('stripe_payment_method_types')->text()->sanitize(false),
            Field::key('active')->text()->sanitize(false),
            Field::key('position')->number()->decimals(0),
        ];
    }
}
