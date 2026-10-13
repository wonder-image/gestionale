<?php

namespace Wonder\Plugin\Gestionale\Resources\System;

use Throwable;

use Wonder\App\Resources\Support\SingletonResource;
use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\Plugin\Custom\Fattura\Valori\EsigibilitaIva;
use Wonder\Plugin\Custom\Fattura\Valori\RegimiFiscali;
use Wonder\Plugin\Gestionale\Extensions\SettingsSections;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Models\Tax\Tax;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Mail\Recipients;
use Wonder\Plugin\Gestionale\Support\Numbers;

/**
 * "Impostazioni" tecniche in Set Up: una riga sola, di `admin`.
 *
 * Quattro riquadri: Fiscale (regime, esigibilità, prezzi, aliquote di
 * ripiego), Documenti (sezionale e bollo), Vendite ed Email (chi riceve le
 * email degli ordini e chi quelle degli errori tecnici). Fra Vendite ed Email
 * stanno quelli dei moduli accesi (`SettingsSections`). Il salvataggio segna
 * `fiscal_confirmed_at`: i Primi passi hanno bisogno di sapere che una persona
 * ha guardato i valori precaricati.
 *
 * In produzione la pagina è in sola lettura, tranne le email degli ordini: non
 * arrivano dal deploy e si cambiano dove si vende.
 */
final class SettingResource extends SingletonResource
{
    public static string $model = Setting::class;

    public static function path(): string
    {
        return 'app/gestionale/impostazioni';
    }

    public static function icon(): string
    {
        return 'bi-sliders';
    }

    public static function titleLabel(): string
    {
        return 'Impostazioni';
    }

    public static function labelSchema(): array
    {
        $labels = [
            'tax_regime' => 'Regime fiscale',
            'vat_collectability' => 'Esigibilità IVA',
            'transmitter_country' => 'Paese del trasmittente',
            'transmitter_fiscal_code' => 'Codice fiscale del trasmittente',
            'catalog_prices_include_tax' => 'Prezzi del catalogo',
            'fallback_tax_id' => 'Aliquota di ripiego',
            'shipping_tax_id' => 'Aliquota della spedizione',
            'invoice_provider' => 'Fatturazione elettronica',
            'invoice_numeration' => 'Sezionale delle fatture',
            'stamp_duty_auto' => 'Bollo automatico',
            'fiscal_confirmed_at' => 'Confermate il',
            'merchant_notification_emails' => 'Email per gli ordini',
            'developer_error_emails' => 'Email per gli errori tecnici',
            'low_stock_emails' => 'Email per la scorta minima',
            'order_reservation_minutes' => 'Minuti di prenotazione',
            'order_payment_wait_days' => 'Giorni di attesa del pagamento',
            'shipping_sync' => 'Spedizioni col deploy',
            'batch_expiry_days' => 'Giorni prima della scadenza',
            'payment_due_days' => 'Giorni di preavviso per le scadenze',
            'quote_validity_days' => 'Validità dei preventivi (giorni)',
            'subscription_unpaid_days' => 'Giorni prima di «non pagato»',
        ];

        foreach (SettingsSections::all() as $section) {
            $labels += $section->labels();
        }

        return $labels;
    }

    public static function formSchema(): array
    {
        $fields = [
            FormField::key('tax_regime')->select(RegimiFiscali::Valori)->value('RF01')->label('Regime fiscale')->required(),
            FormField::key('vat_collectability')->select(EsigibilitaIva::Valori)->value('I')->label('Esigibilità IVA')->required(),
            FormField::key('transmitter_country')->country()->value('IT')->label('Paese del trasmittente'),
            FormField::key('transmitter_fiscal_code')->text()->label('Codice fiscale del trasmittente'),
            FormField::key('catalog_prices_include_tax')
                ->select(['true' => 'IVA inclusa', 'false' => 'IVA esclusa'])
                ->value('true')
                ->label('Prezzi del catalogo')
                ->required(),
            FormField::key('fallback_tax_id')->select(static::taxes())->label('Aliquota di ripiego')->required(),
            FormField::key('shipping_tax_id')->select(static::taxes())->label('Aliquota della spedizione')->required(),
            FormField::key('invoice_provider')
                ->select(['' => 'Nessuna', 'fatture-in-cloud' => 'Fatture in Cloud'])
                ->label('Fatturazione elettronica'),
            FormField::key('invoice_numeration')->text()->value('WEB')->label('Sezionale delle fatture'),
            FormField::key('stamp_duty_auto')
                ->select(['true' => 'Sì', 'false' => 'No'])
                ->value('true')
                ->label('Bollo automatico'),
            FormField::key('merchant_notification_emails')->text()->label('Email per gli ordini'),
            FormField::key('developer_error_emails')->text()->label('Email per gli errori tecnici'),
            ...(Gestionale::feature('low_stock_alerts')
                ? [FormField::key('low_stock_emails')->text()->label('Email per la scorta minima')]
                : []),
            FormField::key('order_reservation_minutes')->number()->decimal(0)->value(30)->label('Minuti di prenotazione')->required(),
            FormField::key('order_payment_wait_days')->number()->decimal(0)->value(7)->label('Giorni di attesa del pagamento')->required(),
            FormField::key('shipping_sync')
                ->select(['true' => 'Sì', 'false' => 'No'])
                ->value('true')
                ->label('Spedizioni col deploy'),
            ...(Gestionale::feature('batch_tracking')
                ? [FormField::key('batch_expiry_days')->number()->decimal(0)->value(30)->label('Giorni prima della scadenza')]
                : []),
            ...(Gestionale::feature('office_sales')
                ? [FormField::key('payment_due_days')->number()->decimal(0)->value(7)->label('Giorni di preavviso per le scadenze')]
                : []),
            ...(Gestionale::feature('quotes')
                ? [FormField::key('quote_validity_days')->number()->decimal(0)->value(30)->label('Validità dei preventivi (giorni)')]
                : []),
            ...(Gestionale::feature('subscriptions')
                ? [FormField::key('subscription_unpaid_days')->number()->decimal(0)->value(15)->label('Giorni prima di «non pagato»')]
                : []),
        ];

        foreach (SettingsSections::all() as $section) {
            array_push($fields, ...$section->fields());
        }

        return $fields;
    }

    public static function formLayoutSchema(): ?Form
    {
        $input = static fn (string $key): object => static::getInput($key);
        $modules = array_map(static fn ($section) => $section->card($input), SettingsSections::all());

        return (new Form)->components([
            (new Container)->components([
                (new Card)->components([
                    SectionTitle::make('Fiscale')
                        ->tooltip('Valori da confermare con il commercialista: il gestionale li usa per ogni documento.')
                        ->columnSpan(12),
                    static::getInput('tax_regime')->columnSpan(6),
                    static::getInput('vat_collectability')->columnSpan(6),
                    static::getInput('catalog_prices_include_tax')->columnSpan(4),
                    static::getInput('fallback_tax_id')->columnSpan(4),
                    static::getInput('shipping_tax_id')->columnSpan(4),
                    static::getInput('transmitter_country')->columnSpan(6),
                    static::getInput('transmitter_fiscal_code')->columnSpan(6),
                ])->columns(12)->columnSpan(12),

                (new Card)->components([
                    SectionTitle::make('Documenti')->columnSpan(12),
                    static::getInput('invoice_provider')->columnSpan(4),
                    static::getInput('invoice_numeration')->columnSpan(4),
                    static::getInput('stamp_duty_auto')->columnSpan(4),
                ])->columns(12)->columnSpan(12),

                (new Card)->components([
                    SectionTitle::make('Vendite')
                        ->tooltip('Quanto resta impegnata la merce di un ordine non ancora pagato, e per quanti giorni si aspetta il bonifico prima di annullare. «Spedizioni col deploy»: con Sì corrieri, metodi, zone e listini partono da qui e in produzione si leggono soltanto; con No li gestisce il commerciante in produzione.')
                        ->columnSpan(12),
                    static::getInput('order_reservation_minutes')->columnSpan(4),
                    static::getInput('order_payment_wait_days')->columnSpan(4),
                    static::getInput('shipping_sync')->columnSpan(4),
                    ...static::salesExtra(),
                ])->columns(12)->columnSpan(12),

                ...static::stockCards(),

                ...$modules,

                (new Card)->components(static::emailComponents())->columns(12)->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ]);
    }

    public static function pageSchema(): PageSchema
    {
        $schema = parent::pageSchema()->titles(['edit' => 'Impostazioni']);
        $url = Gestionale::docsUrl('impostazioni/tecniche');

        return $url === '' ? $schema : $schema->docs($url);
    }

    /**
     * Con «Vendita in ufficio» la card Vendite ha i giorni dello scadenzario
     * (G9): quanto prima una rata si segna «in scadenza».
     *
     * @return list<object>
     */
    private static function salesExtra(): array
    {
        $out = [];

        if (Gestionale::feature('office_sales')) {
            $out[] = SectionTitle::make('Scadenzario')
                ->tooltip('Quanti giorni prima della scadenza una rata si segna in giallo e compare nel riquadro «Pagamenti in scadenza» della home. Le rate già scadute sono sempre in rosso.')
                ->columnSpan(12);
            $out[] = static::getInput('payment_due_days')->columnSpan(4);
        }

        if (Gestionale::feature('quotes')) {
            $out[] = SectionTitle::make('Preventivi')
                ->tooltip('Quanti giorni vale un preventivo nuovo: la data si cambia su ogni preventivo. Inviato e non accettato entro quella data, il preventivo diventa scaduto.')
                ->columnSpan(12);
            $out[] = static::getInput('quote_validity_days')->columnSpan(4);
        }

        if (Gestionale::feature('subscriptions')) {
            $out[] = SectionTitle::make('Abbonamenti da ufficio')
                ->tooltip('Quanti giorni dopo la scadenza di una rata non pagata l\'abbonamento passa da «da pagare» a «non pagato». L\'annullamento lo decidi tu dalla scheda dell\'abbonamento.')
                ->columnSpan(12);
            $out[] = static::getInput('subscription_unpaid_days')->columnSpan(4);
        }

        return $out;
    }

    /**
     * La card Magazzino: solo con «Lotti e scadenze» (G3.20).
     *
     * @return list<Card>
     */
    private static function stockCards(): array
    {
        if (!Gestionale::feature('batch_tracking')) {
            return [];
        }

        return [
            (new Card)->components([
                SectionTitle::make('Magazzino')
                    ->tooltip('Quanti giorni prima della scadenza un lotto si segna in giallo e compare nel riquadro «Lotti in scadenza» della home. I lotti già scaduti sono sempre in rosso.')
                    ->columnSpan(12),
                static::getInput('batch_expiry_days')->columnSpan(4),
            ])->columns(12)->columnSpan(12),
        ];
    }

    /**
     * La card Email: con gli avvisi di scorta accesi c'è un terzo campo, e i
     * tre stanno su una riga.
     */
    private static function emailComponents(): array
    {
        $scorta = Gestionale::feature('low_stock_alerts');
        $span = $scorta ? 4 : 6;
        $tooltip = 'Più indirizzi separati da virgola. «Per gli ordini» riceve l\'avviso di ogni ordine nuovo e di ogni ordine annullato: di solito il negozio. «Per gli errori tecnici» riceve i guasti dei servizi esterni (pagamenti, corrieri, fatture elettroniche) e quelli del codice: chi sviluppa il sito.';

        if ($scorta) {
            $tooltip .= ' «Per la scorta minima» riceve l\'elenco dei prodotti scesi sotto la scorta: vuoto, l\'email non parte e gli avvisi aspettano.';
        }

        $components = [
            SectionTitle::make('Email')->tooltip($tooltip)->columnSpan(12),
            static::getInput('merchant_notification_emails')->columnSpan($span),
            static::getInput('developer_error_emails')->columnSpan($span),
        ];

        if ($scorta) {
            $components[] = static::getInput('low_stock_emails')->columnSpan($span);
        }

        return $components;
    }

    /** Le email degli ordini e della scorta restano di chi vende: in produzione si cambiano lo stesso. */
    public static function editableWhenReadonly(): array
    {
        return ['merchant_notification_emails', 'low_stock_emails', 'batch_expiry_days', 'payment_due_days', 'quote_validity_days', 'subscription_unpaid_days'];
    }

    /** I giorni di validità di un preventivo: da 1 a 365, o un rifiuto; vuoto torna a 30. */
    public static function validityDays(mixed $value): int
    {
        if ($value === null || is_string($value) && trim($value) === '') {
            return 30;
        }

        $number = Numbers::fromForm($value);

        if ($number === null || !is_numeric($number) || (float) $number !== floor((float) $number) || (float) $number < 1 || (float) $number > 365) {
            throw UserError::make('settings.quote_validity_days_invalid');
        }

        return (int) $number;
    }

    /** I giorni dello scadenzario: un intero da 0 a 365, o un rifiuto; vuoto torna a 7. */
    public static function dueDays(mixed $value): int
    {
        if ($value === null || is_string($value) && trim($value) === '') {
            return 7;
        }

        $number = Numbers::fromForm($value);

        if ($number === null || !is_numeric($number) || (float) $number !== floor((float) $number) || (float) $number < 0 || (float) $number > 365) {
            throw UserError::make('settings.payment_due_days_invalid');
        }

        return (int) $number;
    }

    /** I giorni prima di «non pagato» (G10): un intero da 1 a 365, o un rifiuto; vuoto vale 15. */
    public static function unpaidDays(mixed $value): int
    {
        if ($value === null || is_string($value) && trim($value) === '') {
            return 15;
        }

        $number = Numbers::fromForm($value);

        if ($number === null || !is_numeric($number) || (float) $number !== floor((float) $number) || (float) $number < 1 || (float) $number > 365) {
            throw UserError::make('settings.subscription_unpaid_days_invalid');
        }

        return (int) $number;
    }

    /** I giorni del riquadro dei lotti: un intero da 0 a 3650, o un rifiuto. */
    public static function expiryDays(mixed $value): int
    {
        if (is_string($value) && trim($value) === '' || $value === null) {
            return 30;
        }

        $number = Numbers::fromForm($value);

        if ($number === null || !is_numeric($number) || (float) $number !== floor((float) $number) || (float) $number < 0 || (float) $number > 3650) {
            throw UserError::make('settings.batch_expiry_days_invalid');
        }

        return (int) $number;
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)
            ->backend(['list', 'edit', 'update'], ['admin']);
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->inSection('set-up')
            ->inGroup('gestionale')
            ->title('Impostazioni')
            ->order(10)
            ->authority(['admin']);
    }

    /**
     * La prima conferma di una persona vale per i Primi passi e non si
     * riscrive. Gli indirizzi si salvano puliti: uno storto si rifiuta adesso,
     * nominandolo, invece di scoprirlo il giorno che l'email non arriva. I
     * riquadri dei moduli puliscono i loro valori.
     */
    public static function mutateRequestValues(
        array $values,
        string $action,
        string $context = 'backend',
        ?array $oldValues = null
    ): array {
        // Con gli avvisi bloccati i destinatari non si scrivono.
        if (!Gestionale::feature('low_stock_alerts')) {
            unset($values['low_stock_emails']);
        }

        // G3, lotti: da 0 a 3650 giorni; vuoto torna ai 30 di partenza.
        if (!Gestionale::feature('batch_tracking')) {
            unset($values['batch_expiry_days']);
        } elseif (array_key_exists('batch_expiry_days', $values)) {
            $values['batch_expiry_days'] = static::expiryDays($values['batch_expiry_days']);
        }

        // G9, preventivi: da 1 a 365 giorni; vuoto torna ai 30 di partenza.
        if (!Gestionale::feature('quotes')) {
            unset($values['quote_validity_days']);
        } elseif (array_key_exists('quote_validity_days', $values)) {
            $values['quote_validity_days'] = static::validityDays($values['quote_validity_days']);
        }

        // G10, abbonamenti da ufficio: da 1 a 365 giorni; vuoto torna ai 15 di partenza.
        if (!Gestionale::feature('subscriptions')) {
            unset($values['subscription_unpaid_days']);
        } elseif (array_key_exists('subscription_unpaid_days', $values)) {
            $values['subscription_unpaid_days'] = static::unpaidDays($values['subscription_unpaid_days']);
        }

        // G9, scadenzario: da 0 a 365 giorni; vuoto torna ai 7 di partenza.
        if (!Gestionale::feature('office_sales')) {
            unset($values['payment_due_days']);
        } elseif (array_key_exists('payment_due_days', $values)) {
            $values['payment_due_days'] = static::dueDays($values['payment_due_days']);
        }

        foreach (['merchant_notification_emails', 'developer_error_emails', 'low_stock_emails'] as $key) {
            if (!array_key_exists($key, $values)) {
                continue;
            }

            $parsed = Recipients::parse((string) $values[$key]);

            if ($parsed['invalid'] !== []) {
                throw UserError::make('settings.email_invalid', ['email' => $parsed['invalid'][0]]);
            }

            $values[$key] = Recipients::join($parsed['valid']);
        }

        foreach (SettingsSections::all() as $section) {
            $values = $section->mutate($values);
        }

        $confermata = trim((string) ($oldValues['fiscal_confirmed_at'] ?? ''));

        $values['fiscal_confirmed_at'] = $confermata !== '' && $confermata !== '0000-00-00 00:00:00'
            ? $confermata
            : date('Y-m-d H:i:s');

        return $values;
    }

    /** @return array<string, string> */
    private static function taxes(): array
    {
        try {
            $rows = Tax::find(['deleted' => 'false'], null, 'rate', 'DESC');
        } catch (Throwable) {
            return [];
        }

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $rows = isset($rows['id']) ? [$rows] : $rows;
        $options = [];

        foreach ($rows as $row) {
            if (is_array($row) && isset($row['id'])) {
                $options[(string) $row['id']] = (string) ($row['name'] ?? $row['code'] ?? $row['id']);
            }
        }

        return $options;
    }
}
