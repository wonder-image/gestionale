<?php

namespace Wonder\Plugin\Gestionale\Models\Payments;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Codes;
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
 * `icons` è il CSV delle icone (`ICONS`); `none` vuol dire nessuna, vuoto vuol
 * dire mai deciso.
 *
 * `provider` è il tipo: bonifico, contanti, o il gateway che incassa (Stripe,
 * PayPal, Nexi). Bonifico e contanti si incassano a mano: sul pagamento
 * registrato il fornitore resta `manual` (`ledgerProvider()`). `manual` non si
 * offre più nel form, ma resta nella colonna per le righe scritte prima che il
 * tipo si dividesse in due: `forge update` le riscrive.
 *
 * Il codice lo genera il framework (`pme_k3x9d2a`); i tre metodi di serie
 * tengono il loro codice parlante (`bank-transfer`, `cash`, `stripe`), che il
 * sito usa per ritrovarli. Configurazione di `admin`, portata in produzione dal
 * deploy.
 */
final class PaymentMethod extends Model
{
    public static string $table = 'gst_payment_methods';
    public static string $folder = 'gestionale/payments';
    public static string $icon = 'bi bi-credit-card';

    public const FEE_TYPES = ['none', 'amount', 'percent', 'amount_percent'];
    public const AVAILABLE_FOR = ['all', 'shipping', 'pickup'];
    public const PROVIDERS = ['bank_transfer', 'cash', 'stripe', 'paypal', 'nexi'];
    /** I tipi che si incassano a mano, senza gateway. */
    public const MANUAL_PROVIDERS = ['bank_transfer', 'cash'];
    public const TIMINGS = ['immediate', 'deferred', 'on_delivery'];

    /** Le icone che si possono mostrare accanto al metodo: file `payment-icons/<chiave>.svg` dell'ecommerce. */
    public const ICONS = [
        'visa' => 'Visa',
        'master' => 'Mastercard',
        'maestro' => 'Maestro',
        'american_express' => 'American Express',
        'diners_club' => 'Diners Club',
        'discover' => 'Discover',
        'jcb' => 'JCB',
        'unionpay' => 'UnionPay',
        'paypal' => 'PayPal',
        'google_pay' => 'Google Pay',
        'apple_pay' => 'Apple Pay',
        'klarna' => 'Klarna',
        'amazon_pay' => 'Amazon Pay',
        'revolut_pay' => 'Revolut Pay',
        'satispay' => 'Satispay',
        'scalapay' => 'Scalapay',
        'afterpay_clearpay' => 'Clearpay',
        'affirm' => 'Affirm',
        'bancontact' => 'Bancontact',
        'blik' => 'BLIK',
        'eps' => 'EPS',
        'giropay' => 'Giropay',
        'ideal' => 'iDEAL',
        'p24' => 'Przelewy24',
        'twint' => 'TWINT',
        'mobilepay' => 'MobilePay',
        'alipay' => 'Alipay',
        'wechat_pay' => 'WeChat Pay',
        'sepa_debit' => 'Addebito SEPA',
        'genericbank' => 'Bonifico',
        'cash' => 'Contanti',
    ];

    /** @return list<string> le icone con cui nasce un metodo di quel tipo */
    public static function defaultIcons(string $provider): array
    {
        return match ($provider) {
            'stripe' => ['visa', 'master', 'maestro', 'american_express', 'google_pay', 'apple_pay'],
            'paypal' => ['paypal'],
            'nexi' => ['visa', 'master', 'maestro'],
            'bank_transfer' => ['genericbank'],
            'cash' => ['cash'],
            default => [],
        };
    }

    /** @return list<string> le chiavi note del CSV salvato, senza doppioni */
    public static function iconsOf(string $csv): array
    {
        $keys = array_map('trim', explode(',', $csv));

        return array_values(array_unique(array_filter($keys, static fn (string $key): bool => isset(self::ICONS[$key]))));
    }

    /** Il nome del metodo da mostrare: il tipo scelto in Stripe (Klarna, PayPal…) se non è la carta, altrimenti il nome del metodo. */
    public static function choiceLabel(string $providerMethod, string $name): string
    {
        return $providerMethod !== 'card' && isset(self::ICONS[$providerMethod]) ? self::ICONS[$providerMethod] : $name;
    }

    /** Il fornitore da scrivere sul pagamento: a mano per bonifico e contanti, il gateway negli altri casi. */
    public static function ledgerProvider(string $provider): string
    {
        return $provider === '' || $provider === 'manual' || in_array($provider, static::MANUAL_PROVIDERS, true)
            ? 'manual'
            : $provider;
    }

    public static function syncSchema(): ?SyncSchema
    {
        return SyncSchema::multiRow()->keepIds()->localOnly();
    }

    public static function tableSchema(): array
    {
        return [
            ...static::sqlColumnsFromDataSchema(['code', 'fee_value', 'fee_percent']),
            Column::key('name'),
            Column::key('sdi_code')->length(10),
            Column::key('provider')->enum([...static::PROVIDERS, 'manual'])->default('bank_transfer'),
            // Quando arriva il denaro: subito (carta), fra giorni (bonifico),
            // alla consegna (contrassegno, ritiro). Decide quanto resta
            // impegnata la merce e se l'ordine si conferma senza incasso.
            Column::key('timing')->enum(static::TIMINGS)->default('immediate'),
            // Zero vuol dire "nessun conto": niente chiave esterna.
            Column::key('payment_account_id')->int()->default(0),
            Column::key('fee_type')->enum(static::FEE_TYPES)->default('none'),
            Column::key('available_for')->enum(static::AVAILABLE_FOR)->default('all'),
            Column::key('applies_online')->enum(['true', 'false'])->default('true'),
            Column::key('applies_office')->enum(['true', 'false'])->default('true'),
            Column::key('applies_pos')->enum(['true', 'false'])->default('false'),
            Column::key('instructions')->type('TEXT'),
            Column::key('icons')->type('TEXT'),
            Column::key('stripe_payment_method_types')->length(255),
            Column::key('active')->enum(['true', 'false'])->default('true'),
            Column::key('position')->int(),
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->uniqueCode(Codes::PAYMENT_METHOD),
            Field::key('name')->text()->sanitizeFirst(),
            Field::key('sdi_code')->text()->sanitize(false),
            Field::key('provider')->text()->sanitize(false),
            Field::key('timing')->text()->sanitize(false),
            Field::key('payment_account_id')->number()->decimals(0),
            Field::key('fee_type')->text()->sanitize(false),
            // Importo fisso in euro, e percentuale sull'importo dei prodotti.
            Field::key('fee_value')->number()->decimals(2),
            Field::key('fee_percent')->number()->decimals(2),
            Field::key('available_for')->text()->sanitize(false),
            Field::key('applies_online')->text()->sanitize(false),
            Field::key('applies_office')->text()->sanitize(false),
            Field::key('applies_pos')->text()->sanitize(false),
            Field::key('instructions')->text(),
            Field::key('icons')->text()->sanitize(false),
            Field::key('stripe_payment_method_types')->text()->sanitize(false),
            Field::key('active')->text()->sanitize(false),
            Field::key('position')->number()->decimals(0),
        ];
    }
}
