<?php

namespace Wonder\Plugin\Gestionale\Models\System;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Extensions\SettingsSections;
use Wonder\Plugin\Gestionale\Models\Tax\Tax;
use Wonder\Plugin\Gestionale\Support\RequestMemo;
use Wonder\Sql\TableSchema as Column;

/**
 * Impostazioni tecniche e fiscali: una riga sola, scritta da `admin` in locale
 * e portata in produzione dal deploy.
 *
 * Fanno eccezione `merchant_notification_emails` e `low_stock_emails`, chi
 * riceve le email degli ordini e degli avvisi di scorta: non viaggiano col
 * deploy, perché ogni ambiente ha i suoi destinatari, e si cambiano anche in
 * produzione. Lo stesso vale per `stripe_methods_cache`, la lettura dei metodi
 * del conto Stripe: è di quell'ambiente e quel conto, e per `batch_expiry_days`,
 * i giorni di preavviso dei lotti in scadenza scelti dal commerciante, e per
 * `payment_due_days` e `quote_validity_days`, quelli delle rate in scadenza e
 * della validità dei preventivi e i dati di trasporto predefiniti dei DDT
 * (G9), e per `subscription_unpaid_days`, dopo quanti giorni un abbonamento
 * da ufficio con una rata scaduta è non pagato (G10), e per
 * `subscriptions_per_customer`, quanti abbonamenti in corso può avere un
 * cliente (G10b, 0 = senza limite), e per `stock_prices_include_tax`, se i
 * costi dei carichi si scrivono con l'IVA o senza (si parte senza).
 *
 * Qui stanno le scelte che si fanno una volta con il commercialista o in fase
 * di installazione. I moduli accesi aggiungono le loro coi riquadri di
 * `SettingsSections`.
 */
final class Setting extends Model
{
    public static string $table = 'gst_settings';
    public static string $folder = 'gestionale/settings';
    public static string $icon = 'bi bi-sliders';

    public static function syncSchema(): ?SyncSchema
    {
        return SyncSchema::singleton()->localOnly()->exclude(['merchant_notification_emails', 'low_stock_emails', 'stripe_methods_cache', 'batch_expiry_days', 'payment_due_days', 'quote_validity_days', 'delivery_transport_by', 'delivery_carrier_id', 'delivery_goods_appearance', 'delivery_freight_terms', 'subscription_unpaid_days', 'subscriptions_per_customer', 'stock_prices_include_tax']);
    }

    public static function tableSchema(): array
    {
        $columns = [
            Column::key('invoice_provider')->length(50),
            Column::key('tax_regime')->length(10)->default('RF01'),
            Column::key('vat_collectability')->length(5)->default('I'),
            Column::key('transmitter_country')->length(2)->default('IT'),
            Column::key('transmitter_fiscal_code')->length(50),
            Column::key('catalog_prices_include_tax')->enum(['true', 'false'])->default('true'),
            Column::key('fallback_tax_id')->int()->foreign(Tax::$table),
            Column::key('shipping_tax_id')->int()->foreign(Tax::$table),
            Column::key('invoice_numeration')->length(20)->default('WEB'),
            Column::key('stamp_duty_auto')->enum(['true', 'false'])->default('true'),
            // Vendita (5.2): quanto resta impegnata la merce di un ordine non
            // ancora pagato e quanti giorni si aspetta il bonifico.
            Column::key('order_reservation_minutes')->int()->default(30),
            Column::key('order_payment_wait_days')->int()->default(7),
            // Corrieri, metodi, zone e listini viaggiano col deploy (ShippingSync).
            Column::key('shipping_sync')->enum(['true', 'false'])->default('true'),
            Column::key('fiscal_confirmed_at')->datetime(),
            Column::key('developer_error_emails')->type('TEXT'),
            Column::key('merchant_notification_emails')->type('TEXT'),
            Column::key('low_stock_emails')->type('TEXT'),
            // Tipi di pagamento accesi nel conto Stripe, riletti ogni dieci minuti (§11b).
            Column::key('stripe_methods_cache')->type('TEXT'),
            // G3: i giorni del riquadro dei lotti in scadenza, del commerciante.
            Column::key('batch_expiry_days')->int()->default(30),
            // G3, Acquisti: come si scrivono i costi nei carichi nuovi; si salvano sempre senza IVA.
            Column::key('stock_prices_include_tax')->enum(['true', 'false'])->default('false'),
            // G9: i giorni dello scadenzario «in scadenza», del commerciante.
            Column::key('payment_due_days')->int()->default(7),
            // G9: quanti giorni vale un preventivo appena fatto.
            Column::key('quote_validity_days')->int()->default(30),
            // G9: i dati di trasporto che un DDT nuovo propone.
            Column::key('delivery_transport_by')->enum(['sender', 'recipient', 'carrier'])->default('sender'),
            Column::key('delivery_carrier_id')->int()->default(0),
            Column::key('delivery_goods_appearance')->length(100),
            Column::key('delivery_freight_terms')->enum(['prepaid', 'collect'])->default('prepaid'),
            Column::key('subscription_unpaid_days')->int()->default(15),
            Column::key('subscriptions_per_customer')->int()->default(1),
        ];

        foreach (SettingsSections::all() as $section) {
            array_push($columns, ...$section->columns());
        }

        return $columns;
    }

    public static function dataSchema(): array
    {
        $fields = [
            Field::key('invoice_provider')->text()->sanitize(false),
            Field::key('tax_regime')->text()->sanitize(false),
            Field::key('vat_collectability')->text()->sanitize(false),
            Field::key('transmitter_country')->text()->sanitize(false),
            Field::key('transmitter_fiscal_code')->text()->sanitize(false),
            Field::key('catalog_prices_include_tax')->text()->sanitize(false),
            Field::key('fallback_tax_id')->number()->decimals(0),
            Field::key('shipping_tax_id')->number()->decimals(0),
            Field::key('invoice_numeration')->text()->sanitize(false),
            Field::key('stamp_duty_auto')->text()->sanitize(false),
            Field::key('order_reservation_minutes')->number()->decimals(0),
            Field::key('order_payment_wait_days')->number()->decimals(0),
            Field::key('shipping_sync')->text()->sanitize(false),
            Field::key('fiscal_confirmed_at')->date(),
            Field::key('developer_error_emails')->text(),
            Field::key('merchant_notification_emails')->text(),
            Field::key('low_stock_emails')->text(),
            Field::key('stripe_methods_cache')->text()->sanitize(false),
            Field::key('batch_expiry_days')->number()->decimals(0),
            Field::key('stock_prices_include_tax')->text()->sanitize(false),
            Field::key('payment_due_days')->number()->decimals(0),
            Field::key('quote_validity_days')->number()->decimals(0),
            Field::key('delivery_transport_by')->text()->sanitize(false),
            Field::key('delivery_carrier_id')->number()->decimals(0),
            Field::key('delivery_goods_appearance')->text(),
            Field::key('delivery_freight_terms')->text()->sanitize(false),
            Field::key('subscription_unpaid_days')->number()->decimals(0),
            Field::key('subscriptions_per_customer')->number()->decimals(0),
        ];

        foreach (SettingsSections::all() as $section) {
            array_push($fields, ...$section->data());
        }

        return $fields;
    }

    /**
     * La riga unica delle impostazioni, array vuoto se non c'è ancora.
     * Letta una volta per richiesta: chi la scrive passa da qui o chiama forget().
     */
    public static function current(): array
    {
        return RequestMemo::remember('settings', 'current', static fn (): array => static::fresh());
    }

    /** La riga letta adesso dal database, senza la copia della richiesta. */
    public static function fresh(): array
    {
        $row = static::find(['id' => 1], 1);

        return is_array($row) ? $row : [];
    }

    public static function forget(): void
    {
        RequestMemo::forget('settings');
    }

    public static function create(array $values): object
    {
        $result = parent::create($values);
        static::forget();

        return $result;
    }

    public static function update(array $values, int|string $id): object
    {
        $result = parent::update($values, $id);
        static::forget();

        return $result;
    }

    public static function delete(int|string $id): object
    {
        $result = parent::delete($id);
        static::forget();

        return $result;
    }
}
