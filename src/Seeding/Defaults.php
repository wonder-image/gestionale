<?php

namespace Wonder\Plugin\Gestionale\Seeding;

use Wonder\App\Models\Config\SocietyLocation;
use Wonder\App\Module\Contracts\ModuleDefaults;
use Wonder\App\Support\DefaultRows;
use Wonder\Plugin\Custom\Fattura\Valori\AliquoteIva;
use Wonder\Plugin\Custom\Fattura\Valori\Natura;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Locations\Location;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Models\Shipping\Carrier;
use Wonder\Plugin\Gestionale\Models\System\Feature;
use Wonder\Plugin\Gestionale\Models\System\MerchantSetting;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Models\Tax\Tax;
use Wonder\Plugin\Gestionale\Models\Tax\TaxCategory;
use Wonder\Plugin\Gestionale\Models\Tax\TaxRule;
use Wonder\Plugin\Gestionale\Support\Features\FeatureCatalog;

/**
 * Righe precaricate del gestionale, create da `forge update` in locale.
 *
 * Regola unica: si inserisce solo ciò che manca, mai si modifica ciò che c'è.
 * I nomi sono in italiano, perché finiscono davanti al commerciante. In
 * produzione queste righe non nascono qui: arrivano dal file di sync con il
 * deploy.
 */
final class Defaults implements ModuleDefaults
{
    /** Codice dell'aliquota ordinaria: ripiego, spedizione e regole puntano a lei. */
    private const ORDINARY_RATE = '22';

    /** Codice dell'unico tipo fiscale precaricato. */
    private const ORDINARY_CATEGORY = 'ordinaria';

    public static function seed(DefaultRows $rows): void
    {
        self::features($rows);
        self::taxes($rows);
        self::taxCategories($rows);
        self::taxRules($rows);
        self::settings($rows);
        self::location($rows);
        self::paymentMethods($rows);
        self::carriers($rows);
    }

    /** Una riga bloccata per ogni funzionalità del catalogo. */
    private static function features(DefaultRows $rows): void
    {
        $unlock = array_map('strval', (array) Gestionale::config('features.unlock', []));
        $features = [];

        foreach (array_keys(FeatureCatalog::all()) as $key) {
            $features[] = [
                'feature_key' => $key,
                // Il sito può far nascere sbloccate alcune funzionalità (starter),
                // mai cambiarle dopo: le righe esistenti non si toccano.
                'enabled' => in_array($key, $unlock, true) ? 'true' : 'false',
            ];
        }

        $rows->ensure(Feature::class, 'feature_key', $features);
    }

    /** Aliquote italiane visibili, operazioni a zero nascoste ma pronte. */
    private static function taxes(DefaultRows $rows): void
    {
        $taxes = [];

        foreach (AliquoteIva::Valori as $rate => $description) {
            $value = (float) $rate;
            $taxes[] = [
                'code' => rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.'),
                'name' => number_format($value, 0).'% - '.$description,
                'description' => $description,
                'invoice_description' => $description,
                'rate' => $value,
                'nature' => '',
                'visible' => 'true',
            ];
        }

        foreach (Natura::valide() as $code => $description) {
            $taxes[] = [
                'code' => strtolower(str_replace('.', '-', (string) $code)),
                'name' => $code.' - '.$description,
                'description' => $description,
                'invoice_description' => $description,
                'rate' => 0,
                'nature' => (string) $code,
                // Si accendono quando servono: un negozio normale non le usa.
                'visible' => 'false',
            ];
        }

        $rows->ensure(Tax::class, 'code', $taxes);
    }

    /**
     * Un tipo fiscale solo, ed è il predefinito: la scheda prodotto parte da
     * lì, e chi vende libri o alimentari ne aggiunge altri.
     */
    private static function taxCategories(DefaultRows $rows): void
    {
        $rows->ensure(TaxCategory::class, 'code', [[
            'code' => self::ORDINARY_CATEGORY,
            'name' => 'Aliquota ordinaria',
            'description' => 'Beni e servizi con aliquota ordinaria.',
            'position' => 1,
            'visible' => 'true',
            'is_default' => 'true',
        ]]);
    }

    /** Italia, privato e azienda, tipo ordinario: il caso di tutti i giorni. */
    private static function taxRules(DefaultRows $rows): void
    {
        $taxId = self::ordinaryTaxId();
        $categoryId = self::ordinaryCategoryId();

        if ($taxId === 0 || $categoryId === 0) {
            return;
        }

        $candidates = [
            [
                // Stesso formato che compone la pagina: {paese}-{cliente}-{tipo}.
                'code' => 'it-private-'.self::ORDINARY_CATEGORY,
                'country' => 'IT',
                'customer_type' => 'private',
                'tax_category_id' => $categoryId,
                'tax_id' => $taxId,
            ],
            [
                'code' => 'it-business-'.self::ORDINARY_CATEGORY,
                'country' => 'IT',
                'customer_type' => 'business',
                'tax_category_id' => $categoryId,
                'tax_id' => $taxId,
            ],
        ];

        $rows->ensure(TaxRule::class, 'code', self::withoutExistingRules($candidates));
    }

    /**
     * Le regole che il sito non ha già, guardando paese, tipo di cliente e
     * tipo fiscale.
     *
     * Il codice della regola è composto da quelle tre cose, ma il formato è
     * cambiato: un sito aggiornato ha le stesse regole con il codice vecchio, e
     * `ensure()` — che guarda solo il codice — proverebbe a reinserirle
     * sbattendo contro l'indice unico. Qui si controlla la chiave vera.
     *
     * @param list<array<string, mixed>> $candidates
     * @return list<array<string, mixed>>
     */
    private static function withoutExistingRules(array $candidates): array
    {
        $existing = TaxRule::find(['deleted' => 'false']);
        $existing = is_array($existing) && $existing !== []
            ? (isset($existing['id']) ? [$existing] : array_values(array_filter($existing, 'is_array')))
            : [];

        $known = [];

        foreach ($existing as $row) {
            $known[self::ruleKey($row)] = true;
        }

        return array_values(array_filter(
            $candidates,
            static fn (array $row): bool => !isset($known[self::ruleKey($row)])
        ));
    }

    /** Paese, tipo di cliente e tipo fiscale: ciò che rende unica una regola. */
    private static function ruleKey(array $row): string
    {
        return strtolower((string) ($row['country'] ?? ''))
            .'|'.strtolower((string) ($row['customer_type'] ?? ''))
            .'|'.(int) ($row['tax_category_id'] ?? 0);
    }

    /**
     * Le due righe uniche. La conferma fiscale resta vuota apposta: i Primi
     * passi devono far guardare questi valori a una persona.
     */
    private static function settings(DefaultRows $rows): void
    {
        $taxId = self::ordinaryTaxId();

        if ($taxId > 0) {
            $rows->ensureSingleton(Setting::class, [
                'invoice_provider' => '',
                'tax_regime' => 'RF01',
                'vat_collectability' => 'I',
                'transmitter_country' => 'IT',
                'transmitter_fiscal_code' => '',
                'catalog_prices_include_tax' => 'true',
                'fallback_tax_id' => $taxId,
                'shipping_tax_id' => $taxId,
                'invoice_numeration' => 'WEB',
                'stamp_duty_auto' => 'true',
                'fiscal_confirmed_at' => '',
                'developer_error_emails' => '',
            ]);
        }

        $rows->ensureSingleton(MerchantSetting::class, [
            'merchant_notification_emails' => self::societyEmail(),
        ]);
    }

    /** La sede predefinita del core diventa il primo magazzino. */
    private static function location(DefaultRows $rows): void
    {
        $location = SocietyLocation::find(['is_default' => 'true', 'deleted' => 'false'], 1);
        $id = (int) ($location['id'] ?? 0);

        if ($id === 0) {
            return;
        }

        $rows->ensure(Location::class, 'society_location_id', [[
            'code' => Location::newCode(),
            'society_location_id' => $id,
            'has_stock' => 'true',
            'is_pickup_point' => 'false',
            'is_pos' => 'false',
            'active' => 'true',
        ]]);
    }

    /**
     * Tre modi di pagare per cominciare. Stripe nasce spento: si accende
     * quando c'è la chiave, un metodo che non può incassare non si propone.
     */
    private static function paymentMethods(DefaultRows $rows): void
    {
        $rows->ensure(PaymentMethod::class, 'code', [
            [
                'code' => 'bank-transfer',
                'name' => 'Bonifico bancario',
                'provider' => 'bank_transfer',
                'sdi_code' => 'MP05',
                'timing' => 'deferred',
                'instructions' => 'Fai il bonifico indicando il numero dell\'ordine nella causale: preparerai l\'ordine all\'arrivo del denaro.',
                'applies_online' => 'true',
                'applies_office' => 'true',
                'applies_pos' => 'false',
                'active' => 'true',
                'position' => 1,
            ],
            [
                'code' => 'cash',
                'name' => 'Contanti al ritiro',
                'provider' => 'cash',
                'sdi_code' => 'MP01',
                'timing' => 'on_delivery',
                'available_for' => 'pickup',
                'instructions' => 'Paghi in contanti quando ritiri l\'ordine in sede.',
                'applies_online' => 'true',
                'applies_office' => 'true',
                'applies_pos' => 'true',
                'active' => 'true',
                'position' => 2,
            ],
            [
                'code' => 'stripe',
                'name' => 'Carta di credito',
                'provider' => 'stripe',
                'sdi_code' => 'MP08',
                'timing' => 'immediate',
                'stripe_payment_method_types' => 'card',
                'applies_online' => 'true',
                'applies_office' => 'false',
                'applies_pos' => 'false',
                'active' => 'false',
                'position' => 3,
            ],
        ]);

        self::legacyPaymentMethods();
    }

    /**
     * I metodi scritti prima che il tipo si dividesse in bonifico e contanti
     * (`manual`) e prima che la percentuale avesse il suo campo (stava in
     * `fee_value`): si riscrivono una volta e basta, perché dopo non c'è più
     * niente da riscrivere. Le scelte fatte a mano non si toccano.
     */
    private static function legacyPaymentMethods(): void
    {
        foreach ((array) sqlSelect(PaymentMethod::$table, null)->row as $row) {
            if (!is_array($row)) {
                continue;
            }

            $changes = [];

            if ((string) ($row['provider'] ?? '') === 'manual') {
                $changes['provider'] = (string) ($row['code'] ?? '') === 'cash' || (string) ($row['timing'] ?? '') === 'on_delivery'
                    ? 'cash'
                    : 'bank_transfer';
            }

            if ((string) ($row['fee_type'] ?? '') === 'percent'
                && (float) ($row['fee_percent'] ?? 0) <= 0
                && (float) ($row['fee_value'] ?? 0) > 0) {
                $changes['fee_percent'] = $row['fee_value'];
                $changes['fee_value'] = '0.00';
            }

            if ($changes !== []) {
                PaymentMethod::update($changes, (int) $row['id']);
            }
        }
    }

    /**
     * I sei corrieri più usati in Italia, col link per seguire il pacco: il
     * numero di tracking prende il posto di `{tracking}`. Sono voci come le
     * altre: si spengono, si cambiano o si tolgono dal pannello.
     */
    private static function carriers(DefaultRows $rows): void
    {
        $links = [
            ['poste-italiane', 'Poste Italiane', 'https://www.poste.it/cerca/index.html#/risultati-spedizioni/'],
            ['dhl', 'DHL', 'https://www.dhl.com/it-en/home/tracking.html?tracking-id='],
            ['gls', 'GLS', 'https://gls-group.com/IT/it/servizi-online/ricerca-spedizioni.html?match='],
            ['ups', 'UPS', 'https://www.ups.com/track?loc=it_IT&requester=QUIC&tracknum='],
            ['bartolini', 'Bartolini', 'https://services.brt.it/it/tracking?OP=N&CD='],
            ['fedex', 'FedEx', 'https://www.fedex.com/fedextrack/?action=track&trackingnumber='],
        ];
        $carriers = [];

        foreach ($links as $position => [$code, $name, $page]) {
            $carriers[] = [
                'code' => $code,
                'name' => $name,
                'tracking_url_template' => $page.'{tracking}',
                'provider' => 'manual',
                'active' => 'true',
                'position' => $position + 1,
            ];
        }

        $rows->ensure(Carrier::class, 'code', $carriers);
    }

    private static function ordinaryTaxId(): int
    {
        $tax = Tax::find(['code' => self::ORDINARY_RATE, 'deleted' => 'false'], 1);

        return (int) ($tax['id'] ?? 0);
    }

    private static function ordinaryCategoryId(): int
    {
        $category = TaxCategory::find(['code' => self::ORDINARY_CATEGORY, 'deleted' => 'false'], 1);

        return (int) ($category['id'] ?? 0);
    }

    /** L'email della società, se il sito l'ha compilata. */
    private static function societyEmail(): string
    {
        $location = SocietyLocation::find(['is_default' => 'true', 'deleted' => 'false'], 1);

        return trim((string) ($location['email'] ?? ''));
    }
}
