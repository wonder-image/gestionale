<?php

namespace Wonder\Plugin\Gestionale\Models\Payments;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Sql\TableSchema as Column;

/**
 * Ogni movimento di denaro è una riga, rimborsi compresi (4.8): il pagamento
 * del checkout, il bonifico registrato a mano, i contanti al banco, le rate.
 *
 * Il `payment_status` dell'ordine non si scrive mai a mano: si ricalcola dalla
 * somma delle righe riuscite, ed è `Support\Payments\Ledger` (piano 2) l'unico
 * che lo tocca.
 *
 * `provider`, `provider_reference` e `type` hanno un indice unico insieme: è la
 * difesa contro la notifica doppia del gateway, che arriva anche due volte per
 * lo stesso incasso, e il `type` tiene distinto il rimborso che porta il
 * riferimento dell'incasso. `order_id`, `subscription_id` e `customer_id` sono interi
 * semplici: un pagamento di un abbonamento non ha ordine e uno al banco può
 * non avere cliente.
 */
final class Payment extends Model
{
    public static string $table = 'gst_payments';
    public static string $folder = 'gestionale/payments';
    public static string $icon = 'bi bi-cash-coin';

    public const TYPES = ['payment', 'refund'];
    public const STATUSES = ['pending', 'paid', 'failed', 'cancelled'];
    public const PROVIDERS = ['stripe', 'paypal', 'nexi', 'manual'];
    /** Test e produzione non si mescolano: un incasso di prova non è denaro. */
    public const ENVIRONMENTS = ['live', 'test'];

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            ...static::sqlColumnsFromDataSchema(['code', 'amount']),
            Column::key('type')->enum(static::TYPES)->default('payment'),
            Column::key('order_id')->int()->default(0),
            Column::key('subscription_id')->int()->default(0),
            Column::key('customer_id')->int()->default(0),
            Column::key('payment_method_id')->int()->default(0),
            Column::key('payment_account_id')->int()->default(0),
            Column::key('currency')->length(3)->default('EUR'),
            Column::key('status')->enum(static::STATUSES)->default('pending'),
            Column::key('provider')->enum(static::PROVIDERS)->default('manual'),
            Column::key('provider_reference')->length(191),
            // Il metodo scelto dentro il gateway (con Stripe: card, klarna, link…).
            Column::key('provider_method')->length(40),
            Column::key('environment')->enum(static::ENVIRONMENTS)->default('live'),
            Column::key('due_date')->date(),
            Column::key('paid_at')->datetime(),
            Column::key('note')->type('TEXT'),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            // La stessa notifica del gateway non entra due volte. Il tipo fa
            // parte della chiave perché certi gateway rimandano il riferimento
            // dell'incasso anche sul rimborso, e un rimborso non è la ripetizione
            // di un incasso.
            'uni_provider_reference' => ['unique' => ['provider', 'provider_reference', 'type']],
            'ind_order' => ['index' => 'order_id'],
            'ind_due_date' => ['index' => 'due_date'],
            'ind_status' => ['index' => 'status'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->uniqueCode(Codes::PAYMENT),
            Field::key('type')->text()->sanitize(false),
            Field::key('order_id')->number()->decimals(0),
            Field::key('subscription_id')->number()->decimals(0),
            Field::key('customer_id')->number()->decimals(0),
            Field::key('payment_method_id')->number()->decimals(0),
            Field::key('payment_account_id')->number()->decimals(0),
            Field::key('amount')->number()->decimals(2),
            Field::key('currency')->text()->sanitize(false),
            Field::key('status')->text()->sanitize(false),
            Field::key('provider')->text()->sanitize(false),
            Field::key('provider_reference')->text()->sanitize(false),
            Field::key('provider_method')->text()->sanitize(false),
            Field::key('environment')->text()->sanitize(false),
            Field::key('due_date')->date(),
            Field::key('paid_at')->date(),
            Field::key('note')->text(),
        ];
    }
}
